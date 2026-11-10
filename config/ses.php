<?php

declare(strict_types=1);

return [
    // Read checks are opt-in too. No credential-chain probing on dashboard load.
    'enabled' => env('SES_ENABLED', false),
    'setup_enabled' => env('SES_SETUP_ENABLED', false),
    // Never enabled by the browser. Also requires explicit per-workspace CLI authorization.
    'live_enabled' => env('SES_LIVE_ENABLED', false),
    'region' => env('AWS_REGION', 'us-east-1'),
    'topic_arn' => env('SES_SNS_TOPIC_ARN', ''),
    'configuration_prefix' => env('SES_CONFIGURATION_PREFIX', 'mailcrab'),
];
