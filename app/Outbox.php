<?php

declare(strict_types=1);

namespace Mailcrab;

use Crab\Contracts\Database\Connection;

final readonly class Outbox
{
    public function __construct(
        private Store $store,
        private \Mailcrab\Delivery\DeliveryMode $mode,
    ) {}

    // Caller holds the workspace lock. The database uniqueness constraint is the final dedupe gate.
    public function queue(Connection $db, int $workspace, string $key, array $data): int
    {
        $existing = $db->table('deliveries')->where('dedupe_key', $key)->first();
        if ($existing) {
            return (int) $existing['id'];
        }
        $data['transport'] ??= $this->mode->forWorkspace($workspace, $db);
        if ($data['transport'] === 'ses' && !isset($data['sender_id'])) {
            $settings = $db->table('delivery_settings')->where('workspace_id', $workspace)->first();
            if (!$settings || !$settings['default_sender_id']) {
                throw new \Crab\Http\HttpException(503, 'The workspace needs a verified default confirmation sender.');
            }
            $sender = $this->store->row($db, 'senders', $workspace, (int) $settings['default_sender_id']);
            $data['sender_id'] = $sender['id'];
            $data['from_email'] = $sender['email'];
            $data['from_name'] = $sender['name'];
        }
        if (isset($data['sender_id'])) {
            $sender = $this->store->row($db, 'senders', $workspace, (int) $data['sender_id']);
            $data['reply_to'] = $sender['reply_to'];
        }
        return (int) $db->table('deliveries')->insert(
            $data
            + [
                'workspace_id' => $workspace,
                'dedupe_key' => $key,
                'token' => bin2hex(random_bytes(32)),
                'campaign_id' => null,
                'subscriber_id' => null,
                'sender_id' => null,
                'kind' => 'confirmation',
                'from_email' => 'no-reply@mailcrab.invalid',
                'from_name' => 'Mailcrab',
                'postal_address' => '',
                'status' => 'queued',
                'attempts' => 0,
                'available_at' => $this->store->now(),
                'created_at' => $this->store->now(),
            ],
        );
    }

    public function challenge(Connection $db, int $workspace, string $kind, int $target, string $recipient): void
    {
        $transport = $this->mode->forWorkspace($workspace, $db);
        $token = bin2hex(random_bytes(32));
        $id = $db->table('challenges')->insert([
            'transport' => $transport,
            'workspace_id' => $workspace,
            'kind' => $kind,
            'target_id' => $target,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $this->store->after(172800),
            'created_at' => $this->store->now(),
        ]);
        $url = rtrim((string) config('app.url'), '/') . '/confirm/' . $token;
        $db
            ->table('challenges')
            ->where('workspace_id', $workspace)
            ->where('kind', $kind)
            ->where('target_id', $target)
            ->where('id', '<', $id)
            ->whereNull('used_at')
            ->update(['used_at' => $this->store->now()]);
        $this->queue($db, $workspace, "challenge:{$id}", [
            'transport' => $transport,
            'recipient' => $recipient,
            'subscriber_id' => $kind === 'subscriber' ? $target : null,
            'subject' => $kind === 'sender' ? 'Verify your Mailcrab sender' : 'Confirm your newsletter subscription',
            'body' => "Please [confirm your email]({$url}). This link expires in 48 hours.\n\nIf you did not request this, ignore this message.",
        ]);
    }

    public function welcome(Connection $db, int $workspace, array $subscriber): void
    {
        $sequence = $db->table('welcome_sequences')->where('workspace_id', $workspace)->where('enabled', true)->first();
        if (
            !$sequence
            || $sequence['transport'] === 'ses'
            && !in_array($subscriber['consent_transport'], ['ses', 'recorded'], true)
        ) {
            return;
        }
        $sender = $db
            ->table('senders')
            ->where('workspace_id', $workspace)
            ->where('id', $sequence['sender_id'])
            ->whereNotNull('verified_at')
            ->first();
        if (!$sender) {
            return;
        }
        $address = $db->table('workspaces')->where('id', $workspace)->first()['postal_address'];
        $this->queue($db, $workspace, "welcome:{$workspace}:{$subscriber['id']}", [
            'kind' => 'welcome',
            'transport' => $sequence['transport'],
            'recipient' => $subscriber['email'],
            'subscriber_id' => $subscriber['id'],
            'sender_id' => $sender['id'],
            'from_email' => $sender['email'],
            'from_name' => $sender['name'],
            'subject' => $sequence['subject'],
            'body' => $sequence['body'],
            'postal_address' => $address,
        ]);
    }
}
