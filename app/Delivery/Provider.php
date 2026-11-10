<?php

declare(strict_types=1);

namespace Mailcrab\Delivery;

interface Provider
{
    /** Returns an acceptance id, not a delivery receipt. */
    public function send(array $delivery, array $message): string;

    public function idempotent(): bool;
}
