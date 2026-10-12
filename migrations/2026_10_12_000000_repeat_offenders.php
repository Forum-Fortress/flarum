<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => static function (Builder $schema): void {
        $schema->create('forumfortress_repeat_state', static function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->unsignedInteger('user_id')->primary();
            $table->boolean('pending')->default(false)->index();
            $table->unsignedInteger('acted_at')->default(0);
            $table->unsignedInteger('last_seen_at');
        });
        $schema->create('forumfortress_repeat_events', static function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->char('body_hash', 64);
            $table->char('reference_hash', 64);
            $table->unsignedInteger('observed_at')->index();
            $table->boolean('consumed')->default(false);
            $table->unique(['user_id', 'body_hash']);
            $table->unique(['user_id', 'reference_hash']);
        });
    },
    'down' => static function (Builder $schema): void {
        $schema->dropIfExists('forumfortress_repeat_events');
        $schema->dropIfExists('forumfortress_repeat_state');
        resolve(Flarum\Settings\SettingsRepositoryInterface::class)->delete('forumfortress.repeat_offender_suspend');
        // Account suspensions and native reversals belong to Suspend, not purge.
    },
];
