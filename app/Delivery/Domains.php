<?php

declare(strict_types=1);

namespace Mailcrab\Delivery;

use Aws\Exception\AwsException;
use Crab\Contracts\Database\Connection;
use Crab\Http\HttpException;
use Mailcrab\Store;

final readonly class Domains
{
    public function __construct(
        private Store $store,
        private AwsConnection $aws,
        private DeliverySettings $settings,
        private DnsChecks $dns,
    ) {}

    public static function normalize(mixed $value): string
    {
        if (!is_string($value)) {
            throw new HttpException(422, 'Enter a domain, such as newsletter.example.com.');
        }
        $domain = strtolower(trim($value));
        if (
            strlen($domain) > 253
            || !str_contains($domain, '.')
            || filter_var($domain, FILTER_VALIDATE_IP)
            || !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || preg_match('/\.(localhost|local|invalid|test|example)$/D', $domain)
        ) {
            throw new HttpException(422, 'Enter a public domain only, without https://, an email address, or a path.');
        }
        return $domain;
    }

    public function add(int $workspace, array $input): array
    {
        $this->settings->assertRegion($workspace);
        $domain = self::normalize($input['domain'] ?? null);
        $mailFrom = self::normalize($input['mail_from'] ?? 'bounce.' . $domain);
        if (!str_ends_with($mailFrom, '.' . $domain)) {
            throw new HttpException(422, 'MAIL FROM must be a subdomain of the sending domain.');
        }
        return $this->store->db->transaction(function (Connection $db) use ($workspace, $domain, $mailFrom): array {
            $this->store->lock($db, $workspace);
            $existing = $db->table('sending_domains')->where('domain', $domain)->first();
            if ($existing) {
                if ((int) $existing['workspace_id'] !== $workspace) {
                    throw new HttpException(409, 'This domain cannot be connected to this workspace.');
                }
                return $existing;
            }
            if ($db->table('sending_domains')->where('workspace_id', $workspace)->count() >= 20) {
                throw new HttpException(422, 'At most 20 sending domains per workspace.');
            }
            $id = $db->table('sending_domains')->insert([
                'workspace_id' => $workspace,
                'domain' => $domain,
                'region' => $this->aws->region(),
                'mail_from' => $mailFrom,
                'created_at' => $this->store->now(),
                'updated_at' => $this->store->now(),
            ]);
            return $this->store->row($db, 'sending_domains', $workspace, (int) $id);
        });
    }

    public function connect(int $workspace, int $id, array $input): void
    {
        $row = $this->store->row($this->store->db, 'sending_domains', $workspace, $id);
        $this->settings->assertRegion($workspace);
        $this->aws->authorize($input);
        $this->store->db->table('sending_domains')->where('id', $id)->update(['status' => 'connecting']);
        try {
            $ses = $this->aws->ses();
            try {
                $identity = $ses->getEmailIdentity(['EmailIdentity' => $row['domain']])->toArray();
            } catch (AwsException $error) {
                if ($error->getAwsErrorCode() !== 'NotFoundException') {
                    throw $error;
                }
                try {
                    $ses->createEmailIdentity([
                        'EmailIdentity' => $row['domain'],
                        'DkimSigningAttributes' => ['NextSigningKeyLength' => 'RSA_2048_BIT'],
                        'Tags' => [['Key' => 'mailcrab_workspace', 'Value' => (string) $workspace]],
                    ]);
                } catch (AwsException $race) {
                    if ($race->getAwsErrorCode() !== 'AlreadyExistsException') {
                        throw $race;
                    }
                }
                $identity = $ses->getEmailIdentity(['EmailIdentity' => $row['domain']])->toArray();
            }
            $this->owns($identity, $workspace);
            $ses->putEmailIdentityMailFromAttributes([
                'EmailIdentity' => $row['domain'],
                'MailFromDomain' => $row['mail_from'],
                'BehaviorOnMxFailure' => 'REJECT_MESSAGE',
            ]);
            $this->refresh($workspace, $id);
        } catch (\Throwable $error) {
            $this->fail($row, $error);
        }
    }

    public function refresh(int $workspace, int $id): void
    {
        $row = $this->store->row($this->store->db, 'sending_domains', $workspace, $id);
        $this->settings->assertRegion($workspace);
        if ($row['status'] === 'disconnected') {
            throw new HttpException(409, 'Reconnect this domain before checking DNS.');
        }
        try {
            $identity = $this->aws->ses()->getEmailIdentity(['EmailIdentity' => $row['domain']])->toArray();
            $this->owns($identity, $workspace);
            $records = $this->dns->check($row['domain'], $row['mail_from'], $row['region'], $identity);
            $safe = [
                'verification' => $identity['VerificationStatus'] ?? 'PENDING',
                'dkim' => $identity['DkimAttributes']['Status'] ?? 'PENDING',
                'mail_from' => $identity['MailFromAttributes']['MailFromDomainStatus'] ?? 'PENDING',
            ];
            $ready =
                ($identity['VerifiedForSendingStatus'] ?? false)
                && ($identity['DkimAttributes']['SigningEnabled'] ?? false)
                && $safe['dkim'] === 'SUCCESS'
                && $safe['mail_from'] === 'SUCCESS'
                && ($identity['MailFromAttributes']['MailFromDomain'] ?? '') === $row['mail_from']
                && ($identity['MailFromAttributes']['BehaviorOnMxFailure'] ?? '') === 'REJECT_MESSAGE'
                && !array_filter($records, static fn($r): bool => $r['status'] !== 'verified');
            $this->store
                ->db
                ->table('sending_domains')
                ->where('id', $id)
                ->where('workspace_id', $workspace)
                ->update([
                    'status' => $ready ? 'verified' : 'pending',
                    'identity' => json_encode($safe, JSON_THROW_ON_ERROR),
                    'dns' => json_encode($records, JSON_THROW_ON_ERROR),
                    'checked_at' => $this->store->now(),
                    'last_error' => null,
                    'updated_at' => $this->store->now(),
                ]);
        } catch (\Throwable $error) {
            $this->fail($row, $error);
        }
    }

    public function disconnect(int $workspace, int $id): void
    {
        $this->store->db->transaction(function (Connection $db) use ($workspace, $id): void {
            $this->store->lock($db, $workspace);
            $row = $this->store->row($db, 'sending_domains', $workspace, $id);
            // Retain ownership reservation and audit trail; never delete a cloud identity from a browser action.
            $db
                ->table('sending_domains')
                ->where('id', $id)
                ->update(['status' => 'disconnected', 'checked_at' => null, 'updated_at' => $this->store->now()]);
        });
    }

    private function owns(array $identity, int $workspace): void
    {
        $tags = array_column($identity['Tags'] ?? [], 'Value', 'Key');
        if (($tags['mailcrab_workspace'] ?? null) !== (string) $workspace) {
            throw new HttpException(
                409,
                'This SES identity is not assigned to this workspace. Ask the operator to review ownership and add the mailcrab_workspace tag in AWS. Existing identities are never adopted automatically.',
            );
        }
    }

    private function fail(array $row, \Throwable $error): never
    {
        $message = DeliverySettings::error($error);
        $this->store
            ->db
            ->table('sending_domains')
            ->where('id', $row['id'])
            ->where('workspace_id', $row['workspace_id'])
            ->update([
                'status' => 'error',
                'last_error' => $message,
                'checked_at' => $this->store->now(),
                'updated_at' => $this->store->now(),
            ]);
        throw new HttpException(422, $message);
    }
}
