<?php

declare(strict_types=1);

namespace Mailcrab;

use Crab\Contracts\Container\Container;
use Crab\Contracts\Plugin;
use Mailcrab\Delivery\DeliveryRouter;
use Mailcrab\Delivery\Provider;

final class Setup implements Plugin
{
    public function register(Container $container): void
    {
        $container->singleton(Provider::class, DeliveryRouter::class);
        $container->singleton(
            \Mailcrab\Delivery\AwsConnection::class,
            static fn(): \Mailcrab\Delivery\AwsConnection => new \Mailcrab\Delivery\AwsConnection(),
        );
    }
}
