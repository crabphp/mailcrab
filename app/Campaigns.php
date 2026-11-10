<?php

declare(strict_types=1);

namespace Mailcrab;

use Crab\Contracts\Database\Connection;
use Crab\Http\HttpException;

final readonly class Campaigns
{
    public function __construct(
        private Store $store,
        private Outbox $outbox,
        private \Mailcrab\Delivery\DeliveryMode $mode,
    ) {}

    public function content(Connection $db, int $workspace, array $input): array
    {
        $sender = $this->store->row($db, 'senders', $workspace, Store::id($input['sender_id'] ?? null, 'sender_id'));
        $subject = Store::text($input, 'subject', 180);
        $body = Store::text($input, 'body', 50000);
        if (preg_match('/[\r\n]/', $subject)) {
            throw new HttpException(422, 'Subject must be one line.');
        }
        $tag = isset($input['tag_id']) && $input['tag_id'] !== '' ? Store::id($input['tag_id'], 'tag_id') : null;
        if ($tag !== null) {
            $this->store->row($db, 'tags', $workspace, $tag);
        }
        return ['sender_id' => $sender['id'], 'subject' => $subject, 'body' => $body, 'tag_id' => $tag];
    }

    public function save(int $workspace, array $input, ?int $id = null): array
    {
        return $this->store->db->transaction(function (Connection $db) use ($workspace, $input, $id): array {
            $w = $this->store->lock($db, $workspace);
            $data = $this->content($db, $workspace, $input) + ['updated_at' => $this->store->now()];
            if ($id !== null) {
                $campaign = $this->store->row($db, 'campaigns', $workspace, $id);
                if ($campaign['status'] !== 'draft') {
                    throw new HttpException(409, 'Scheduled content is immutable. Duplicate it as a new draft.');
                }
                $db->table('campaigns')->where('id', $id)->update($data);
            } else {
                $id = (int) $db->table('campaigns')->insert(
                    $data
                    + [
                        'workspace_id' => $workspace,
                        'postal_address' => $w['postal_address'],
                        'created_at' => $this->store->now(),
                    ],
                );
            }
            return $this->store->row($db, 'campaigns', $workspace, $id);
        });
    }

    public function schedule(int $workspace, int $id, array $input): array
    {
        $at = Store::timestamp($input['scheduled_at'] ?? null, 'scheduled_at');
        return $this->store->db->transaction(function (Connection $db) use ($workspace, $id, $at, $input): array {
            $w = $this->store->lock($db, $workspace);
            $campaign = $this->store->row($db, 'campaigns', $workspace, $id);
            if ($campaign['status'] !== 'draft') {
                if ($campaign['scheduled_at'] === $at && $campaign['status'] !== 'cancelled') {
                    return $campaign;
                }
                throw new HttpException(409, 'Campaign is already scheduled.');
            }
            if ($at < $this->store->now() || $at > $this->store->after(31536000)) {
                throw new HttpException(422, 'Schedule within the next year.');
            }
            $sender = $this->store->row($db, 'senders', $workspace, (int) $campaign['sender_id']);
            if (!$sender['verified_at']) {
                throw new HttpException(422, 'Verify the sender first.');
            }
            if (trim($w['postal_address']) === '') {
                throw new HttpException(422, 'A postal address is required.');
            }
            $transport = $this->mode->forWorkspace($workspace, $db);
            if ($transport === 'ses' && ($input['confirm_live'] ?? null) !== true) {
                throw new HttpException(422, 'Explicitly confirm scheduling real SES emails.');
            }
            $max = $db->first('SELECT MAX(id) AS max_id FROM subscribers WHERE workspace_id = ?', [$workspace]);
            $db
                ->table('campaigns')
                ->where('id', $id)
                ->update([
                    'status' => 'scheduled',
                    'transport' => $transport,
                    'scheduled_at' => $at,
                    'audience_max' => (int) ($max['max_id'] ?? 0),
                    'postal_address' => $w['postal_address'],
                    'updated_at' => $this->store->now(),
                ]);
            return $this->store->row($db, 'campaigns', $workspace, $id);
        });
    }

    public function cancel(int $workspace, int $id): void
    {
        $this->store->db->transaction(function (Connection $db) use ($workspace, $id): void {
            $this->store->lock($db, $workspace);
            $campaign = $this->store->row($db, 'campaigns', $workspace, $id);
            if ($campaign['status'] === 'finished') {
                throw new HttpException(409, 'Campaign has finished.');
            }
            $db
                ->table('campaigns')
                ->where('id', $id)
                ->update(['status' => 'cancelled', 'updated_at' => $this->store->now()]);
        });
    }

    // Exactly one campaign page per tick. No unbounded recipient materialization or LIMIT/OFFSET drift.
    public function expand(int $limit): int
    {
        $limit = max(1, min(100, $limit));
        $candidate = $this->store
            ->db
            ->table('campaigns')
            ->whereIn('status', ['scheduled', 'queuing'])
            ->where('scheduled_at', '<=', $this->store->now())
            ->orderBy('id')
            ->first();
        if (!$candidate) {
            return 0;
        }
        return $this->store->db->transaction(function (Connection $db) use ($candidate, $limit): int {
            $wid = (int) $candidate['workspace_id'];
            $this->store->lock($db, $wid);
            $campaign = $this->store->row($db, 'campaigns', $wid, (int) $candidate['id']);
            if (!in_array($campaign['status'], ['scheduled', 'queuing'], true)) {
                return 0;
            }
            $sender = $this->store->row($db, 'senders', $wid, (int) $campaign['sender_id']);
            $rows = $db
                ->table('subscribers')
                ->where('workspace_id', $wid)
                ->where('id', '>', $campaign['audience_cursor'])
                ->where('id', '<=', $campaign['audience_max'])
                ->orderBy('id')
                ->limit($limit)
                ->get();
            $cursor = (int) $campaign['audience_cursor'];
            $count = 0;
            foreach ($rows as $subscriber) {
                $cursor = (int) $subscriber['id'];
                if (
                    $subscriber['status'] !== 'active'
                    || $campaign['transport'] === 'ses'
                    && !in_array($subscriber['consent_transport'], ['ses', 'recorded'], true)
                ) {
                    continue;
                }
                if (
                    $campaign['tag_id'] !== null
                    && !$db
                        ->table('subscriber_tags')
                        ->where('workspace_id', $wid)
                        ->where('subscriber_id', $subscriber['id'])
                        ->where('tag_id', $campaign['tag_id'])
                        ->exists()
                ) {
                    continue;
                }
                $this->outbox->queue($db, $wid, "campaign:{$campaign['id']}:{$subscriber['id']}", [
                    'kind' => 'campaign',
                    'transport' => $campaign['transport'],
                    'campaign_id' => $campaign['id'],
                    'subscriber_id' => $subscriber['id'],
                    'sender_id' => $sender['id'],
                    'recipient' => $subscriber['email'],
                    'from_email' => $sender['email'],
                    'from_name' => $sender['name'],
                    'subject' => $campaign['subject'],
                    'body' => $campaign['body'],
                    'postal_address' => $campaign['postal_address'],
                ]);
                ++$count;
            }
            $done = count($rows) < $limit || $cursor >= (int) $campaign['audience_max'];
            $db
                ->table('campaigns')
                ->where('id', $campaign['id'])
                ->update(['audience_cursor' => $cursor, 'status' => $done ? 'sending' : 'queuing']);
            return $count;
        });
    }

    public function test(int $workspace, int $id, int $user, string $requestId, bool $confirmLive = false): int
    {
        if (!preg_match('/^[a-zA-Z0-9-]{16,80}$/D', $requestId)) {
            throw new HttpException(422, 'Send an Idempotency-Key of 16–80 letters/digits/hyphens.');
        }
        return $this->store->db->transaction(function (Connection $db) use (
            $workspace,
            $id,
            $user,
            $requestId,
            $confirmLive,
        ): int {
            $this->store->lock($db, $workspace);
            $campaign = $this->store->row($db, 'campaigns', $workspace, $id);
            $sender = $this->store->row($db, 'senders', $workspace, (int) $campaign['sender_id']);
            if (!$sender['verified_at']) {
                throw new HttpException(422, 'Verify the sender before a test send.');
            }
            $transport = $this->mode->forWorkspace($workspace, $db);
            if ($transport === 'ses' && !$confirmLive) {
                throw new HttpException(422, 'Explicitly confirm sending a real SES test email.');
            }
            $recipient = $db->table('users')->where('id', $user)->first()['email'];
            return $this->outbox->queue($db, $workspace, "test:{$workspace}:{$id}:{$user}:{$requestId}", [
                'kind' => 'test',
                'transport' => $transport,
                'sender_id' => $sender['id'],
                'recipient' => $recipient,
                'from_email' => $sender['email'],
                'from_name' => $sender['name'],
                'subject' => '[Test] ' . mb_strcut($campaign['subject'], 0, 173, 'UTF-8'),
                'body' => $campaign['body'],
                'postal_address' => $campaign['postal_address'],
            ]);
        });
    }

    public function welcome(int $workspace, array $input): array
    {
        return $this->store->db->transaction(function (Connection $db) use ($workspace, $input): array {
            $this->store->lock($db, $workspace);
            $content = $this->content($db, $workspace, $input);
            unset($content['tag_id']);
            if (!is_bool($input['enabled'] ?? null)) {
                throw new HttpException(422, 'enabled must be boolean.');
            }
            $sender = $this->store->row($db, 'senders', $workspace, (int) $content['sender_id']);
            if ($input['enabled'] && !$sender['verified_at']) {
                throw new HttpException(422, 'Verify the welcome sender first.');
            }
            $row = $db->table('welcome_sequences')->where('workspace_id', $workspace)->first();
            $transport = $this->mode->forWorkspace($workspace, $db);
            if ($transport === 'ses' && $input['enabled'] && ($input['confirm_live'] ?? null) !== true) {
                throw new HttpException(422, 'Explicitly confirm enabling real SES welcome emails.');
            }
            $data = $content
            + ['enabled' => $input['enabled'], 'transport' => $transport, 'updated_at' => $this->store->now()];
            if ($row) {
                $db->table('welcome_sequences')->where('id', $row['id'])->update($data);
            } else {
                $db->table('welcome_sequences')->insert(
                    $data + ['workspace_id' => $workspace, 'created_at' => $this->store->now()],
                );
            }
            return $db->table('welcome_sequences')->where('workspace_id', $workspace)->first();
        });
    }
}
