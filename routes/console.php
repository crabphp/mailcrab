<?php

declare(strict_types=1);

use Crab\Command;
use Mailcrab\Console\Bootstrap;
use Mailcrab\Console\Tick;

Command::add('mailcrab:workspace', Bootstrap::class);
Command::add('mailcrab:tick', Tick::class);
Command::add('mailcrab:ses-authorize', \Mailcrab\Console\AuthorizeSes::class);
