<?php

declare(strict_types=1);

namespace Mailcrab\Console;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Mailcrab\Worker;

final readonly class Tick implements Command
{
    public function __construct(
        private Worker $worker,
    ) {}

    public function description(): string
    {
        return 'Run one bounded delivery tick (fake by default; SES only with explicit workspace authorization); --batch=50 (1–100). Safe for cron; no daemon.';
    }

    public function handle(Input $input, Output $output): int
    {
        $output->line(json_encode($this->worker->tick((int) ($input->value('batch') ?? '50')), JSON_THROW_ON_ERROR));
        return 0;
    }
}
