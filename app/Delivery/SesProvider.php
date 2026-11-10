<?php

declare(strict_types=1);

namespace Mailcrab\Delivery;

use Aws\Exception\AwsException;
use Aws\SesV2\SesV2Client;

/** Only called by DeliveryRouter after operator authorization, readiness and quota/rate admission. */
final readonly class SesProvider implements Provider
{
    public function __construct(
        private SesV2Client $client,
        private string $configurationSet,
        private bool $explicitlyAuthorized = false,
    ) {}

    public function idempotent(): bool
    {
        return false;
    }

    public function send(array $delivery, array $message): string
    {
        return $this->sendPrepared($this->prepare($delivery, $message));
    }

    // All identity network checks and MIME preparation happen before rate/quota admission.
    public function prepare(array $delivery, array $message): array
    {
        if (!$this->explicitlyAuthorized) {
            throw new \LogicException(
                'Real SES sending requires explicit user authorization and reviewed runtime wiring.',
            );
        }
        if ($this->configurationSet === '') {
            throw new \LogicException('A feedback-enabled SES configuration set is required.');
        }
        // Never let SDK-level retries turn an ambiguous SendEmail outcome into a duplicate.
        $this->client->getHandlerList()->remove('retry');
        $domain = substr($delivery['from_email'], strrpos($delivery['from_email'], '@') + 1);
        try {
            $identity = $this->client->getEmailIdentity(['EmailIdentity' => $domain]);
        } catch (\Throwable) {
            throw new Deferred('SES sender identity could not be checked. No send was attempted.');
        }
        $dkim = $identity->get('DkimAttributes') ?? [];
        $mailFrom = $identity->get('MailFromAttributes') ?? [];
        if (
            !$identity->get('VerifiedForSendingStatus')
            || !($dkim['SigningEnabled'] ?? false)
            || ($dkim['Status'] ?? '') !== 'SUCCESS'
            || ($mailFrom['MailFromDomainStatus'] ?? '') !== 'SUCCESS'
            || ($mailFrom['BehaviorOnMxFailure'] ?? '') !== 'REJECT_MESSAGE'
        ) {
            throw new Deferred('SES identity, DKIM, or MAIL FROM is no longer ready.');
        }
        $boundary = 'mc-' . bin2hex(random_bytes(16));
        $header = static fn(string $value): string => mb_encode_mimeheader(
            str_replace(["\r", "\n"], '', $value),
            'UTF-8',
            'B',
            "\r\n",
        );
        $raw =
            'From: '
            . $header($delivery['from_name'])
            . ' <'
            . $delivery['from_email']
            . ">\r\nTo: "
            . $delivery['recipient']
            . "\r\nSubject: "
            . $header($delivery['subject'])
            . "\r\nMIME-Version: 1.0\r\n";
        if (!empty($delivery['reply_to'])) {
            $reply = \Mailcrab\Store::email($delivery['reply_to']);
            $raw .= 'Reply-To: ' . $reply . "\r\n";
        }
        foreach ($message['headers'] as $name => $value) {
            $raw .= $name . ': ' . $value . "\r\n";
        }
        $raw .= 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n\r\n";
        foreach (['text' => 'text/plain', 'html' => 'text/html'] as $key => $type) {
            $raw .=
                '--'
                . $boundary
                . "\r\nContent-Type: {$type}; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($message[$key]))
                . "\r\n";
        }
        $raw .= '--' . $boundary . "--\r\n";
        return [
            'FromEmailAddress' => $delivery['from_email'],
            'Destination' => ['ToAddresses' => [$delivery['recipient']]],
            'ConfigurationSetName' => $this->configurationSet,
            'Content' => ['Raw' => ['Data' => $raw]],
            'EmailTags' => [
                ['Name' => 'mailcrab_delivery', 'Value' => (string) $delivery['id']],
                ['Name' => 'mailcrab_workspace', 'Value' => (string) ($delivery['workspace_id'] ?? '')],
            ],
        ];
    }

    public function sendPrepared(array $command): string
    {
        if (!$this->explicitlyAuthorized) {
            throw new \LogicException('Explicit SES authorization is required.');
        }
        try {
            $result = $this->client->sendEmail($command);
            $id = (string) $result->get('MessageId');
            if ($id === '') {
                throw new \RuntimeException('SES acceptance ID is missing; outcome unknown.');
            }
            return $id;
        } catch (AwsException $error) {
            if (in_array(
                $error->getAwsErrorCode(),
                ['TooManyRequestsException', 'Throttling', 'LimitExceededException'],
                true,
            )) {
                throw new Retryable('SES explicitly rejected this attempt before acceptance.', previous: $error);
            }
            // SES has no send idempotency key: unknown outcome must never be blindly retried.
            throw $error;
        }
    }
}
