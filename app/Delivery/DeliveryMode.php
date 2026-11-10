<?php

declare(strict_types=1);

namespace Mailcrab\Delivery;

use Mailcrab\Store;

final readonly class DeliveryMode
{
    public function __construct(
        private Store $store,
    ) {}

    public function forWorkspace(int $workspace, ?\Crab\Contracts\Database\Connection $connection = null): string
    {
        if (config('ses.live_enabled') !== true || config('ses.enabled') !== true) {
            return 'fake';
        }
        $settings = ($connection ?? $this->store->db)
            ->table('delivery_settings')
            ->where('workspace_id', $workspace)
            ->first();
        return $settings && $settings['live_authorized_at'] !== null ? 'ses' : 'fake';
    }
}
