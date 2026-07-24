<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Session, device and login-history tables.
 *
 * These sit AROUND identity rather than being identity — `users` itself stays
 * in database/migrations/ because Core and Shared reference it. @see the users
 * migration for that reasoning.
 *
 * Order within the file matters: devices before sessions, because a session
 * references the device it was opened from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /*
            | Per-user HMAC of coarse request characteristics. Not a tracking
            | identifier: keyed with the user id and app key, so the same
            | browser fingerprints differently for different accounts.
            | @see UserDevice::fingerprintFor()
            */
            $table->string('fingerprint', 64);

            $table->string('name')->nullable();          // user-assigned label
            $table->string('platform', 64)->nullable();
            $table->string('browser', 64)->nullable();
            $table->string('device_type', 32)->nullable(); // desktop|mobile|tablet

            /*
            | A trusted device may skip the 2FA challenge. Time-limited via
            | trusted_at — permanent trust is a permanent 2FA bypass on
            | hardware the user may no longer own.
            */
            $table->boolean('is_trusted')->default(false);
            $table->timestampTz('trusted_at')->nullable();

            $table->timestampTz('last_used_at')->nullable();
            $table->string('last_ip', 45)->nullable();

            $table->timestampsTz();

            // One device row per user per fingerprint — re-signing in from the
            // same browser must update, not duplicate.
            $table->unique(['user_id', 'fingerprint']);
            $table->index(['user_id', 'last_used_at']);
        });

        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // nullOnDelete: pruning an old device must not erase the session
            // history that references it.
            $table->foreignId('device_id')->nullable()->constrained('user_devices')->nullOnDelete();

            /*
            | Exactly one of these is set. `session_id` for cookie-based panel
            | sessions, `token_id` for Sanctum API sessions. Both are needed
            | because revoking has to reach the right mechanism.
            */
            $table->string('session_id')->nullable()->index();
            $table->unsignedBigInteger('token_id')->nullable()->index();

            // Which guard the session belongs to. A user may hold an admin and
            // a customer session simultaneously; revoking one must not touch
            // the other.
            $table->string('guard', 20);

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('location')->nullable();      // coarse, city-level

            $table->timestampTz('last_activity_at');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason', 64)->nullable();

            $table->timestampsTz();

            // The security page query: this user's active sessions, recent
            // first. Partial-ish index via the composite ordering.
            $table->index(['user_id', 'revoked_at', 'last_activity_at']);
        });

        Schema::create('login_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
            | Nullable: an attempt against an address that does not exist must
            | still be recorded — that is precisely what account enumeration
            | looks like.
            */
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('email');                      // as typed, lowercased
            $table->string('guard', 20);

            $table->boolean('successful');
            // Coded reason, never a message: 'invalid_credentials',
            // 'suspended', 'unverified', 'two_factor_failed'.
            $table->string('failure_reason', 64)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('platform', 64)->nullable();
            $table->string('browser', 64)->nullable();
            $table->string('location')->nullable();

            // Append-only — no updated_at. @see LoginAttempt::UPDATED_AT
            $table->timestampTz('created_at')->nullable();

            /*
            | The two detection queries:
            |   failures for one address in a window   (credential stuffing)
            |   attempts from one IP in a window       (spray across accounts)
            */
            $table->index(['email', 'successful', 'created_at']);
            $table->index(['ip_address', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('user_devices');
    }
};
