<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel database notifications — the in-app inbox.
 *
 * Standard Laravel schema, with one deviation: `data` is jsonb rather than
 * text so PostgreSQL can index into it. Filtering an inbox by notification
 * payload is otherwise a full scan.
 *
 * The primary key is a UUID here because Laravel's DatabaseNotification model
 * requires it — this is a framework contract, not a departure from the
 * platform's BIGINT rule. @see docs/001_Architecture.md §8
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            // The inbox query: this user's unread notifications, newest first.
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        /*
        | Per-user channel opt-outs.
        |
        | Deny-list rather than allow-list: a new notification type is on by
        | default for everyone. An allow-list would mean shipping a feature
        | that nobody receives until they opt in, which nobody does.
        |
        | Database notifications are never opt-outable and security alerts
        | override any row here — enforced in BaseNotification::shouldSend().
        */
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('channel', 20);
            // Null means "this channel, for everything". A class name narrows
            // the opt-out to one notification.
            $table->string('notification_type')->nullable();

            $table->boolean('enabled')->default(true);

            $table->timestampsTz();

            $table->unique(['user_id', 'channel', 'notification_type'], 'notification_prefs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};
