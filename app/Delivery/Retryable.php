<?php

declare(strict_types=1);

namespace Mailcrab\Delivery;

/** Only a known pre-acceptance rejection is retryable. A timeout is ambiguous. */
final class Retryable extends \RuntimeException {}
