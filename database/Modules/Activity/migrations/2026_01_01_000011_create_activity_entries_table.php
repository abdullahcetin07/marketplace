<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a user did — the actor-centric counterpart to audit_entries.
 *
 * @see App\Modules\Activity\Domain\Models\ActivityEntry
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
            | Nullable so a failed login against a non-existent address is
            | still recorded — that is what enumeration looks like, and
            | dropping it would hide the attack.
            |
            | nullOnDelete, not cascade: deleting an account must not erase the
            | record of what that account did. The trail outlives the actor.
            */
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type', 40);

            // Pre-rendered sentence, optional. Null means the type's
            // translation is used instead — see ActivityEntry::label().
            $table->string('description')->nullable();

            // Optional target: the session revoked, the device trusted.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->jsonb('properties')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('browser', 32)->nullable();
            $table->string('platform', 32)->nullable();

            $table->uuid('correlation_id')->nullable();

            // Append-only — no updated_at.
            $table->timestampTz('created_at')->nullable();

            /*
            | Access patterns:
            |   a user's own activity feed, newest first
            |   all entries of one type (e.g. every failed login) for review
            |   a subject's history
            */
            $table->index(['user_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_entries');
    }
};
