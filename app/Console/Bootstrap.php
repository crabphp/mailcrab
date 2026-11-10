<?php

declare(strict_types=1);

namespace Mailcrab\Console;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Mailcrab\Auth;
use Mailcrab\Store;

final readonly class Bootstrap implements Command
{
    public function __construct(
        private Auth $auth,
    ) {}

    public function description(): string
    {
        return 'Create an isolated workspace and owner; --name --slug --email --address; password from MAILCRAB_ADMIN_PASSWORD';
    }

    public function handle(Input $input, Output $output): int
    {
        Store::key();
        $password = getenv('MAILCRAB_ADMIN_PASSWORD');
        if (!$password) {
            $output->line('Set MAILCRAB_ADMIN_PASSWORD to a unique 12–72 byte password.');
            return 1;
        }
        $result = $this->auth->createWorkspace(
            (string) $input->value('name', ''),
            (string) $input->value('slug', ''),
            (string) $input->value('email', ''),
            $password,
            (string) $input->value('address', ''),
        );
        $output->line('Workspace created: ' . $result['workspace_id'] . '. Log in at ' . config('app.url'));
        return 0;
    }
}
