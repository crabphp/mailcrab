<?php

declare(strict_types=1);

namespace Mailcrab\Http;

use Crab\Contracts\Http\Request;
use Crab\Http\HttpException;
use Crab\Http\Response;
use Mailcrab\Auth;
use Mailcrab\Delivery\DeliverySettings;
use Mailcrab\Delivery\Domains;
use Mailcrab\Delivery\FeedbackSetup;
use Mailcrab\Delivery\SesEvents;

final readonly class DeliveryApi
{
    public function __construct(
        private Auth $auth,
        private DeliverySettings $settings,
        private Domains $domains,
        private FeedbackSetup $feedback,
        private SesEvents $events,
    ) {}

    private function input(Request $request): array
    {
        $input = $request->getParsedBody();
        if (!is_array($input)) {
            throw new HttpException(422, 'Send a JSON object.');
        }
        return $input;
    }

    public function show(Request $request, int $workspace): array
    {
        $this->auth->member($request, $workspace);
        return $this->settings->overview($workspace);
    }

    public function connection(Request $request, int $workspace): array
    {
        $this->auth->member($request, $workspace);
        $this->settings->checkConnection($workspace);
        return $this->settings->overview($workspace);
    }

    public function preferences(Request $request, int $workspace): array
    {
        $this->auth->member($request, $workspace);
        $this->settings->preferences($workspace, $this->input($request));
        return $this->settings->overview($workspace);
    }

    public function addDomain(Request $request, int $workspace): Response
    {
        $this->auth->member($request, $workspace);
        return Response::created($this->domains->add($workspace, $this->input($request)));
    }

    public function connectDomain(Request $request, int $workspace, int $id): array
    {
        $this->auth->member($request, $workspace);
        $this->domains->connect($workspace, $id, $this->input($request));
        return $this->settings->overview($workspace);
    }

    public function refreshDomain(Request $request, int $workspace, int $id): array
    {
        $this->auth->member($request, $workspace);
        $this->domains->refresh($workspace, $id);
        return $this->settings->overview($workspace);
    }

    public function disconnectDomain(Request $request, int $workspace, int $id): array
    {
        $this->auth->member($request, $workspace);
        $this->domains->disconnect($workspace, $id);
        return $this->settings->overview($workspace);
    }

    public function connectFeedback(Request $request, int $workspace): array
    {
        $this->auth->member($request, $workspace);
        $this->feedback->connect($workspace, $this->input($request));
        return $this->settings->overview($workspace);
    }

    public function confirmFeedback(Request $request, int $workspace): array
    {
        $this->auth->member($request, $workspace);
        $this->feedback->confirm($workspace, $this->input($request));
        return $this->settings->overview($workspace);
    }

    public function checkFeedback(Request $request, int $workspace): array
    {
        $this->auth->member($request, $workspace);
        $this->feedback->check($workspace);
        return $this->settings->overview($workspace);
    }

    public function webhook(Request $request, int $workspace): Response
    {
        $this->events->receive($workspace, (string) $request->getBody());
        return Response::json(['ok' => true], 202);
    }
}
