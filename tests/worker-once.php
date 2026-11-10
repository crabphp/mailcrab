<?php

declare(strict_types=1);

// Bounded child process used only by the MySQL concurrency test. Never opens a server.
require dirname(__DIR__) . '/vendor/autoload.php';
if (!preg_match('/dbname=mailcrab_test(?:;|$)/', (string) getenv('DB_DSN')) || getenv('DELIVERY_PROVIDER') !== 'fake') {
    throw new RuntimeException('Concurrency helper only permits the dedicated MySQL test database and fake provider.');
}
$app = require dirname(__DIR__) . '/app.php';
$app->container->instance(Psr\Clock\ClockInterface::class, new Crab\Testing\FrozenClock('2026-11-10T12:00:00Z'));
echo json_encode($app->container->get(Mailcrab\Worker::class)->tick(3), JSON_THROW_ON_ERROR) . "\n";
