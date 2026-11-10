<?php

declare(strict_types=1);

namespace Mailcrab\Http;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Http\Request;
use Crab\Http\Cookie;
use Crab\Http\HttpException;
use Crab\Http\Response;
use Mailcrab\Audience;
use Mailcrab\Auth;
use Mailcrab\Campaigns;
use Mailcrab\Outbox;
use Mailcrab\Renderer;
use Mailcrab\Store;

final readonly class Api
{
    public function __construct(
        private Store $store,
        private Auth $auth,
        private Audience $audience,
        private Campaigns $campaigns,
        private Outbox $outbox,
        private Renderer $renderer,
        private \Mailcrab\Delivery\DeliveryMode $mode,
    ) {}

    private function input(Request $request): array
    {
        $input = $request->getParsedBody();
        if (!is_array($input)) {
            throw new HttpException(422, 'A JSON object is required.');
        }
        return $input;
    }

    public function login(Request $request): Response
    {
        return $this->auth->login($this->input($request));
    }

    public function me(Request $request): array
    {
        $session = $request->getAttribute('session');
        $user = $this->store
            ->db
            ->table('users')
            ->select('id', 'email')
            ->where('id', $session['user_id'])
            ->first();
        $workspaces = $this->store
            ->db
            ->table('workspaces')
            ->join('memberships', 'memberships.workspace_id', '=', 'workspaces.id')
            ->where('memberships.user_id', $user['id'])
            ->select('workspaces.id', 'workspaces.name', 'workspaces.slug', 'workspaces.postal_address')
            ->orderBy('workspaces.id')
            ->limit(100)
            ->get();
        foreach ($workspaces as &$workspace) {
            $workspace['delivery_mode'] = $this->mode->forWorkspace((int) $workspace['id']);
        }
        unset($workspace);
        return [
            'user' => $user,
            'workspaces' => $workspaces,
            'csrf' => $session['csrf'],
            'provider' => 'workspace-routed',
            'real_sending' => in_array('ses', array_column($workspaces, 'delivery_mode'), true),
        ];
    }

    public function logout(Request $request): Response
    {
        $this->store->db->table('sessions')->where('id', $request->getAttribute('session')['id'])->delete();
        return Response::noContent()->withCookie(Cookie::forget(Auth::COOKIE));
    }

    public function subscribers(Request $request, int $workspace): array
    {
        $this->auth->member($request, $workspace);
        $after = max(0, (int) ($request->getQueryParams()['after'] ?? 0));
        $rows = $this->store
            ->db
            ->table('subscribers')
            ->where('workspace_id', $workspace)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(100)
            ->get();
        foreach ($rows as &$row) {
            $row['tags'] = $this->store
                ->db
                ->table('tags')
                ->join('subscriber_tags', 'subscriber_tags.tag_id', '=', 'tags.id')
                ->where('tags.workspace_id', $workspace)
                ->where('subscriber_tags.workspace_id', $workspace)
                ->where('subscriber_tags.subscriber_id', $row['id'])
                ->select('tags.id', 'tags.name')
                ->get();
        }
        unset($row);
        return ['data' => $rows, 'next' => count($rows) === 100 ? (int) end($rows)['id'] : null];
    }

    public function addSubscriber(Request $request, int $workspace): Response
    {
        $this->auth->member($request, $workspace);
        return Response::created($this->audience->add($workspace, $this->input($request)));
    }

    public function import(Request $request, int $workspace): array
    {
        $this->auth->member($request, $workspace);
        return $this->audience->import($workspace, $this->input($request));
    }

    public function consent(Request $request, int $workspace, int $id): array
    {
        $this->auth->member($request, $workspace);
        $this->store->row($this->store->db, 'subscribers', $workspace, $id);
        return [
            'data' => $this->store
                ->db
                ->table('consents')
                ->where('workspace_id', $workspace)
                ->where('subscriber_id', $id)
                ->orderByDesc('id')
                ->limit(100)
                ->get(),
        ];
    }

    public function tagSubscriber(Request $request, int $workspace, int $id): array
    {
        $this->auth->member($request, $workspace);
        $input = $this->input($request);
        $tags = $input['tags'] ?? null;
        if (!is_array($tags) || count($tags) > 10) {
            throw new HttpException(422, 'At most 10 tag names.');
        }
        $tags = array_values(array_unique(array_map(static fn($tag): string => Store::text(
            ['tag' => $tag],
            'tag',
            60,
        ), $tags)));
        $this->store->db->transaction(function (Connection $db) use ($workspace, $id, $tags): void {
            $this->store->lock($db, $workspace);
            $this->store->row($db, 'subscribers', $workspace, $id);
            $db->table('subscriber_tags')->where('workspace_id', $workspace)->where('subscriber_id', $id)->delete();
            $this->audience->tags($db, $workspace, $id, $tags);
        });
        return ['ok' => true];
    }

    public function suppress(Request $request, int $workspace, int $id): array
    {
        $this->auth->member($request, $workspace);
        $this->store->db->transaction(function (Connection $db) use ($workspace, $id): void {
            $this->store->lock($db, $workspace);
            $this->audience->suppress($db, $workspace, $id, 'unsubscribed');
        });
        return ['ok' => true];
    }

    public function tags(Request $request, int $workspace): array
    {
        $this->auth->member($request, $workspace);
        return [
            'data' => $this->store
                ->db
                ->table('tags')
                ->where('workspace_id', $workspace)
                ->orderBy('name')
                ->limit(200)
                ->get(),
        ];
    }

    public function senders(Request $request, int $workspace): array
    {
        $this->auth->member($request, $workspace);
        return [
            'data' => $this->store
                ->db
                ->table('senders')
                ->where('workspace_id', $workspace)
                ->orderBy('id')
                ->limit(100)
                ->get(),
        ];
    }

    public function addSender(Request $request, int $workspace): Response
    {
        $this->auth->member($request, $workspace);
        $input = $this->input($request);
        $email = Store::email($input['email'] ?? null);
        $name = Store::text($input, 'name');
        $replyTo = !empty($input['reply_to']) ? Store::email($input['reply_to']) : null;
        if (preg_match('/[\r\n]/', $name)) {
            throw new HttpException(422, 'Sender name must be one line.');
        }
        $sender = $this->store->db->transaction(function (Connection $db) use (
            $workspace,
            $email,
            $name,
            $replyTo,
        ): array {
            $this->store->lock($db, $workspace);
            $existing = $db->table('senders')->where('workspace_id', $workspace)->where('email', $email)->first();
            if ($existing) {
                throw new HttpException(409, 'This sender already exists.');
            }
            $domainName = substr($email, strrpos($email, '@') + 1);
            $domainVerified = $db
                ->table('sending_domains')
                ->where('workspace_id', $workspace)
                ->where('domain', $domainName)
                ->where('status', 'verified')
                ->where('checked_at', '>=', $this->store->after(-3600))
                ->exists();
            $id = (int) $db->table('senders')->insert([
                'workspace_id' => $workspace,
                'reply_to' => $replyTo,
                'verified_at' => $domainVerified ? $this->store->now() : null,
                'email' => $email,
                'name' => $name,
                'created_at' => $this->store->now(),
                'updated_at' => $this->store->now(),
            ]);
            if (!$domainVerified) {
                $this->outbox->challenge($db, $workspace, 'sender', $id, $email);
            }
            $db
                ->table('delivery_settings')
                ->where('workspace_id', $workspace)
                ->whereNull('default_sender_id')
                ->update(['default_sender_id' => $id]);
            return $this->store->row($db, 'senders', $workspace, $id);
        });
        return Response::created($sender);
    }

    public function updateSender(Request $request, int $workspace, int $id): array
    {
        $this->auth->member($request, $workspace);
        $input = $this->input($request);
        $name = Store::text($input, 'name');
        if (preg_match('/[\r\n]/', $name)) {
            throw new HttpException(422, 'Sender name must be one line.');
        }
        $replyTo = !empty($input['reply_to']) ? Store::email($input['reply_to']) : null;
        return $this->store->db->transaction(function (Connection $db) use ($workspace, $id, $name, $replyTo): array {
            $this->store->lock($db, $workspace);
            $this->store->row($db, 'senders', $workspace, $id);
            $db
                ->table('senders')
                ->where('id', $id)
                ->update(['name' => $name, 'reply_to' => $replyTo, 'updated_at' => $this->store->now()]);
            return $this->store->row($db, 'senders', $workspace, $id);
        });
    }

    public function revokeSender(Request $request, int $workspace, int $id): array
    {
        $this->auth->member($request, $workspace);
        $this->store->db->transaction(function (Connection $db) use ($workspace, $id): void {
            $this->store->lock($db, $workspace);
            $this->store->row($db, 'senders', $workspace, $id);
            $db
                ->table('senders')
                ->where('id', $id)
                ->update(['verified_at' => null, 'updated_at' => $this->store->now()]);
            $db
                ->table('challenges')
                ->where('workspace_id', $workspace)
                ->where('kind', 'sender')
                ->where('target_id', $id)
                ->whereNull('used_at')
                ->update(['used_at' => $this->store->now()]);
        });
        return ['ok' => true];
    }

    public function verifySender(Request $request, int $workspace, int $id): array
    {
        $this->auth->member($request, $workspace);
        $verified = $this->store->db->transaction(function (Connection $db) use ($workspace, $id): bool {
            $this->store->lock($db, $workspace);
            $sender = $this->store->row($db, 'senders', $workspace, $id);
            $domain = substr($sender['email'], strrpos($sender['email'], '@') + 1);
            if ($db
                ->table('sending_domains')
                ->where('workspace_id', $workspace)
                ->where('domain', $domain)
                ->where('status', 'verified')
                ->where('checked_at', '>=', $this->store->after(-3600))
                ->exists()) {
                $db
                    ->table('senders')
                    ->where('id', $id)
                    ->update(['verified_at' => $this->store->now(), 'updated_at' => $this->store->now()]);
                return true;
            }
            $recent = $db
                ->table('challenges')
                ->where('workspace_id', $workspace)
                ->where('kind', 'sender')
                ->where('target_id', $id)
                ->where('created_at', '>', $this->store->after(-60))
                ->exists();
            if ($recent) {
                throw new HttpException(429, 'Wait a minute before requesting another verification.');
            }
            $this->outbox->challenge($db, $workspace, 'sender', $id, $sender['email']);
            return false;
        });
        return ['ok' => true, 'verified' => $verified];
    }

    public function campaigns(Request $request, int $workspace): array
    {
        $this->auth->member($request, $workspace);
        $before = (int) ($request->getQueryParams()['before'] ?? PHP_INT_MAX);
        $rows = $this->store
            ->db
            ->table('campaigns')
            ->where('workspace_id', $workspace)
            ->where('id', '<', $before)
            ->orderByDesc('id')
            ->limit(50)
            ->get();
        return ['data' => $rows, 'next' => count($rows) === 50 ? (int) end($rows)['id'] : null];
    }

    public function addCampaign(Request $request, int $workspace): Response
    {
        $this->auth->member($request, $workspace);
        return Response::created($this->campaigns->save($workspace, $this->input($request)));
    }

    public function updateCampaign(Request $request, int $workspace, int $id): array
    {
        $this->auth->member($request, $workspace);
        return $this->campaigns->save($workspace, $this->input($request), $id);
    }

    public function schedule(Request $request, int $workspace, int $id): array
    {
        $this->auth->member($request, $workspace);
        $input = $this->input($request);
        if ($this->mode->forWorkspace($workspace) === 'ses' && ($input['confirm_live'] ?? null) !== true) {
            throw new HttpException(422, 'Explicitly confirm this is a real SES campaign, not a fake preview.');
        }
        return $this->campaigns->schedule($workspace, $id, $input);
    }

    public function cancel(Request $request, int $workspace, int $id): array
    {
        $this->auth->member($request, $workspace);
        $this->campaigns->cancel($workspace, $id);
        return ['ok' => true];
    }

    public function test(Request $request, int $workspace, int $id): array
    {
        $this->auth->member($request, $workspace);
        if (
            $this->mode->forWorkspace($workspace) === 'ses'
            && ($this->input($request)['confirm_live'] ?? null) !== true
        ) {
            throw new HttpException(422, 'Explicitly confirm a real SES test to the owner address.');
        }
        return ['delivery_id' => $this->campaigns->test(
            $workspace,
            $id,
            (int) $request->getAttribute('session')['user_id'],
            $request->getHeaderLine('Idempotency-Key'),
            ($this->input($request)['confirm_live'] ?? null) === true,
        )];
    }

    public function preview(Request $request, int $workspace, int $id): Response
    {
        $this->auth->member($request, $workspace);
        $campaign = $this->store->row($this->store->db, 'campaigns', $workspace, $id);
        $message = $this->renderer->render($campaign + ['kind' => 'campaign', 'token' => 'preview'], false);
        return Response::html($message['html'])->withHeader(
            'Content-Security-Policy',
            "default-src 'none'; frame-ancestors 'self'; sandbox",
        );
    }

    public function getWelcome(Request $request, int $workspace): array
    {
        $this->auth->member($request, $workspace);
        return ['data' => $this->store->db->table('welcome_sequences')->where('workspace_id', $workspace)->first()];
    }

    public function saveWelcome(Request $request, int $workspace): array
    {
        $this->auth->member($request, $workspace);
        $input = $this->input($request);
        if (
            $this->mode->forWorkspace($workspace) === 'ses'
            && ($input['enabled'] ?? false)
            && ($input['confirm_live'] ?? null) !== true
        ) {
            throw new HttpException(422, 'Explicitly confirm enabling real SES welcome emails.');
        }
        return $this->campaigns->welcome($workspace, $input);
    }

    public function outbox(Request $request, int $workspace): array
    {
        $this->auth->member($request, $workspace);
        $rows = $this->store
            ->db
            ->table('deliveries')
            ->where('workspace_id', $workspace)
            ->select(
                'id',
                'kind',
                'transport',
                'recipient',
                'subject',
                'body',
                'status',
                'attempts',
                'provider_id',
                'last_error',
                'created_at',
            )
            ->orderByDesc('id')
            ->limit(100)
            ->get();
        $everAuthorized =
            (
                $this->store
                    ->db
                    ->table('delivery_settings')
                    ->where('workspace_id', $workspace)
                    ->first()['first_live_authorized_at'] ?? null
            ) !== null;
        foreach ($rows as &$row) {
            if (($row['transport'] === 'ses' || $everAuthorized) && $row['kind'] === 'confirmation') {
                $row['body'] = 'Confirmation links are delivered only to the recipient and are not exposed in live activity.';
            }
        }
        unset($row);
        return ['data' => $rows];
    }

    public function report(Request $request, int $workspace): array
    {
        $this->auth->member($request, $workspace);
        $campaign = $request->getQueryParams()['campaign_id'] ?? null;
        if ($campaign !== null) {
            $campaign = Store::id($campaign, 'campaign_id');
            $this->store->row($this->store->db, 'campaigns', $workspace, $campaign);
        }
        $filter = $campaign ? ' AND d.campaign_id = ?' : '';
        $bindings = $campaign ? [$workspace, $campaign] : [$workspace];
        $byTransport = [];
        foreach (['fake', 'ses'] as $transport) {
            $scope = $filter . ' AND d.transport = ?';
            $args = [...$bindings, $transport];
            $byTransport[$transport] = [
                'accepted' => (int) $this->store->db->first(
                    'SELECT COUNT(*) AS count FROM deliveries d WHERE d.workspace_id = ? AND d.accepted_at IS NOT NULL'
                    . $scope,
                    $args,
                )['count'],
                'deliveries' => $this->store->db->select(
                    'SELECT d.status, COUNT(*) AS count FROM deliveries d WHERE d.workspace_id = ?'
                    . $scope
                    . ' GROUP BY d.status',
                    $args,
                ),
                'events' => $this->store->db->select(
                    'SELECT e.kind, COUNT(*) AS count, COUNT(DISTINCT e.delivery_id) AS unique_recipients FROM delivery_events e JOIN deliveries d ON d.id = e.delivery_id AND d.workspace_id = e.workspace_id WHERE d.workspace_id = ?'
                    . $scope
                    . ' GROUP BY e.kind',
                    $args,
                ),
            ];
        }
        return [
            'by_transport' => $byTransport,
            'ses_eligible' => $this->store
                ->db
                ->table('subscribers')
                ->where('workspace_id', $workspace)
                ->where('status', 'active')
                ->whereNotNull('confirmed_at')
                ->whereIn('consent_transport', ['ses', 'recorded'])
                ->count(),
            'subscribers' => $this->store->db->select('SELECT status, COUNT(*) AS count FROM subscribers WHERE workspace_id = ? GROUP BY status', [
                $workspace,
            ]),
            'deliveries' => $this->store->db->select(
                'SELECT d.status, COUNT(*) AS count FROM deliveries d WHERE d.workspace_id = ?'
                . $filter
                . ' GROUP BY d.status',
                $bindings,
            ),
            'accepted' => (int) $this->store->db->first(
                'SELECT COUNT(*) AS count FROM deliveries d WHERE d.workspace_id = ? AND d.accepted_at IS NOT NULL'
                . $filter,
                $bindings,
            )['count'],
            'events' => $this->store->db->select(
                'SELECT e.kind, COUNT(*) AS count, COUNT(DISTINCT e.delivery_id) AS unique_recipients FROM delivery_events e JOIN deliveries d ON d.id = e.delivery_id AND d.workspace_id = e.workspace_id WHERE d.workspace_id = ?'
                . $filter
                . ' GROUP BY e.kind',
                $bindings,
            ),
            'note' => 'Fake simulations and real SES outcomes are separated. Accepted is not delivered. Clicks may include security scanners. No open tracking.',
        ];
    }
}
