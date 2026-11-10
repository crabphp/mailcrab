<?php

declare(strict_types=1);

return [
    'address' => env('SERVER_ADDRESS', 'tcp://127.0.0.1:8090'),
    'workers' => env('SERVER_WORKERS', 1),
    'watch' => ['app', 'app.php', 'config', 'routes', '.env', 'public'],
    'public' => 'public',
    'max_body' => 524288,
    'body_timeout' => 15,
    'pid' => 'storage/crab.pid',
    'log' => 'storage/server.log',
];
