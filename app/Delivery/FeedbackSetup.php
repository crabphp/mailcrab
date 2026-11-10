<?php

declare(strict_types=1);

namespace Mailcrab\Delivery;

use Aws\Exception\AwsException;
use Crab\Http\HttpException;
use Mailcrab\Store;

final readonly class FeedbackSetup
{
    public function __construct(
        private Store $store,
        private AwsConnection $aws,
        private DeliverySettings $settings,
    ) {}

    public function connect(int $workspace, array $input): void
    {
        $this->settings->assertRegion($workspace);
        $this->aws->authorize($input);
        $topic = $this->aws->topic();
        $endpoint = $this->aws->endpoint($workspace);
        $name = $this->aws->configurationSet($workspace);
        try {
            $ses = $this->aws->ses();
            $sns = $this->aws->sns();
            // Validate the operator-supplied topic before changing any SES resources.
            $sns->getTopicAttributes(['TopicArn' => $topic]);
            try {
                $set = $ses->getConfigurationSet(['ConfigurationSetName' => $name])->toArray();
            } catch (AwsException $error) {
                if ($error->getAwsErrorCode() !== 'NotFoundException') {
                    throw $error;
                }
                try {
                    $ses->createConfigurationSet([
                        'ConfigurationSetName' => $name,
                        'SuppressionOptions' => ['SuppressedReasons' => ['BOUNCE', 'COMPLAINT']],
                        'Tags' => [['Key' => 'mailcrab_workspace', 'Value' => (string) $workspace]],
                    ]);
                } catch (AwsException $race) {
                    if ($race->getAwsErrorCode() !== 'AlreadyExistsException') {
                        throw $race;
                    }
                }
                $set = $ses->getConfigurationSet(['ConfigurationSetName' => $name])->toArray();
            }
            if (
                (array_column($set['Tags'] ?? [], 'Value', 'Key')['mailcrab_workspace'] ?? null) !== (string) $workspace
            ) {
                throw new HttpException(
                    409,
                    'The configuration set is not assigned to this workspace. Ask the operator to review its ownership.',
                );
            }
            // Repair existing owned sets too; reconnect must not loop forever on missing suppression.
            $ses->putConfigurationSetSuppressionOptions([
                'ConfigurationSetName' => $name,
                'SuppressedReasons' => ['BOUNCE', 'COMPLAINT'],
            ]);
            $destination = [
                'Enabled' => true,
                'MatchingEventTypes' => [
                    'SEND',
                    'DELIVERY',
                    'BOUNCE',
                    'COMPLAINT',
                    'REJECT',
                    'RENDERING_FAILURE',
                    'DELIVERY_DELAY',
                ],
                'SnsDestination' => ['TopicArn' => $topic],
            ];
            $destinations = $ses->getConfigurationSetEventDestinations(['ConfigurationSetName' => $name])->get(
                'EventDestinations',
            ) ?? [];
            $existing = array_values(array_filter(
                $destinations,
                static fn($d): bool => ($d['Name'] ?? '') === 'mailcrab-feedback',
            ));
            $args = [
                'ConfigurationSetName' => $name,
                'EventDestinationName' => 'mailcrab-feedback',
                'EventDestination' => $destination,
            ];
            if ($existing) {
                $ses->updateConfigurationSetEventDestination($args);
            } else {
                $ses->createConfigurationSetEventDestination($args);
            }
            $result = $sns->subscribe([
                'TopicArn' => $topic,
                'Protocol' => 'https',
                'Endpoint' => $endpoint,
                'ReturnSubscriptionArn' => true,
            ]);
            $arn = (string) $result->get('SubscriptionArn');
            $this->settings->update($workspace, [
                'subscription_arn' => $arn,
                'feedback' => null,
                'feedback_checked_at' => null,
                'feedback_error' => null,
            ]);
            if (str_starts_with($arn, $topic . ':')) {
                $this->check($workspace);
            }
        } catch (\Throwable $error) {
            $this->fail($workspace, $error);
        }
    }

    public function confirm(int $workspace, array $input): void
    {
        $this->aws->authorize($input);
        $this->settings->assertRegion($workspace);
        $row = $this->settings->get($workspace);
        if (!$row['subscription_token']) {
            throw new HttpException(
                409,
                'No signed SNS confirmation has arrived. Ensure the HTTPS webhook is publicly reachable, then retry Connect feedback.',
            );
        }
        try {
            // Use the SNS API with an exact allowlisted ARN/token; never fetch SubscribeURL.
            $result = $this->aws->sns()->confirmSubscription([
                'TopicArn' => $this->aws->topic(),
                'Token' => $row['subscription_token'],
                'AuthenticateOnUnsubscribe' => 'true',
            ]);
            $this->settings->update($workspace, [
                'subscription_arn' => (string) $result->get('SubscriptionArn'),
                'subscription_token' => null,
            ]);
            $this->check($workspace);
        } catch (\Throwable $error) {
            $this->fail($workspace, $error);
        }
    }

    public function check(int $workspace): void
    {
        $this->settings->assertRegion($workspace);
        $row = $this->settings->get($workspace);
        try {
            $topic = $this->aws->topic();
            $endpoint = $this->aws->endpoint($workspace);
            $name = $this->aws->configurationSet($workspace);
            $set = $this->aws->ses()->getConfigurationSet(['ConfigurationSetName' => $name])->toArray();
            $owned =
                (array_column($set['Tags'] ?? [], 'Value', 'Key')['mailcrab_workspace'] ?? null)
                === (string) $workspace;
            $destinations = $this->aws
                ->ses()
                ->getConfigurationSetEventDestinations(['ConfigurationSetName' => $name])
                ->get('EventDestinations') ?? [];
            $events = false;
            foreach ($destinations as $dest) {
                if (
                    ($dest['Enabled'] ?? false)
                    && ($dest['SnsDestination']['TopicArn'] ?? '') === $topic
                    && !array_diff(['DELIVERY', 'BOUNCE', 'COMPLAINT'], $dest['MatchingEventTypes'] ?? [])
                ) {
                    $events = true;
                }
            }
            $confirmed = false;
            $problem = 'waiting_for_confirmation';
            if (is_string($row['subscription_arn']) && str_starts_with($row['subscription_arn'], $topic . ':')) {
                $attributes = $this->aws
                    ->sns()
                    ->getSubscriptionAttributes(['SubscriptionArn' => $row['subscription_arn']])
                    ->get('Attributes') ?? [];
                $problem = match (true) {
                    ($attributes['TopicArn'] ?? '') !== $topic
                        || ($attributes['Endpoint'] ?? '') !== $endpoint
                        || ($attributes['Protocol'] ?? '') !== 'https'
                        => 'endpoint_mismatch',
                    ($attributes['PendingConfirmation'] ?? 'true') !== 'false' => 'waiting_for_confirmation',
                    ($attributes['RawMessageDelivery'] ?? 'false') !== 'false' => 'raw_delivery_enabled',
                    default => null,
                };
                $confirmed = $problem === null;
            }
            $suppression = !array_diff(['BOUNCE', 'COMPLAINT'], $set['SuppressionOptions']['SuppressedReasons'] ?? []);
            $safe = [
                'ready' =>
                    $owned
                        && $events
                        && $confirmed
                        && $suppression
                        && ($set['SendingOptions']['SendingEnabled'] ?? true),
                'topic' => $topic,
                'endpoint' => $endpoint,
                'configuration_set' => $name,
                'events_connected' => $events,
                'subscription_confirmed' => $confirmed,
                'suppression_enabled' => $suppression,
                'configuration_sending_enabled' => (bool) ($set['SendingOptions']['SendingEnabled'] ?? true),
                'subscription_problem' => $problem,
            ];
            $this->settings->update($workspace, [
                'feedback' => json_encode($safe, JSON_THROW_ON_ERROR),
                'feedback_checked_at' => $this->store->now(),
                'feedback_error' => null,
            ]);
        } catch (\Throwable $error) {
            $this->fail($workspace, $error);
        }
    }

    private function fail(int $workspace, \Throwable $error): never
    {
        $message = DeliverySettings::error($error);
        $this->settings->update($workspace, [
            'feedback' => null,
            'feedback_error' => $message,
            'feedback_checked_at' => $this->store->now(),
        ]);
        throw new HttpException(422, $message);
    }
}
