<?php

declare(strict_types=1);

namespace Mailcrab;

use Crab\Contracts\Database\Connection;
use Crab\Http\HttpException;

final readonly class Feedback
{
    public function __construct(
        private Store $store,
        private Audience $audience,
    ) {}

    public function fake(string $body, string $timestamp, string $signature): void
    {
        $secret = (string) config('mailcrab.webhook_secret');
        if (strlen($secret) < 32) {
            throw new HttpException(503, 'Configure FAKE_WEBHOOK_SECRET (32+ bytes).');
        }
        if (
            !ctype_digit($timestamp)
            || abs($this->store->clock->now()->getTimestamp() - (int) $timestamp) > 300
            || !hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $signature)
        ) {
            throw new HttpException(401, 'Invalid feedback signature.');
        }
        try {
            $input = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'Invalid feedback JSON.');
        }
        if (!is_array($input)) {
            throw new HttpException(422, 'Invalid feedback.');
        }
        $key = Store::text($input, 'event_id', 120);
        $provider = Store::text($input, 'provider_id', 190);
        $kind = $input['kind'] ?? null;
        if (!in_array($kind, ['delivered', 'bounce', 'complaint'], true)) {
            throw new HttpException(422, 'Unknown feedback kind.');
        }
        $delivery = $this->store
            ->db
            ->table('deliveries')
            ->where('transport', 'fake')
            ->where('provider_id', $provider)
            ->first() ?? throw new HttpException(404, 'Delivery not found; retry feedback after provider acceptance is committed.');
        $this->event($delivery, 'fake:' . $key, $kind);
    }

    public function event(array $delivery, string $key, string $kind, ?Connection $connection = null): void
    {
        $apply = function (Connection $db) use ($delivery, $key, $kind): void {
            $wid = (int) $delivery['workspace_id'];
            $this->store->lock($db, $wid);
            if ($db->table('delivery_events')->where('event_key', $key)->exists()) {
                return;
            }
            $db->table('delivery_events')->insert([
                'workspace_id' => $wid,
                'delivery_id' => $delivery['id'],
                'event_key' => $key,
                'kind' => $kind,
                'created_at' => $this->store->now(),
            ]);
            $current = $this->store->row($db, 'deliveries', $wid, (int) $delivery['id']);
            if ($kind === 'delivered') {
                $db->table('deliveries')->where('id', $delivery['id'])->update(['delivered_at' => $this->store->now()]);
                if (!in_array($current['status'], ['bounced', 'complained'], true)) {
                    $db->table('deliveries')->where('id', $delivery['id'])->update(['status' => 'delivered']);
                }
            } elseif ($kind === 'rejected') {
                if (!in_array($current['status'], ['delivered', 'bounced', 'complained'], true)) {
                    $db
                        ->table('deliveries')
                        ->where('id', $delivery['id'])
                        ->update([
                            'status' => 'failed',
                            'last_error' => 'SES rejected this message. It will not be resent automatically.',
                        ]);
                }
            } elseif ($kind === 'unsubscribe' && $delivery['subscriber_id'] !== null) {
                if (!$this->audience->suppressed($db, $wid, $delivery['recipient'])) {
                    $this->audience->suppress($db, $wid, (int) $delivery['subscriber_id'], 'unsubscribed');
                }
            } elseif (in_array($kind, ['bounce', 'complaint'], true)) {
                $reason = $kind === 'bounce' ? 'bounced' : 'complained';
                if ($current['status'] !== 'complained') {
                    $db->table('deliveries')->where('id', $delivery['id'])->update(['status' => $reason]);
                }
                $subscriber = $db
                    ->table('subscribers')
                    ->where('workspace_id', $wid)
                    ->where('email', $delivery['recipient'])
                    ->first();
                if ($subscriber) {
                    $this->audience->suppress($db, $wid, (int) $subscriber['id'], $reason);
                } else {
                    $db->table('suppressions')->insertOrIgnore([
                        'workspace_id' => $wid,
                        'email' => $delivery['recipient'],
                        'reason' => $reason,
                        'created_at' => $this->store->now(),
                    ]);
                }
            }
        };
        if ($connection !== null) {
            $apply($connection);
        } else {
            $this->store->db->transaction($apply);
        }
    }

    public function unsubscribe(#[\SensitiveParameter] string $token): void
    {
        $delivery = $this->store
            ->db
            ->table('deliveries')
            ->where('token', $token)
            ->whereIn('kind', ['campaign', 'welcome'])
            ->first();
        if ($delivery && $delivery['subscriber_id'] !== null) {
            $this->event($delivery, 'unsubscribe:' . $delivery['id'], 'unsubscribe');
        }
    }

    public function click(#[\SensitiveParameter] string $token): string
    {
        $link = $this->store->db->table('links')->where('token', $token)->first() ?? throw new HttpException(
            404,
            'Link not found.',
        );
        $delivery = $this->store->db->table('deliveries')->where('id', $link['delivery_id'])->first();
        $this->event($delivery, 'click:' . $link['id'], 'click');
        return $link['url'];
    }
}
