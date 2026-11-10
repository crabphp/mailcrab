<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;
use Crab\Contracts\Database\Table;

return new class implements Migration {
    public function up(Schema $schema): void
    {
        $schema->table('delivery_settings', static function (Table $t): void {
            $t->dateTime('live_authorized_at')->nullable();
            $t->dateTime('first_live_authorized_at')->nullable();
            $t->text('live_authorization_reason')->nullable();
        });
        foreach (['campaigns', 'welcome_sequences', 'deliveries'] as $name) {
            $schema->table($name, static function (Table $t): void {
                $t->string('transport', 16)->default('fake');
            });
        }
        $schema->table('challenges', static function (Table $t): void {
            $t->string('transport', 16)->default('fake');
        });
        $schema->table('subscribers', static function (Table $t): void {
            $t->string('consent_transport', 16)->default('fake');
        });
        $schema
            ->connection()
            ->execute(
                "UPDATE subscribers SET consent_transport = 'recorded' WHERE EXISTS (SELECT 1 FROM consents WHERE consents.subscriber_id = subscribers.id AND consents.workspace_id = subscribers.workspace_id AND consents.kind = 'import_opt_in')",
            );
        $schema->create('ses_authorization_events', static function (Table $t): void {
            $t->id();
            $t->foreignId('workspace_id')->references('workspaces');
            $t->string('action', 16);
            $t->text('reason');
            $t->dateTime('created_at');
            $t->index(['workspace_id', 'id']);
        });
        $schema->create('ses_rate_limits', static function (Table $t): void {
            $t->string('account_key', 64);
            $t->primary(['account_key']);
            $t->bigInteger('next_available_us')->default(0);
        });
        $schema->create('ses_send_reservations', static function (Table $t): void {
            $t->id();
            $t->string('account_key', 64);
            $t->foreignId('workspace_id')->references('workspaces');
            $t->foreignId('delivery_id')->references('deliveries');
            $t->dateTime('created_at');
            $t->index(['account_key', 'created_at']);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('ses_send_reservations');
        $schema->drop('ses_rate_limits');
        $schema->drop('ses_authorization_events');
        foreach (['deliveries', 'welcome_sequences', 'campaigns'] as $name) {
            $schema->table($name, static function (Table $t): void {
                $t->dropColumn('transport');
            });
        }
        $schema->table('challenges', static function (Table $t): void {
            $t->dropColumn('transport');
        });
        $schema->table('subscribers', static function (Table $t): void {
            $t->dropColumn('consent_transport');
        });
        $schema->table('delivery_settings', static function (Table $t): void {
            $t->dropColumn('live_authorized_at', 'first_live_authorized_at', 'live_authorization_reason');
        });
    }
};
