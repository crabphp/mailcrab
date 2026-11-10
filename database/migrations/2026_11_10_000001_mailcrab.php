<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;
use Crab\Contracts\Database\Table;

return new class implements Migration {
    public function up(Schema $schema): void
    {
        $schema->create('workspaces', static function (Table $t): void {
            $t->id();
            $t->string('name', 120);
            $t->string('slug', 80)->unique();
            $t->text('postal_address');
            $t->timestamps();
        });
        $schema->create('users', static function (Table $t): void {
            $t->id();
            $t->string('email', 254)->unique();
            $t->string('password');
            $t->timestamps();
        });
        $schema->create('memberships', static function (Table $t): void {
            $t->foreignId('workspace_id')->references('workspaces');
            $t->foreignId('user_id')->references('users');
            $t->primary(['workspace_id', 'user_id']);
        });
        $schema->create('sessions', static function (Table $t): void {
            $t->id();
            $t->string('token_hash', 64)->unique();
            $t->foreignId('user_id')->references('users');
            $t->dateTime('expires_at')->index();
        });
        $schema->create('senders', static function (Table $t): void {
            $t->id();
            $t->foreignId('workspace_id')->references('workspaces');
            $t->string('email', 254);
            $t->string('name', 120);
            $t->dateTime('verified_at')->nullable();
            $t->unique(['workspace_id', 'email']);
            $t->timestamps();
        });
        $schema->create('subscribers', static function (Table $t): void {
            $t->id();
            $t->foreignId('workspace_id')->references('workspaces');
            $t->string('email', 254);
            $t->string('name', 120)->default('');
            $t->string('status', 24);
            $t->dateTime('confirmed_at')->nullable();
            $t->unique(['workspace_id', 'email']);
            $t->index(['workspace_id', 'status', 'id']);
            $t->timestamps();
        });
        $schema->create('tags', static function (Table $t): void {
            $t->id();
            $t->foreignId('workspace_id')->references('workspaces');
            $t->string('name', 60);
            $t->unique(['workspace_id', 'name']);
        });
        $schema->create('subscriber_tags', static function (Table $t): void {
            $t->foreignId('workspace_id')->references('workspaces');
            $t->foreignId('subscriber_id')->references('subscribers');
            $t->foreignId('tag_id')->references('tags');
            $t->primary(['subscriber_id', 'tag_id']);
        });
        $schema->create('consents', static function (Table $t): void {
            $t->id();
            $t->foreignId('workspace_id')->references('workspaces');
            $t->foreignId('subscriber_id')->references('subscribers');
            $t->string('kind', 32);
            $t->text('source');
            $t->text('evidence');
            $t->dateTime('occurred_at');
            $t->dateTime('recorded_at');
            $t->index(['workspace_id', 'subscriber_id']);
        });
        $schema->create('suppressions', static function (Table $t): void {
            $t->id();
            $t->foreignId('workspace_id')->references('workspaces');
            $t->string('email', 254);
            $t->string('reason', 32);
            $t->dateTime('created_at');
            $t->unique(['workspace_id', 'email']);
        });
        $schema->create('challenges', static function (Table $t): void {
            $t->id();
            $t->foreignId('workspace_id')->references('workspaces');
            $t->string('kind', 24);
            $t->bigInteger('target_id');
            $t->string('token_hash', 64)->unique();
            $t->dateTime('expires_at');
            $t->dateTime('used_at')->nullable();
            $t->dateTime('created_at');
            $t->index(['workspace_id', 'kind', 'target_id']);
        });
        $schema->create('campaigns', static function (Table $t): void {
            $t->id();
            $t->foreignId('workspace_id')->references('workspaces');
            $t->foreignId('sender_id')->references('senders');
            $t->foreignId('tag_id')->nullable()->references('tags');
            $t->string('subject', 180);
            $t->text('body');
            $t->text('postal_address');
            $t->string('status', 24)->default('draft');
            $t->dateTime('scheduled_at')->nullable();
            $t->bigInteger('audience_max')->default(0);
            $t->bigInteger('audience_cursor')->default(0);
            $t->dateTime('finished_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'scheduled_at']);
            $t->index(['workspace_id', 'id']);
        });
        $schema->create('welcome_sequences', static function (Table $t): void {
            $t->id();
            $t->foreignId('workspace_id')->unique()->references('workspaces');
            $t->foreignId('sender_id')->references('senders');
            $t->string('subject', 180);
            $t->text('body');
            $t->boolean('enabled')->default(false);
            $t->timestamps();
        });
        $schema->create('deliveries', static function (Table $t): void {
            $t->id();
            $t->foreignId('workspace_id')->references('workspaces');
            $t->foreignId('campaign_id')->nullable()->references('campaigns');
            $t->foreignId('subscriber_id')->nullable()->references('subscribers');
            $t->foreignId('sender_id')->nullable()->references('senders');
            $t->string('kind', 24);
            $t->string('dedupe_key', 190)->unique();
            $t->string('token', 64)->unique();
            $t->string('recipient', 254);
            $t->string('from_email', 254);
            $t->string('from_name', 120);
            $t->string('subject', 180);
            $t->text('body');
            $t->text('postal_address');
            $t->string('status', 24)->default('queued');
            $t->integer('attempts')->default(0);
            $t->dateTime('available_at');
            $t->dateTime('claimed_at')->nullable();
            $t->string('provider_id', 190)->nullable()->unique();
            $t->text('last_error')->nullable();
            $t->dateTime('accepted_at')->nullable();
            $t->dateTime('delivered_at')->nullable();
            $t->dateTime('created_at');
            $t->index(['status', 'available_at', 'id']);
            $t->index(['workspace_id', 'campaign_id', 'status']);
        });
        $schema->create('links', static function (Table $t): void {
            $t->id();
            $t->foreignId('delivery_id')->references('deliveries');
            $t->string('token', 64)->unique();
            $t->text('url');
            $t->integer('position');
            $t->unique(['delivery_id', 'position']);
        });
        $schema->create('delivery_events', static function (Table $t): void {
            $t->id();
            $t->foreignId('workspace_id')->references('workspaces');
            $t->foreignId('delivery_id')->references('deliveries');
            $t->string('event_key', 190)->unique();
            $t->string('kind', 32);
            $t->dateTime('created_at');
            $t->index(['workspace_id', 'delivery_id', 'kind']);
        });
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'delivery_events',
            'links',
            'deliveries',
            'welcome_sequences',
            'campaigns',
            'challenges',
            'suppressions',
            'consents',
            'subscriber_tags',
            'tags',
            'subscribers',
            'senders',
            'sessions',
            'memberships',
            'users',
            'workspaces',
        ] as $table) {
            $schema->drop($table);
        }
    }
};
