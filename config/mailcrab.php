<?php

declare(strict_types=1);

return [
    'key' => env('APP_KEY', ''),
    'provider' => env('DELIVERY_PROVIDER', 'fake'),
    'webhook_secret' => env('FAKE_WEBHOOK_SECRET', ''),
    'outbox' => env('MAILCRAB_OUTBOX', storage_path('outbox')),
];
