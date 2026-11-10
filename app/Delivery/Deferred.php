<?php

declare(strict_types=1);

namespace Mailcrab\Delivery;

/** A pre-send gate: no provider SendEmail occurred, so it does not consume a delivery attempt. */
final class Deferred extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $delay = 60,
    ) {
        parent::__construct($message);
    }
}
