<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;
use Crab\Contracts\Database\Table;

return new class implements Migration {
    public function up(Schema $schema): void
    {
        $schema->create('delivery_settings', static function (Table $t): void {
            $t->foreignId('workspace_id')->references('workspaces');
            $t->primary(['workspace_id']);
            $t->string('region', 32);
            $t->json('account')->nullable();
            $t->dateTime('account_checked_at')->nullable();
            $t->text('account_error')->nullable();
            $t->json('feedback')->nullable();
            $t->dateTime('feedback_checked_at')->nullable();
            $t->text('feedback_error')->nullable();
            $t->string('subscription_arn', 2048)->nullable();
            $t->text('subscription_token')->nullable();
            $t->boolean('paused')->default(true);
            $t->integer('rate_per_second')->default(1);
            $t->foreignId('default_sender_id')->nullable()->references('senders');
            $t->timestamps();
        });
        $schema->create('sending_domains', static function (Table $t): void {
            $t->id();
            $t->foreignId('workspace_id')->references('workspaces');
            // No cross-workspace claiming of an identity, even in a second region.
            $t->string('domain', 253)->unique();
            $t->string('region', 32);
            $t->string('mail_from', 253);
            $t->string('status', 24)->default('not_connected');
            $t->json('identity')->nullable();
            $t->json('dns')->nullable();
            $t->dateTime('checked_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
            $t->index(['workspace_id', 'id']);
        });
        $schema->create('ses_event_inbox', static function (Table $t): void {
            $t->id();
            $t->foreignId('workspace_id')->references('workspaces');
            $t->string('event_key', 64)->unique();
            $t->json('payload');
            $t->string('status', 24)->default('pending');
            $t->integer('attempts')->default(0);
            $t->dateTime('available_at');
            $t->dateTime('created_at');
            $t->text('last_error')->nullable();
            $t->index(['status', 'available_at']);
        });
        $schema->table('senders', static function (Table $t): void {
            $t->string('reply_to', 254)->nullable();
        });
        $schema->table('deliveries', static function (Table $t): void {
            $t->string('reply_to', 254)->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->table('deliveries', static function (Table $t): void {
            $t->dropColumn('reply_to');
        });
        $schema->table('senders', static function (Table $t): void {
            $t->dropColumn('reply_to');
        });
        $schema->drop('ses_event_inbox');
        $schema->drop('sending_domains');
        $schema->drop('delivery_settings');
    }
};
