<?php

declare(strict_types=1);

namespace Mailcrab\Delivery;

use Aws\Exception\AwsException;
use Crab\Contracts\Database\Connection;
use Crab\Http\HttpException;
use Mailcrab\Store;

final readonly class DeliverySettings
{
    public function __construct(
        private Store $store,
        private AwsConnection $aws,
        private DeliveryMode $mode,
    ) {}

    public function get(int $workspace, ?Connection $connection = null): array
    {
        $read = function (Connection $db) use ($workspace): array {
            $this->store->lock($db, $workspace);
            $db->table('delivery_settings')->insertOrIgnore([
                'workspace_id' => $workspace,
                'region' => $this->aws->region(),
                'created_at' => $this->store->now(),
                'updated_at' => $this->store->now(),
            ]);
            return $db->table('delivery_settings')->where('workspace_id', $workspace)->first();
        };
        return $connection ? $read($connection) : $this->store->db->transaction($read);
    }

    public function update(int $workspace, array $values): void
    {
        $this->store->db->transaction(function (Connection $db) use ($workspace, $values): void {
            $this->get($workspace, $db);
            $db
                ->table('delivery_settings')
                ->where('workspace_id', $workspace)
                ->update($values + ['updated_at' => $this->store->now()]);
        });
    }

    public function assertRegion(int $workspace): void
    {
        if ($this->get($workspace)['region'] !== $this->aws->region()) {
            throw new HttpException(
                409,
                'AWS_REGION changed. Restore the workspace’s original region; identities are region-specific.',
            );
        }
    }

    public function checkConnection(int $workspace): void
    {
        $this->assertRegion($workspace);
        try {
            $account = $this->aws->ses()->getAccount()->toArray();
            $quota = $account['SendQuota'] ?? [];
            $safe = [
                'production' => (bool) ($account['ProductionAccessEnabled'] ?? false),
                'sending_enabled' => (bool) ($account['SendingEnabled'] ?? false),
                'enforcement' => $account['EnforcementStatus'] ?? 'UNKNOWN',
                'daily_limit' => (float) ($quota['Max24HourSend'] ?? 0),
                'sent_last_24h' => (float) ($quota['SentLast24Hours'] ?? 0),
                'max_send_rate' => (float) ($quota['MaxSendRate'] ?? 0),
            ];
            $this->update($workspace, [
                'account' => json_encode($safe, JSON_THROW_ON_ERROR),
                'account_checked_at' => $this->store->now(),
                'account_error' => null,
            ]);
        } catch (\Throwable $error) {
            $message = self::error($error);
            $this->update($workspace, [
                'account' => null,
                'account_error' => $message,
                'account_checked_at' => $this->store->now(),
            ]);
            throw new HttpException(422, $message);
        }
    }

    public static function error(\Throwable $error): string
    {
        if ($error instanceof HttpException) {
            return $error->getMessage();
        }
        if ($error instanceof AwsException) {
            return match ($error->getAwsErrorCode()) {
                'AccessDenied',
                'AccessDeniedException',
                'AuthorizationError',
                    => 'AWS denied this action. Ask the operator to check the Mailcrab IAM policy.',
                'NotFoundException',
                'NotFound',
                'ResourceNotFoundException',
                    => 'AWS could not find this resource in the configured region.',
                'AlreadyExistsException' => 'This AWS resource already exists. Refresh its status before retrying.',
                'TooManyRequestsException',
                'Throttling',
                'ThrottlingException',
                    => 'AWS is rate limiting setup checks. Wait a minute and retry.',
                default => 'AWS could not complete this check. Verify credentials, region, resource permissions, and network access.',
            };
        }
        return 'Could not reach AWS. Configure the server credential chain (IAM role or AWS_PROFILE) and check network access.';
    }

    public function preferences(int $workspace, array $input): void
    {
        $this->get($workspace);
        $this->store->db->transaction(function (Connection $db) use ($workspace, $input): void {
            $current = $this->get($workspace, $db);
            $rate = Store::id($input['rate_per_second'] ?? $current['rate_per_second'], 'rate_per_second');
            if ($rate > 100) {
                throw new HttpException(422, 'Choose a sending limit between 1 and 100 messages per second.');
            }
            if (array_key_exists('paused', $input) && !is_bool($input['paused'])) {
                throw new HttpException(422, 'paused must be a boolean.');
            }
            $sender = isset($input['default_sender_id'])
                ? Store::id($input['default_sender_id'], 'default_sender_id')
                : (array_key_exists('default_sender_id', $input) ? null : $current['default_sender_id']);
            if ($sender !== null) {
                $this->store->row($db, 'senders', $workspace, $sender);
            }
            if (array_key_exists('postal_address', $input)) {
                $address = Store::text($input, 'postal_address', 500);
                $db
                    ->table('workspaces')
                    ->where('id', $workspace)
                    ->update(['postal_address' => $address, 'updated_at' => $this->store->now()]);
            }
            $db
                ->table('delivery_settings')
                ->where('workspace_id', $workspace)
                ->update([
                    'rate_per_second' => $rate,
                    'paused' => $input['paused'] ?? (bool) $current['paused'],
                    'default_sender_id' => $sender,
                    'updated_at' => $this->store->now(),
                ]);
        });
    }

    public function overview(int $workspace, ?Connection $connection = null): array
    {
        $db = $connection ?? $this->store->db;
        $settings = $this->get($workspace, $connection);
        $account = $settings['account'] ? json_decode($settings['account'], true, 32, JSON_THROW_ON_ERROR) : null;
        $feedback = $settings['feedback'] ? json_decode($settings['feedback'], true, 32, JSON_THROW_ON_ERROR) : null;
        $domains = $db->table('sending_domains')->where('workspace_id', $workspace)->orderBy('id')->limit(20)->get();
        foreach ($domains as &$domain) {
            $domain['dns'] = $domain['dns'] ? json_decode($domain['dns'], true, 32, JSON_THROW_ON_ERROR) : [];
            $domain['identity'] = $domain['identity']
                ? json_decode($domain['identity'], true, 32, JSON_THROW_ON_ERROR)
                : null;
            $domain['fresh'] = $domain['checked_at'] !== null && $domain['checked_at'] >= $this->store->after(-3600);
        }
        unset($domain);
        $senders = $db->table('senders')->where('workspace_id', $workspace)->orderBy('id')->limit(100)->get();
        foreach ($senders as &$sender) {
            $name = substr($sender['email'], strrpos($sender['email'], '@') + 1);
            $domain = array_values(array_filter($domains, static fn($d): bool => $d['domain'] === $name));
            $sender['domain_status'] = $domain[0]['status'] ?? 'not_connected';
            $sender['domain_fresh'] = $domain[0]['fresh'] ?? false;
            $sender['ses_ready'] =
                $sender['verified_at'] !== null
                && $sender['domain_status'] === 'verified'
                && ($domain[0]['fresh'] ?? false);
        }
        unset($sender);
        $fresh =
            $settings['account_checked_at'] !== null && $settings['account_checked_at'] >= $this->store->after(-3600);
        $feedbackFresh =
            $settings['feedback_checked_at'] !== null && $settings['feedback_checked_at'] >= $this->store->after(-3600);
        $default = array_values(array_filter(
            $senders,
            static fn($s): bool => (string) $s['id'] === (string) $settings['default_sender_id'],
        ));
        $address = $db->table('workspaces')->where('id', $workspace)->first()['postal_address'];
        $checks = [
            [
                'id' => 'connection',
                'label' => 'SES connection',
                'ok' =>
                    config('ses.enabled') === true
                        && $fresh
                        && $account !== null
                        && $settings['region'] === $this->aws->region(),
                'reason' => !$account
                    ? 'Connect the operator-managed AWS account.'
                    : (!$fresh ? 'Connection check expired. Recheck SES.' : 'AWS region must match this workspace.'),
                'section' => 'ses-connection-title',
            ],
            [
                'id' => 'account',
                'label' => 'Production access and quota',
                'ok' =>
                    $fresh
                        && ($account['production'] ?? false)
                        && ($account['sending_enabled'] ?? false)
                        && ($account['enforcement'] ?? '') === 'HEALTHY'
                        && ($account['max_send_rate'] ?? 0) >= 1
                        && ($account['daily_limit'] ?? 0) > ($account['sent_last_24h'] ?? 0),
                'reason' => !$fresh
                    ? 'Recheck the AWS account.'
                    : (
                        !($account['production'] ?? false)
                            ? 'Request production access in the SES console.'
                            : 'Resolve AWS sending restrictions or exhausted quota.'
                    ),
                'section' => 'ses-connection-title',
            ],
            [
                'id' => 'domain',
                'label' => 'Authenticated sending domain',
                'ok' =>
                    count(array_filter($domains, static fn($d): bool => $d['status'] === 'verified' && $d['fresh']))
                        > 0,
                'reason' => 'Connect a domain and recheck its DNS and SES status.',
                'section' => 'domain-title',
            ],
            [
                'id' => 'sender',
                'label' => 'Default sender',
                'ok' => $default[0]['ses_ready'] ?? false,
                'reason' => 'Choose a confirmed sender on a recently verified domain.',
                'section' => 'sender-title',
            ],
            [
                'id' => 'address',
                'label' => 'Postal mailing address',
                'ok' => trim($address) !== '',
                'reason' => 'Add a valid postal mailing address below.',
                'section' => 'ready-title',
            ],
            [
                'id' => 'feedback',
                'label' => 'Delivery, bounce and complaint feedback',
                'ok' =>
                    $feedbackFresh
                        && ($feedback['ready'] ?? false)
                        && ($feedback['topic'] ?? '') === (string) config('ses.topic_arn')
                        && ($feedback['configuration_set'] ?? '') === $this->aws->configurationSet($workspace)
                        && ($feedback['endpoint'] ?? '')
                            === rtrim((string) config('app.url'), '/') . '/webhooks/ses/' . $workspace,
                'reason' => !$feedbackFresh
                    ? 'Connect or recheck feedback.'
                    : 'Confirm the HTTPS subscription, event publishing, and SES suppression.',
                'section' => 'feedback-title',
            ],
        ];
        $enabled = config('ses.enabled') === true;
        $ready = $enabled && !in_array(false, array_column($checks, 'ok'), true);
        return [
            'enabled' => config('ses.enabled') === true,
            'setup_enabled' => config('ses.setup_enabled') === true,
            'region' => $settings['region'],
            'region_changed' => $settings['region'] !== $this->aws->region(),
            'account' => $account,
            'account_checked_at' => $settings['account_checked_at'],
            'account_error' => $settings['account_error'],
            'feedback' => $feedback,
            'feedback_error' => $settings['feedback_error'],
            'feedback_checked_at' => $settings['feedback_checked_at'],
            'subscription_pending' => $settings['subscription_token'] !== null,
            'subscription_requested' => $settings['subscription_arn'] !== null,
            'topic_configured' => (string) config('ses.topic_arn') !== '',
            'connection_fresh' => $fresh,
            'feedback_fresh' => $feedbackFresh,
            'postal_address' => $address,
            'configuration_set' => $this->aws->configurationSet($workspace),
            'webhook_path' => '/webhooks/ses/' . $workspace,
            'webhook_url' => rtrim((string) config('app.url'), '/') . '/webhooks/ses/' . $workspace,
            'domains' => $domains,
            'senders' => $senders,
            'default_sender_id' => $settings['default_sender_id'],
            'paused' => (bool) $settings['paused'],
            'rate_per_second' => (int) $settings['rate_per_second'],
            'checks' => $checks,
            'ready' => $ready,
            'real_sending' => $this->mode->forWorkspace($workspace, $db) === 'ses',
            'live_authorized_at' => $settings['live_authorized_at'],
            'live_gate_enabled' => config('ses.live_enabled') === true,
        ];
    }
}
