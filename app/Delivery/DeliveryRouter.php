<?php

declare(strict_types=1);

namespace Mailcrab\Delivery;

final readonly class DeliveryRouter implements Provider
{
    public function __construct(
        private FakeProvider $fake,
        private SesGuard $guard,
        private AwsConnection $aws,
    ) {}

    public function idempotent(): bool
    {
        return false;
    }

    public function idempotentFor(array $delivery): bool
    {
        return ($delivery['transport'] ?? 'fake') === 'fake';
    }

    public function send(array $delivery, array $message): string
    {
        if (($delivery['transport'] ?? 'fake') === 'fake') {
            return $this->fake->send($delivery, $message);
        }
        if ($delivery['transport'] !== 'ses') {
            throw new \LogicException('Unknown delivery transport.');
        }
        try {
            $context = $this->guard->preflight($delivery);
            $provider = new SesProvider($this->aws->ses(), $context['configuration_set'], explicitlyAuthorized: true);
            $command = $provider->prepare($delivery, $message);
        } catch (Deferred $error) {
            throw $error;
        } catch (\Throwable) {
            throw new Deferred('SES preflight could not complete. No send was attempted.');
        }
        return $this->guard->dispatch($delivery, $context, static fn(): string => $provider->sendPrepared($command));
    }
}
