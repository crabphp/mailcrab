<?php

declare(strict_types=1);

namespace Mailcrab\Delivery;

use Crab\Contracts\Database\Connection;
use Crab\Database\ManagedConnection;
use Mailcrab\Store;

final readonly class SesGuard
{
    public function __construct(
        private Store $store,
        private AwsConnection $aws,
        private DeliverySettings $settings,
        private DeliveryMode $mode,
    ) {}

    private function assertReady(array $delivery, ?Connection $db = null): array
    {
        $workspace = (int) $delivery['workspace_id'];
        if ($this->mode->forWorkspace($workspace, $db) !== 'ses') {
            throw new Deferred('Live SES delivery is not authorized for this workspace.');
        }
        $view = $this->settings->overview($workspace, $db);
        if ($view['paused']) {
            throw new Deferred('SES sending is paused.');
        }
        if (!$view['ready']) {
            throw new Deferred('SES readiness checks are incomplete or expired. Review Email delivery settings.');
        }
        $sender = array_filter(
            $view['senders'],
            static fn($s): bool => (
                (string) $s['id'] === (string) $delivery['sender_id']
                && $s['email'] === $delivery['from_email']
                && $s['ses_ready']
            ),
        );
        if (!$sender) {
            throw new Deferred('This delivery’s sender or domain is no longer ready.');
        }
        return $view;
    }

    public function preflight(array $delivery): array
    {
        $this->assertReady($delivery);
        try {
            $account = $this->aws->ses()->getAccount()->toArray();
        } catch (\Throwable) {
            throw new Deferred('Could not refresh SES account quota. No send was attempted.');
        }
        if (
            !($account['ProductionAccessEnabled'] ?? false)
            || !($account['SendingEnabled'] ?? false)
            || ($account['EnforcementStatus'] ?? '') !== 'HEALTHY'
        ) {
            throw new Deferred('SES production access or account sending is unavailable.');
        }
        $quota = $account['SendQuota'] ?? [];
        if (($quota['MaxSendRate'] ?? 0) <= 0 || ($quota['Max24HourSend'] ?? 0) <= ($quota['SentLast24Hours'] ?? 0)) {
            throw new Deferred('SES quota is exhausted.');
        }
        return [
            'quota' => $quota,
            'account_key' => hash('sha256', implode(':', array_slice(explode(':', $this->aws->topic()), 0, 5))),
            'configuration_set' => $this->aws->configurationSet((int) $delivery['workspace_id']),
        ];
    }

    /** Identity checks and MIME preparation must finish before calling this method.
     * The dispatch mutex spans committed admission AND the external call, never an uncommitted send.
     */
    public function dispatch(array $delivery, array $context, \Closure $send): string
    {
        $started = false;
        $work = function (Connection $db) use ($delivery, $context, $send, &$started): string {
            $key = $context['account_key'];
            $file = null;
            $mysql = $db->driver() === 'mysql';
            try {
                if ($mysql) {
                    if ((int) ($db->first('SELECT GET_LOCK(?, 0) AS acquired', [$key])['acquired'] ?? 0) !== 1) {
                        throw new Deferred('Another SES dispatch is in progress.', 1);
                    }
                } else {
                    $dir = dirname(__DIR__, 2) . '/storage/locks';
                    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                        throw new Deferred('Could not create the dispatch lock directory.');
                    }
                    $file = @fopen($dir . '/ses-' . $key . '.lock', 'c');
                    if ($file === false || !flock($file, LOCK_EX | LOCK_NB)) {
                        if (is_resource($file)) {
                            fclose($file);
                        }
                        throw new Deferred('Another SES dispatch is in progress.', 1);
                    }
                }
            } catch (Deferred $error) {
                throw $error;
            } catch (\Throwable) {
                throw new Deferred('Could not acquire the SES dispatch lock. No send was attempted.');
            }
            $failure = null;
            try {
                try {
                    $rate = $db->transaction(function (Connection $db) use ($delivery, $context, $key): float {
                        $workspace = (int) $delivery['workspace_id'];
                        $this->store->lock($db, $workspace);
                        // The account mutex is already held before any consistent read: no stale
                        // REPEATABLE READ snapshot can omit another workspace’s committed reservation.
                        $view = $this->assertReady($delivery, $db);
                        $row = $this->store->row($db, 'deliveries', $workspace, (int) $delivery['id']);
                        if (
                            $row['status'] !== 'sending'
                            || $db
                                ->table('suppressions')
                                ->where('workspace_id', $workspace)
                                ->where('email', $row['recipient'])
                                ->exists()
                        ) {
                            throw new Deferred('Delivery is no longer eligible.');
                        }
                        if (
                            $row['campaign_id'] !== null
                            && $this->store->row($db, 'campaigns', $workspace, (int) $row['campaign_id'])['status']
                                === 'cancelled'
                        ) {
                            throw new Deferred('Campaign was cancelled.');
                        }
                        if ($row['subscriber_id'] !== null && $row['kind'] !== 'confirmation') {
                            $subscriber = $this->store->row(
                                $db,
                                'subscribers',
                                $workspace,
                                (int) $row['subscriber_id'],
                            );
                            if (
                                $subscriber['status'] !== 'active'
                                || !in_array($subscriber['consent_transport'], ['ses', 'recorded'], true)
                            ) {
                                throw new Deferred('Recipient needs valid live consent.');
                            }
                        }
                        if ($row['kind'] === 'welcome') {
                            $sequence = $db->table('welcome_sequences')->where('workspace_id', $workspace)->first();
                            if (!$sequence || !$sequence['enabled']) {
                                throw new Deferred('Welcome sequence was disabled.');
                            }
                        }
                        $quota = $context['quota'];
                        $rate = min((float) $quota['MaxSendRate'], (int) $view['rate_per_second'], 100);
                        $db->table('ses_rate_limits')->insertOrIgnore(['account_key' => $key]);
                        $limit = $db->table('ses_rate_limits')->where('account_key', $key)->lockForUpdate()->first();
                        $now = (int) $this->store->clock->now()->format('Uu');
                        if ((int) $limit['next_available_us'] > $now) {
                            throw new Deferred('Waiting for the shared SES sending-rate slot.', 1);
                        }
                        // Conservative: include every reserved attempt, even if AWS already counted it.
                        $reserved = $db
                            ->table('ses_send_reservations')
                            ->where('account_key', $key)
                            ->where('created_at', '>', $this->store->after(-86400))
                            ->count();
                        if (((float) $quota['SentLast24Hours'] + $reserved) >= (float) $quota['Max24HourSend']) {
                            throw new Deferred('Conservative shared SES daily quota guard is full.');
                        }
                        $db
                            ->table('ses_rate_limits')
                            ->where('account_key', $key)
                            ->update(['next_available_us' => $now + (int) ceil(1000000 / $rate)]);
                        $db->table('ses_send_reservations')->insert([
                            'account_key' => $key,
                            'workspace_id' => $workspace,
                            'delivery_id' => $delivery['id'],
                            'created_at' => $this->store->now(),
                        ]);
                        return $rate;
                    });
                } catch (Deferred $error) {
                    throw $error;
                } catch (\Throwable) {
                    throw new Deferred('SES admission could not complete. No send was attempted.');
                }
                // No preparation/network work remains between durable admission and SendEmail.
                $started = true;
                try {
                    return $send();
                } finally {
                    $db
                        ->table('ses_rate_limits')
                        ->where('account_key', $key)
                        ->update([
                            'next_available_us' =>
                                (int) $this->store->clock->now()->format('Uu') + (int) ceil(1000000 / $rate),
                        ]);
                }
            } catch (\Throwable $error) {
                $failure = $error;
                throw $error;
            } finally {
                try {
                    if ($mysql && $db instanceof ManagedConnection && $db->isOpen()) {
                        $db->first('SELECT RELEASE_LOCK(?) AS released', [$key]);
                    } elseif (is_resource($file)) {
                        flock($file, LOCK_UN);
                        fclose($file);
                    }
                } catch (\Throwable $error) {
                    if ($failure === null) {
                        throw $error;
                    }
                }
            }
        };
        try {
            return $this->store->db->run(static function (Connection $db) use ($work): string {
                if ($db->driver() !== 'mysql') {
                    return $work($db);
                }
                if (!$db instanceof ManagedConnection) {
                    throw new Deferred('SES dispatch requires a pinned MySQL session.');
                }
                // Advisory locks belong to a physical session, not merely a pooled connection object.
                return $db->withoutReconnect($work);
            });
        } catch (\Throwable $error) {
            if (!$started && !$error instanceof Deferred) {
                throw new Deferred('SES dispatch session was lost before sending. No send was attempted.');
            }
            throw $error;
        }
    }
}
