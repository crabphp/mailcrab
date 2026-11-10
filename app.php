<?php

declare(strict_types=1);

use Crab\App;
use Mailcrab\Http\Security;

return App::configure(__DIR__)
    ->withSetup(Mailcrab\Setup::class)
    ->withMiddleware([Security::class])
    ->withRouting(routes: 'routes/web.php', commands: 'routes/console.php', health: '/up')
    ->create();
