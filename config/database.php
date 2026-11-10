<?php

declare(strict_types=1);

return [
    'default' => 'main',
    'connections' => ['main' => [
        'dsn' => env('DB_DSN', 'mysql:host=127.0.0.1;dbname=mailcrab;charset=utf8mb4'),
        'username' => env('DB_USERNAME', 'mailcrab'),
        'password' => env('DB_PASSWORD', ''),
        'async' => env('DB_ASYNC', false),
        'pool' => 5,
        'migrations' => dirname(__DIR__) . '/database/migrations',
    ]],
];
