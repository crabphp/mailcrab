<?php

declare(strict_types=1);

namespace Mailcrab\Delivery;

use Crab\Contracts\Database\Connection;
use Crab\Http\HttpException;
use Mailcrab\Feedback;
use Mailcrab\Store;

final readonly class SesEvents
{
    public function __construct(
        private Store $store,
        private AwsConnection $aws,
        private SnsVerifier $verifier,
        private DeliverySettings $settings,
        private Feedback $feedback,
    ) {}

    public function receive(int $workspace, string $body): void
    {
        $this->store->db->table('workspaces')->where('id', $workspace)->first() ?? throw new HttpException(
            404,
            'Not found.',
        );
        $message = $this->verifier->verify($body);
        if ($message['Type'] !== 'Notification') {
            $this->control($workspace, $message);
            return;
        }
        try {
            $event = json_decode($message['Message'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'Malformed SES notification.');
        }
        if (!is_array($event)) {
            throw new HttpException(422, 'SES notification must be an object.');
        }
        $set = $event['mail']['tags']['ses:configuration-set'][0] ?? null;
        // A shared SNS topic may contain other workspaces/apps. Do not persist or expose those events.
        if ($set !== $this->aws->configurationSet($workspace)) {
            return;
        }
        $type = $event['eventType'] ?? $event['notificationType'] ?? '';
        $kind = match ($type) {
            'Delivery' => 'delivered',
            'Complaint' => 'complaint',
            'Bounce' => ($event['bounce']['bounceType'] ?? '') === 'Permanent' ? 'bounce' : 'transient_bounce',
            'DeliveryDelay' => 'delivery_delay',
            'Reject', 'Rendering Failure', 'RenderingFailure' => 'rejected',
            default => null,
        };
        if ($kind === null) {
            return;
        }
        $provider = Store::text($event['mail'] ?? [], 'messageId', 190);
        $recipients = match ($kind) {
            'delivered' => $event['delivery']['recipients'] ?? [],
            'bounce', 'transient_bounce' => array_column($event['bounce']['bouncedRecipients'] ?? [], 'emailAddress'),
            'complaint' => array_column($event['complaint']['complainedRecipients'] ?? [], 'emailAddress'),
            default => $event['mail']['destination'] ?? [],
        };
        if (!is_array($recipients) || count($recipients) > 50) {
            throw new HttpException(422, 'Invalid SES recipient list.');
        }
        $recipients = array_map(Store::email(...), $recipients);
        $tags = $event['mail']['tags'] ?? [];
        $payload = [
            'provider_id' => $provider,
            'kind' => $kind,
            'recipients' => $recipients,
            'delivery_id' => $tags['mailcrab_delivery'][0] ?? null,
            'workspace_id' => $tags['mailcrab_workspace'][0] ?? null,
        ];
        $key = hash('sha256', $message['TopicArn'] . ':' . $message['MessageId']);
        $this->store
            ->db
            ->table('ses_event_inbox')
            ->insertOrIgnore([
                'workspace_id' => $workspace,
                'event_key' => $key,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'available_at' => $this->store->now(),
                'created_at' => $this->store->now(),
            ]);
        // Durable before applying. A callback can arrive before the worker commits provider acceptance.
        $row = $this->store
            ->db
            ->table('ses_event_inbox')
            ->where('event_key', $key)
            ->where('workspace_id', $workspace)
            ->first();
        if ($row) {
            $this->apply($row);
        }
    }

    private function control(int $workspace, array $message): void
    {
        $this->settings->get($workspace);
        $key = hash('sha256', $message['TopicArn'] . ':' . $message['MessageId']);
        $token = $message['Type'] === 'SubscriptionConfirmation' ? Store::text($message, 'Token', 8192) : null;
        $this->store->db->transaction(function (Connection $db) use ($workspace, $message, $key, $token): void {
            $this->store->lock($db, $workspace);
            $inserted = $db->table('ses_event_inbox')->insertOrIgnore([
                'workspace_id' => $workspace,
                'event_key' => $key,
                'payload' => json_encode(['type' => $message['Type']], JSON_THROW_ON_ERROR),
                'status' => 'processed',
                'available_at' => $this->store->now(),
                'created_at' => $this->store->now(),
            ]);
            if ($inserted === 0) {
                return;
            }
            $values = ['feedback' => null, 'feedback_checked_at' => null, 'updated_at' => $this->store->now()];
            if ($token !== null) {
                $values['subscription_token'] = $token;
            } else {
                $values += [
                    'subscription_arn' => null,
                    'subscription_token' => null,
                    'feedback_error' => 'AWS reports this feedback subscription was removed. Reconnect feedback.',
                ];
            }
            $db->table('delivery_settings')->where('workspace_id', $workspace)->update($values);
        });
    }

    public function process(int $batch = 50): int
    {
        $rows = $this->store
            ->db
            ->table('ses_event_inbox')
            ->where('status', 'pending')
            ->where('available_at', '<=', $this->store->now())
            ->orderBy('id')
            ->limit(max(1, min(100, $batch)))
            ->get();
        foreach ($rows as $row) {
            $this->apply($row);
        }
        return count($rows);
    }

    private function apply(array $row): void
    {
        $this->store->db->transaction(function (Connection $db) use ($row): void {
            $workspace = (int) $row['workspace_id'];
            $this->store->lock($db, $workspace);
            $current = $this->store->row($db, 'ses_event_inbox', $workspace, (int) $row['id']);
            if ($current['status'] !== 'pending' || $current['available_at'] > $this->store->now()) {
                return;
            }
            $payload = json_decode($current['payload'], true, 16, JSON_THROW_ON_ERROR);
            $delivery = $db
                ->table('deliveries')
                ->where('workspace_id', $workspace)
                ->where('transport', 'ses')
                ->where('provider_id', $payload['provider_id'])
                ->first();
            // Recovery is permitted only with signed workspace + delivery tags AND matching recipient.
            if (
                !$delivery
                && (string) $payload['workspace_id'] === (string) $workspace
                && ctype_digit((string) $payload['delivery_id'])
            ) {
                $candidate = $db
                    ->table('deliveries')
                    ->where('workspace_id', $workspace)
                    ->where('id', (int) $payload['delivery_id'])
                    ->where('transport', 'ses')
                    ->whereIn('status', ['sending', 'unknown'])
                    ->whereNull('provider_id')
                    ->first();
                if ($candidate && in_array($candidate['recipient'], $payload['recipients'], true)) {
                    $db
                        ->table('deliveries')
                        ->where('id', $candidate['id'])
                        ->update([
                            'provider_id' => $payload['provider_id'],
                            'accepted_at' => $this->store->now(),
                            'status' => 'accepted',
                        ]);
                    $delivery = $db->table('deliveries')->where('id', $candidate['id'])->first();
                }
            }
            if (!$delivery) {
                $attempts = (int) $current['attempts'] + 1;
                $db
                    ->table('ses_event_inbox')
                    ->where('id', $row['id'])
                    ->update([
                        'attempts' => $attempts,
                        'status' => $attempts < 12 ? 'pending' : 'unmatched',
                        'available_at' => $this->store->after(60 * min(60, 2 ** ($attempts - 1))),
                        'last_error' => 'No matching accepted delivery yet. Reconciliation is bounded; no email is resent.',
                    ]);
                return;
            }
            if (!in_array($delivery['recipient'], $payload['recipients'], true)) {
                $db
                    ->table('ses_event_inbox')
                    ->where('id', $row['id'])
                    ->update(['status' => 'ignored', 'last_error' => 'Recipient does not match delivery.']);
                return;
            }
            // Apply suppression and mark the inbox processed on the SAME held connection.
            $this->feedback->event($delivery, 'ses:' . $row['event_key'], $payload['kind'], $db);
            $db
                ->table('ses_event_inbox')
                ->where('id', $row['id'])
                ->update(['status' => 'processed', 'last_error' => null]);
        });
    }
}
