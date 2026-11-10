<?php

declare(strict_types=1);

namespace Mailcrab;

use Crab\Contracts\Database\Connection;
use Crab\Http\HttpException;

final readonly class Audience
{
    public const CONSENT = 'I want to receive this newsletter by email. I can unsubscribe at any time.';

    public function __construct(
        private Store $store,
        private Outbox $outbox,
        private \Mailcrab\Delivery\DeliveryMode $mode,
    ) {}

    public function add(int $workspace, array $input): array
    {
        $record = $this->validate($input);
        return $this->store->db->transaction(function (Connection $db) use ($workspace, $record): array {
            $this->store->lock($db, $workspace);
            return $this->insertOptedIn($db, $workspace, $record);
        });
    }

    public function import(int $workspace, array $input): array
    {
        if (($input['origin'] ?? null) !== 'existing_opt_in' || ($input['consent'] ?? null) !== true) {
            throw new HttpException(
                422,
                'Only existing, explicitly opted-in audiences may be imported. No scraped or purchased lists.',
            );
        }
        $source = Store::text($input, 'source', 500);
        $evidence = Store::text($input, 'evidence', 2000);
        $csv = Store::text($input, 'csv', 262144);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        try {
            $header = fgetcsv($stream, escape: '');
            if (!$header) {
                throw new HttpException(422, 'CSV needs a header.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            if (
                count($header) !== count(array_unique($header))
                || array_diff($header, ['email', 'name', 'tags', 'consent_at'])
                || !in_array('email', $header, true)
                || !in_array('consent_at', $header, true)
            ) {
                throw new HttpException(
                    422,
                    'CSV headers: email,consent_at and optional name,tags. Tags use | separators.',
                );
            }
            $records = [];
            while (($row = fgetcsv($stream, escape: '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($records) >= 500) {
                    throw new HttpException(422, 'Import at most 500 rows per batch.');
                }
                if (count($row) !== count($header)) {
                    throw new HttpException(422, 'CSV column count mismatch at row ' . (count($records) + 2));
                }
                $data = array_combine($header, $row);
                $data['tags'] = ($data['tags'] ?? '') === '' ? [] : explode('|', $data['tags']);
                $records[] = $this->validate($data + ['consent' => true, 'source' => $source, 'evidence' => $evidence]);
            }
        } finally {
            fclose($stream);
        }
        return $this->store->db->transaction(function (Connection $db) use ($workspace, $records): array {
            $this->store->lock($db, $workspace);
            $counts = ['imported' => 0, 'duplicate' => 0, 'suppressed' => 0];
            foreach ($records as $record) {
                $result = $this->insertOptedIn($db, $workspace, $record);
                ++$counts[$result['result']];
            }
            return $counts;
        });
    }

    private function validate(array $input): array
    {
        if (($input['consent'] ?? null) !== true) {
            throw new HttpException(422, 'Explicit opt-in consent is required.');
        }
        $at = Store::timestamp($input['consent_at'] ?? null);
        if ($at > $this->store->now()) {
            throw new HttpException(422, 'Consent cannot be in the future.');
        }
        $tags = $input['tags'] ?? [];
        if (!is_array($tags) || count($tags) > 10) {
            throw new HttpException(422, 'At most 10 tags.');
        }
        $tags = array_values(array_unique(array_map(static fn($tag): string => Store::text(
            ['tag' => $tag],
            'tag',
            60,
        ), $tags)));
        return [
            'email' => Store::email($input['email'] ?? null),
            'name' => Store::text($input, 'name', 120, false),
            'tags' => $tags,
            'consent_at' => $at,
            'source' => Store::text($input, 'source', 500),
            'evidence' => Store::text($input, 'evidence', 2000),
        ];
    }

    private function insertOptedIn(Connection $db, int $workspace, array $record): array
    {
        $existing = $db
            ->table('subscribers')
            ->where('workspace_id', $workspace)
            ->where('email', $record['email'])
            ->first();
        if (
            $this->suppressed($db, $workspace, $record['email'])
            || $existing && in_array($existing['status'], ['unsubscribed', 'bounced', 'complained'], true)
        ) {
            return ['result' => 'suppressed'];
        }
        // An import never upgrades a pending DOI subscription, rewrites consent, or re-enrolls a welcome.
        if ($existing) {
            return ['result' => 'duplicate', 'id' => (int) $existing['id']];
        }
        $id = (int) $db->table('subscribers')->insert([
            'workspace_id' => $workspace,
            'email' => $record['email'],
            'name' => $record['name'],
            'status' => 'active',
            'confirmed_at' => $record['consent_at'],
            'consent_transport' => 'recorded',
            'created_at' => $this->store->now(),
            'updated_at' => $this->store->now(),
        ]);
        $this->consent(
            $db,
            $workspace,
            $id,
            'import_opt_in',
            $record['source'],
            $record['evidence'],
            $record['consent_at'],
        );
        $this->tags($db, $workspace, $id, $record['tags']);
        return ['result' => 'imported', 'id' => $id];
    }

    public function tags(Connection $db, int $workspace, int $subscriber, array $tags): void
    {
        foreach ($tags as $name) {
            $tag = $db->table('tags')->where('workspace_id', $workspace)->where('name', $name)->first();
            $id = $tag['id'] ?? $db->table('tags')->insert(['workspace_id' => $workspace, 'name' => $name]);
            $db->table('subscriber_tags')->insertOrIgnore([
                'workspace_id' => $workspace,
                'subscriber_id' => $subscriber,
                'tag_id' => $id,
            ]);
        }
    }

    public function signup(string $slug, array $input): void
    {
        $email = Store::email($input['email'] ?? null);
        $name = Store::text($input, 'name', 120, false);
        if (!in_array($input['consent'] ?? null, [true, 'yes'], true)) {
            throw new HttpException(422, 'Please explicitly consent to receive the newsletter.');
        }
        $workspace = $this->store->db->table('workspaces')->where('slug', $slug)->first() ?? throw new HttpException(
            404,
            'Newsletter not found.',
        );
        $this->store->db->transaction(function (Connection $db) use ($workspace, $email, $name): void {
            $wid = (int) $workspace['id'];
            $this->store->lock($db, $wid);
            if ($this->suppressed($db, $wid, $email)) {
                return;
            }
            $subscriber = $db->table('subscribers')->where('workspace_id', $wid)->where('email', $email)->first();
            $transport = $this->mode->forWorkspace($wid, $db);
            if ($subscriber && $subscriber['status'] !== 'pending') {
                if (
                    $subscriber['status'] !== 'active'
                    || $subscriber['consent_transport'] !== 'fake'
                    || $transport !== 'ses'
                ) {
                    return;
                }
                // Simulation consent is not recipient possession: request a real DOI before SES eligibility.
                $db
                    ->table('subscribers')
                    ->where('id', $subscriber['id'])
                    ->update(['status' => 'pending', 'confirmed_at' => null, 'updated_at' => $this->store->now()]);
            }
            if ($subscriber) {
                $recent = $db
                    ->table('challenges')
                    ->where('workspace_id', $wid)
                    ->where('kind', 'subscriber')
                    ->where('target_id', $subscriber['id'])
                    ->where('transport', $transport)
                    ->where('created_at', '>', $this->store->after(-3600))
                    ->exists();
                if ($recent) {
                    return;
                }
            }
            $id = $subscriber['id'] ?? $db->table('subscribers')->insert([
                'workspace_id' => $wid,
                'email' => $email,
                'name' => $name,
                'status' => 'pending',
                'created_at' => $this->store->now(),
                'updated_at' => $this->store->now(),
            ]);
            $this->consent(
                $db,
                $wid,
                (int) $id,
                'signup_requested',
                '/subscribe/' . $workspace['slug'],
                self::CONSENT,
                $this->store->now(),
            );
            $this->outbox->challenge($db, $wid, 'subscriber', (int) $id, $email);
        });
    }

    public function confirm(#[\SensitiveParameter] string $token): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new HttpException(404, 'Confirmation not found.');
        }
        $challenge = $this->store
            ->db
            ->table('challenges')
            ->where('token_hash', hash('sha256', $token))
            ->first() ?? throw new HttpException(404, 'Confirmation not found.');
        return $this->store->db->transaction(function (Connection $db) use ($challenge): string {
            $wid = (int) $challenge['workspace_id'];
            $this->store->lock($db, $wid);
            $row = $this->store->row($db, 'challenges', $wid, (int) $challenge['id']);
            $settings = $db->table('delivery_settings')->where('workspace_id', $wid)->first();
            if ($row['transport'] === 'fake' && ($settings['first_live_authorized_at'] ?? null) !== null) {
                throw new HttpException(
                    410,
                    'This simulated confirmation cannot authorize real email. Request a new live confirmation.',
                );
            }
            if ($row['used_at'] !== null) {
                return 'This confirmation has already been processed.';
            }
            if ($row['expires_at'] <= $this->store->now()) {
                throw new HttpException(410, 'This confirmation expired. Request a new one.');
            }
            $db->table('challenges')->where('id', $row['id'])->update(['used_at' => $this->store->now()]);
            if ($row['kind'] === 'sender') {
                $db
                    ->table('senders')
                    ->where('workspace_id', $wid)
                    ->where('id', $row['target_id'])
                    ->update(['verified_at' => $this->store->now()]);
                return 'Sender verified for local fake delivery. SES requires a separately verified identity.';
            }
            $subscriber = $this->store->row($db, 'subscribers', $wid, (int) $row['target_id']);
            if ($subscriber['status'] !== 'pending' || $this->suppressed($db, $wid, $subscriber['email'])) {
                return 'This confirmation has been processed.';
            }
            $db
                ->table('subscribers')
                ->where('id', $subscriber['id'])
                ->update([
                    'status' => 'active',
                    'confirmed_at' => $this->store->now(),
                    'consent_transport' => $row['transport'],
                    'updated_at' => $this->store->now(),
                ]);
            $this->consent(
                $db,
                $wid,
                (int) $subscriber['id'],
                'double_opt_in',
                'email_confirmation',
                self::CONSENT,
                $this->store->now(),
            );
            $subscriber['consent_transport'] = $row['transport'];
            $this->outbox->welcome($db, $wid, $subscriber);
            return 'You are subscribed. Welcome aboard!';
        });
    }

    public function suppressed(Connection $db, int $workspace, string $email): bool
    {
        return $db->table('suppressions')->where('workspace_id', $workspace)->where('email', $email)->exists();
    }

    // Caller holds workspace lock; pending jobs recheck this before crossing the provider boundary.
    public function suppress(Connection $db, int $workspace, int $subscriber, string $reason): void
    {
        $row = $this->store->row($db, 'subscribers', $workspace, $subscriber);
        $priority = ['unsubscribed' => 1, 'bounced' => 2, 'complained' => 3];
        if (!isset($priority[$reason])) {
            throw new \InvalidArgumentException('Invalid suppression reason.');
        }
        $existing = $db
            ->table('suppressions')
            ->where('workspace_id', $workspace)
            ->where('email', $row['email'])
            ->first();
        $alreadyRecorded = $db
            ->table('consents')
            ->where('workspace_id', $workspace)
            ->where('subscriber_id', $subscriber)
            ->where('kind', $reason)
            ->exists();
        if (!$existing) {
            $db->table('suppressions')->insert([
                'workspace_id' => $workspace,
                'email' => $row['email'],
                'reason' => $reason,
                'created_at' => $this->store->now(),
            ]);
        } elseif ($priority[$reason] > ($priority[$existing['reason']] ?? 0)) {
            $db->table('suppressions')->where('id', $existing['id'])->update(['reason' => $reason]);
        }
        if ($priority[$reason] >= ($priority[$row['status']] ?? 0)) {
            $db
                ->table('subscribers')
                ->where('id', $subscriber)
                ->update(['status' => $reason, 'updated_at' => $this->store->now()]);
        }
        if (!$alreadyRecorded) {
            $this->consent(
                $db,
                $workspace,
                $subscriber,
                $reason,
                'delivery_feedback',
                'Suppression is persistent and cannot be cleared by import.',
                $this->store->now(),
            );
        }
    }

    public function consent(
        Connection $db,
        int $workspace,
        int $subscriber,
        string $kind,
        string $source,
        string $evidence,
        string $at,
    ): void {
        $db->table('consents')->insert([
            'workspace_id' => $workspace,
            'subscriber_id' => $subscriber,
            'kind' => $kind,
            'source' => $source,
            'evidence' => $evidence,
            'occurred_at' => $at,
            'recorded_at' => $this->store->now(),
        ]);
    }
}
