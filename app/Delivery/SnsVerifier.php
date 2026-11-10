<?php

declare(strict_types=1);

namespace Mailcrab\Delivery;

use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Crab\Http\HttpException;
use Mailcrab\Store;

final readonly class SnsVerifier
{
    public function __construct(
        private AwsConnection $aws,
        private SnsCertificate $certificates,
        private Store $store,
        private \Crab\Contracts\Cache\Cache $cache,
    ) {}

    public function verify(string $body): array
    {
        if (strlen($body) > 262144) {
            throw new HttpException(413, 'SNS payload too large.');
        }
        try {
            $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($input)) {
                throw new \RuntimeException('Not an object.');
            }
            $message = new Message($input);
            foreach ([
                'Message',
                'MessageId',
                'Timestamp',
                'TopicArn',
                'Type',
                'SigningCertURL',
                'Signature',
                'SignatureVersion',
            ] as $key) {
                if (!isset($input[$key]) || !is_string($input[$key])) {
                    throw new \RuntimeException('Invalid field.');
                }
            }
            if ($input['TopicArn'] !== $this->aws->topic()) {
                throw new \RuntimeException('Topic not allowlisted.');
            }
            $host = 'sns.' . $this->aws->region() . '.amazonaws.com';
            if (!preg_match(
                '~^https://' . preg_quote($host, '~') . '/SimpleNotificationService-[A-Za-z0-9_-]+\.pem$~D',
                $input['SigningCertURL'],
            )) {
                throw new \RuntimeException('Certificate URL not allowlisted.');
            }
            $timestamp = new \DateTimeImmutable($input['Timestamp']);
            $age = $this->store->clock->now()->getTimestamp() - $timestamp->getTimestamp();
            if ($age < -300 || $age > 2592000) {
                throw new \RuntimeException('Expired SNS message.');
            }
            $validator = new MessageValidator(
                fn(string $url): string => $this->cache->remember(
                    'sns-cert-' . hash('sha256', $url),
                    3600,
                    fn(): string => $this->certificates->fetch($url),
                ),
                '/^' . preg_quote($host, '/') . '$/D',
            );
            $validator->validate($message);
            return $input;
        } catch (SnsCertificateUnavailable) {
            throw new HttpException(503, 'SNS certificate temporarily unavailable. Retry this notification.');
        } catch (\Throwable) {
            throw new HttpException(401, 'Invalid SNS signature, topic, timestamp, or certificate URL.');
        }
    }
}
