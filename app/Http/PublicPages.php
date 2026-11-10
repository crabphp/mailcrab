<?php

declare(strict_types=1);

namespace Mailcrab\Http;

use Crab\Contracts\Http\Request;
use Crab\Http\HttpException;
use Crab\Http\Response;
use Mailcrab\Audience;
use Mailcrab\Feedback;
use Mailcrab\Renderer;
use Mailcrab\Store;

final readonly class PublicPages
{
    public function __construct(
        private Store $store,
        private Audience $audience,
        private Feedback $feedback,
    ) {}

    private function page(string $title, string $body): Response
    {
        return Response::html(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
            . Renderer::escape($title)
            . '</title><link rel="stylesheet" href="/signup.css"></head><body><main><a class="brand" href="/">Mailcrab /</a><h1>'
            . Renderer::escape($title)
            . '</h1>'
            . $body
            . '</main></body></html>',
        );
    }

    public function subscribe(string $slug): Response
    {
        $workspace = $this->store->db->table('workspaces')->where('slug', $slug)->first() ?? throw new HttpException(
            404,
            'Newsletter not found.',
        );
        return $this->page(
            $workspace['name'],
            '<p>Good emails. Nothing extra.</p><form method="post"><label>Your name<input name="name" maxlength="120" autocomplete="name"></label><label>Email address<input name="email" type="email" maxlength="254" autocomplete="email" required></label><label class="trap">Website<input name="website" tabindex="-1" autocomplete="off"></label><label class="check"><input type="checkbox" name="consent" value="yes" required> '
            . Renderer::escape(Audience::CONSENT)
            . '</label><button>Subscribe</button></form><p class="muted">We will email a confirmation link before subscribing you.</p>',
        );
    }

    public function signup(Request $request, string $slug): Response
    {
        $input = $request->getParsedBody();
        if (!is_array($input)) {
            throw new HttpException(422, 'Form required.');
        }
        if (($input['website'] ?? '') === '') {
            $this->audience->signup($slug, $input);
        }
        return $this->page(
            'Check your inbox',
            '<p>If this address is eligible, a confirmation email will arrive. You are not subscribed until you confirm.</p>',
        );
    }

    public function confirmation(#[\SensitiveParameter] string $token): Response
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new HttpException(404, 'Confirmation not found.');
        }
        return $this->page(
            'Confirm your email',
            '<p>Confirm the newsletter signup or sender verification you requested.</p><form method="post"><button>Confirm email</button></form><p class="muted">Did not request this? Close this page.</p>',
        );
    }

    public function confirm(#[\SensitiveParameter] string $token): Response
    {
        return $this->page('Email confirmation', '<p>' . Renderer::escape($this->audience->confirm($token)) . '</p>');
    }

    public function unsubscribe(#[\SensitiveParameter] string $token): Response
    {
        return $this->page(
            'Unsubscribe',
            '<p>Stop receiving this workspace’s newsletters. No login required.</p><form method="post"><button>Unsubscribe me</button></form>',
        );
    }

    public function unsubscribePost(#[\SensitiveParameter] string $token): Response
    {
        $this->feedback->unsubscribe($token);
        return $this->page(
            'You are unsubscribed',
            '<p>If this subscription exists, it has been suppressed. You will not receive further newsletters from this workspace.</p>',
        );
    }

    public function click(#[\SensitiveParameter] string $token): Response
    {
        return Response::redirect($this->feedback->click($token));
    }

    public function feedback(Request $request): array
    {
        $this->feedback->fake(
            (string) $request->getBody(),
            $request->getHeaderLine('X-Mailcrab-Timestamp'),
            $request->getHeaderLine('X-Mailcrab-Signature'),
        );
        return ['ok' => true];
    }

    public function ready(): array
    {
        $this->store->db->first('SELECT 1 AS ready');
        Store::key();
        if (config('mailcrab.provider') !== 'fake') {
            throw new HttpException(503, 'Only fake delivery is enabled.');
        }
        return [
            'ready' => true,
            'delivery_provider' => 'workspace-routed',
            'real_sending' =>
                config('ses.live_enabled') === true
                    && $this->store->db->table('delivery_settings')->whereNotNull('live_authorized_at')->exists(),
        ];
    }

    public function dashboard(): Response
    {
        $path = dirname(__DIR__, 2) . '/public/index.html';
        if (!is_file($path)) {
            return Response::text(
                'Build the dashboard: npm --prefix dashboard ci && npm --prefix dashboard run build',
                503,
            );
        }
        return Response::html(file_get_contents($path));
    }
}
