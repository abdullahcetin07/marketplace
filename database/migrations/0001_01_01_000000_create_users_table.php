<?php

declare(strict_types=1);

use App\Shared\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single identity table backing all three guards.
 *
 * `type` is the discriminator that App\Models\{Admin,Seller,Customer} scope on.
 * See App\Models\User for why one table beats three.
 *
 * WHY THIS LIVES IN database/migrations/ AND NOT database/Modules/Identity/:
 * `users` is referenced by the Spatie permission pivots, by the HasCreator and
 * HasUpdater traits in app/Shared, and by BasePolicy in app/Core. If it were
 * owned by a module, Core and Shared would depend on that module and the
 * layering test would (correctly) fail. The Identity module owns everything
 * *around* identity — sessions, devices, login history — not identity itself.
 *
 * IDENTIFIERS: BIGINT primary key, UUID as the public identifier. Every foreign
 * key in the platform references the bigint. @see docs/001_Architecture.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();

            /*
            | Public identifier. Everything customer-facing uses this; the
            | bigint id never leaves the application. @see HasUuid
            */
            $table->uuid('uuid')->unique();

            /*
            | Actor type. Indexed because EVERY query through a guard filters
            | on it — this is the hottest index on the table.
            */
            $table->string('type', 20)->index();

            $table->string('name');
            $table->string('email');
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone', 32)->nullable();
            $table->timestampTz('phone_verified_at')->nullable();

            $table->string('status', 20)->default(Status::Pending->value)->index();

            /*
            | Locale preferences as BIGINT foreign keys into the Localization
            | lookup tables. These were enum-backed string columns in Sprint 0;
            | Country, Currency and Language became tables so an administrator
            | can add or disable one without a deploy.
            | @see docs/001_Architecture.md §"Enums vs lookup tables"
            |
            | All four are nullOnDelete: retiring a currency must degrade a
            | user to the platform default, never delete the account.
            */
            $table->foreignId('language_id')->nullable()->constrained('languages')->nullOnDelete();
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->foreignId('timezone_id')->nullable()->constrained('timezones')->nullOnDelete();

            /*
            | Avatar is a media collection, not a column — see the Media module
            | and App\Shared\Traits\HasMedia. This column caches the resolved
            | URL so rendering a comment thread does not join `media` once per
            | author. It is refreshed by the media observer and is safe to be
            | stale for the length of a request.
            */
            $table->string('avatar_url')->nullable();

            /*
            | Two-factor authentication. Columns exist from Sprint 0 so
            | enabling 2FA later is a feature flag, not a migration on a large
            | live table. Both secret columns are encrypted at the cast level.
            | @see App\Models\User::casts()
            */
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();

            $table->timestampTz('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->unsignedInteger('login_count')->default(0);
            $table->timestampTz('password_changed_at')->nullable();

            $table->rememberToken();
            $table->timestampsTz();
            $table->softDeletesTz();

            /*
            | Email is unique PER ACTOR TYPE, not globally.
            |
            | This is a deliberate product decision: one human may legitimately
            | be both a seller and a customer with the same address, and
            | forcing them to invent a second email is hostile. A global unique
            | index would also let anyone probe whether a given address belongs
            | to an admin.
            |
            | Soft-deleted rows are excluded via the partial index below so a
            | deleted account's address can be reused.
            */
            $table->unique(['type', 'email']);

            $table->index(['status', 'type']);
            $table->index('last_login_at');
        });

        // Partial unique index — PostgreSQL only; the SQLite test connection
        // relies on the composite unique above, which is sufficient there.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'CREATE UNIQUE INDEX users_type_email_active_unique
                 ON users (type, email) WHERE deleted_at IS NULL',
            );
        }

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
