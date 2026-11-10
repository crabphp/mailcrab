<?php

declare(strict_types=1);

namespace Mailcrab;

use Crab\Contracts\Database\Connection;
use Mailcrab\Delivery\Provider;
use Mailcrab\Delivery\Retryable;

final readonly class Worker
{
    public function __construct(
        private Store $store,
        private Campaigns $campaigns,
        private Audience $audience,
        private Renderer $renderer,
        private Provider $provider,
        private \Mailcrab\Delivery\SesEvents $sesEvents,
    ) {}

    public function tick(int $batch = 50): array
    {
        if (config('mailcrab.provider') !== 'fake') {
            throw new \LogicException('Real delivery is locked. Only the fake provider is enabled.');
        }
        $batch = max(1, min(100, $batch));
        $this->sesEvents->process($batch);
        $recovered = $this->recover($batch);
        $enqueued = $this->campaigns->expand($batch);
        $processed = 0;
        $rows = $this->store
            ->db
            ->table('deliveries')
            ->where('status', 'queued')
            ->where('available_at', '<=', $this->store->now())
            ->orderBy('id')
            ->limit($batch)
            ->get();
        foreach ($rows as $candidate) {
            $delivery = $this->claim((int) $candidate['workspace_id'], (int) $candidate['id']);
            if (!$delivery) {
                continue;
            }
            ++$processed;
            try {
                $message = $this->renderer->render($delivery);
                $id = $this->provider->send($delivery, $message);
                $this->store
                    ->db
                    ->table('deliveries')
                    ->where('id', $delivery['id'])
                    ->where('status', 'sending')
                    ->update([
                        'status' => 'accepted',
                        'provider_id' => $id,
                        'accepted_at' => $this->store->now(),
                        'last_error' => null,
                    ]);
            } catch (\Mailcrab\Delivery\Deferred $error) {
                $this->store
                    ->db
                    ->table('deliveries')
                    ->where('id', $delivery['id'])
                    ->where('status', 'sending')
                    ->update([
                        'status' => 'queued',
                        'attempts' => max(0, (int) $delivery['attempts'] - 1),
                        'available_at' => $this->store->after(max(1, $error->delay)),
                        'last_error' => $error->getMessage(),
                    ]);
            } catch (\Throwable $error) {
                $safeRetry = $error instanceof Retryable || $this->idempotent($delivery);
                $retry = $safeRetry && (int) $delivery['attempts'] < 3;
                $this->store
                    ->db
                    ->table('deliveries')
                    ->where('id', $delivery['id'])
                    ->where('status', 'sending')
                    ->update([
                        'status' => $retry ? 'queued' : ($safeRetry ? 'failed' : 'unknown'),
                        'available_at' => $this->store->after(30 * (2 ** ((int) $delivery['attempts'] - 1))),
                        'last_error' => $safeRetry
                            ? 'Provider did not accept this attempt; bounded retry policy applied.'
                            : 'Outcome unknown. Do not retry; reconcile with provider.',
                    ]);
            }
        }
        $this->finish();
        return compact('enqueued', 'processed', 'recovered');
    }

    private function claim(int $workspace, int $id): ?array
    {
        return $this->store->db->transaction(function (Connection $db) use ($workspace, $id): ?array {
            $this->store->lock($db, $workspace);
            $row = $this->store->row($db, 'deliveries', $workspace, $id);
            if ($row['status'] !== 'queued' || $row['available_at'] > $this->store->now()) {
                return null;
            }
            $allowed = !$this->audience->suppressed($db, $workspace, $row['recipient']);
            if ($row['sender_id'] !== null) {
                $sender = $this->store->row($db, 'senders', $workspace, (int) $row['sender_id']);
                $allowed = $allowed && $sender['verified_at'] !== null;
            }
            if ($row['kind'] === 'confirmation' && $row['subscriber_id'] !== null) {
                $subscriber = $this->store->row($db, 'subscribers', $workspace, (int) $row['subscriber_id']);
                $allowed =
                    $allowed
                    && $subscriber['status'] === 'pending'
                    && !$this->audience->suppressed($db, $workspace, $row['recipient']);
            }
            if ($row['kind'] !== 'confirmation') {
                $allowed = $allowed && !$this->audience->suppressed($db, $workspace, $row['recipient']);
                if ($row['subscriber_id'] !== null) {
                    $subscriber = $this->store->row($db, 'subscribers', $workspace, (int) $row['subscriber_id']);
                    $allowed =
                        $allowed
                        && $subscriber['status'] === 'active'
                        && $subscriber['confirmed_at'] !== null
                        && (
                            $row['transport'] !== 'ses'
                            || in_array($subscriber['consent_transport'], ['ses', 'recorded'], true)
                        );
                }
                if ($row['kind'] === 'welcome') {
                    $sequence = $db->table('welcome_sequences')->where('workspace_id', $workspace)->first();
                    $allowed = $allowed && $sequence && (bool) $sequence['enabled'];
                }
            }
            if ($row['campaign_id'] !== null) {
                $campaign = $this->store->row($db, 'campaigns', $workspace, (int) $row['campaign_id']);
                $allowed = $allowed && $campaign['status'] !== 'cancelled';
            }
            if (!$allowed) {
                $db->table('deliveries')->where('id', $id)->update(['status' => 'suppressed']);
                return null;
            }
            $db
                ->table('deliveries')
                ->where('id', $id)
                ->update([
                    'status' => 'sending',
                    'attempts' => (int) $row['attempts'] + 1,
                    'claimed_at' => $this->store->now(),
                ]);
            return $this->store->row($db, 'deliveries', $workspace, $id);
        });
    }

    private function recover(int $batch): int
    {
        $rows = $this->store
            ->db
            ->table('deliveries')
            ->where('status', 'sending')
            ->where('claimed_at', '<', $this->store->after(-300))
            ->orderBy('id')
            ->limit($batch)
            ->get();
        $count = 0;
        foreach ($rows as $row) {
            $retry = $this->idempotent($row) && (int) $row['attempts'] < 3;
            $count += $this->store
                ->db
                ->table('deliveries')
                ->where('id', $row['id'])
                ->where('status', 'sending')
                ->where('claimed_at', $row['claimed_at'])
                ->update([
                    'status' => $retry ? 'queued' : 'unknown',
                    'available_at' => $this->store->now(),
                    'last_error' => $retry
                        ? 'Recovered stale claim using provider idempotency.'
                        : 'Stale in-flight claim. Reconcile manually; never blindly retry.',
                ]);
        }
        return $count;
    }

    private function idempotent(array $delivery): bool
    {
        return $this->provider instanceof \Mailcrab\Delivery\DeliveryRouter
            ? $this->provider->idempotentFor($delivery)
            : $this->provider->idempotent();
    }

    private function finish(): void
    {
        $rows = $this->store
            ->db
            ->table('campaigns')
            ->where('status', 'sending')
            ->orderBy('id')
            ->limit(10)
            ->get();
        foreach ($rows as $row) {
            $this->store->db->transaction(function (Connection $db) use ($row): void {
                $this->store->lock($db, (int) $row['workspace_id']);
                if (!$db
                    ->table('deliveries')
                    ->where('campaign_id', $row['id'])
                    ->whereIn('status', ['queued', 'sending'])
                    ->exists()) {
                    $db
                        ->table('campaigns')
                        ->where('id', $row['id'])
                        ->where('status', 'sending')
                        ->update(['status' => 'finished', 'finished_at' => $this->store->now()]);
                }
            });
        }
    }
}
