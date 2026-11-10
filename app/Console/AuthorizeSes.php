<?php

declare(strict_types=1);

namespace Mailcrab\Console;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Database\Connection;
use Crab\Http\HttpException;
use Mailcrab\Delivery\DeliverySettings;
use Mailcrab\Store;

final readonly class AuthorizeSes implements Command
{
    public function __construct(
        private Store $store,
        private DeliverySettings $settings,
    ) {}

    public function description(): string
    {
        return 'Authorize future SES queues only with user direction: --workspace=ID --confirm=EXACT_SLUG --reason=...; --revoke disables authorization. Sends no email.';
    }

    public function handle(Input $input, Output $output): int
    {
        $workspace = Store::id($input->value('workspace'), 'workspace');
        $view = $this->settings->overview($workspace);
        $this->store->db->transaction(function (Connection $db) use ($workspace, $input, $view): void {
            $row = $this->store->lock($db, $workspace);
            if ($input->value('confirm', '') !== $row['slug']) {
                throw new HttpException(422, 'Type the exact workspace slug in --confirm.');
            }
            $reason = Store::text(['reason' => $input->value('reason', '')], 'reason', 500);
            $revoke = $input->flag('revoke');
            if (!$revoke && (config('ses.live_enabled') !== true || !$view['ready'])) {
                throw new HttpException(
                    422,
                    'Enable the operator SES live gate and pass every fresh readiness check first.',
                );
            }
            $settings = $db->table('delivery_settings')->where('workspace_id', $workspace)->first();
            if (!$revoke) {
                $db
                    ->table('challenges')
                    ->where('workspace_id', $workspace)
                    ->where('transport', 'fake')
                    ->whereNull('used_at')
                    ->update(['used_at' => $this->store->now()]);
                $db
                    ->table('deliveries')
                    ->where('workspace_id', $workspace)
                    ->where('kind', 'confirmation')
                    ->where('transport', 'fake')
                    ->where('status', 'queued')
                    ->update(['status' => 'suppressed']);
            }
            $db->table('ses_authorization_events')->insert([
                'workspace_id' => $workspace,
                'action' => $revoke ? 'revoked' : 'authorized',
                'reason' => $reason,
                'created_at' => $this->store->now(),
            ]);
            $db
                ->table('delivery_settings')
                ->where('workspace_id', $workspace)
                ->update([
                    'live_authorized_at' => $revoke ? null : $this->store->now(),
                    'first_live_authorized_at' =>
                        $settings['first_live_authorized_at'] ?? ($revoke ? null : $this->store->now()),
                    'live_authorization_reason' => $reason,
                    'paused' => true,
                    'updated_at' => $this->store->now(),
                ]);
        });
        $output->line(
            'SES authorization updated. Sending stays paused until explicitly unpaused in Settings. Existing fake jobs stay fake. No email was sent.',
        );
        return 0;
    }
}
