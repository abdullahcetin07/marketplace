<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIGRATION ORDERING: the Localization tables use a `0000_` prefix so they sort
 * ahead of everything in database/migrations/. Laravel sorts all pending
 * migrations by filename across every registered path, and `users` carries
 * foreign keys to currencies, countries, languages and timezones — so these
 * must exist first.
 *
 * @see App\Modules\Localization\Domain\Models\Currency
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('code', 3)->unique();          // ISO-4217
            $table->string('name');
            $table->string('native_name')->nullable();

            $table->string('symbol', 8);
            $table->string('symbol_position', 8)->default('after');

            // Exponent for major <-> minor unit conversion. Money is stored as
            // an integer of minor units everywhere; this is how many digits
            // that means. Zero-decimal currencies (JPY) are supported.
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->string('decimal_separator', 1)->default(',');
            $table->string('thousands_separator', 1)->default('.');

            /*
            | Rate relative to the platform's default currency.
            |
            | decimal(20,10), not float: an exchange rate multiplied against a
            | large order total loses real money to binary rounding. 10 decimal
            | places covers the smallest quoted increments with headroom.
            */
            $table->decimal('exchange_rate', 20, 10)->default(1);
            $table->timestampTz('rate_updated_at')->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestampsTz();

            $table->index(['is_active', 'sort_order']);
        });

        /*
        | Exactly one default currency, enforced by the database rather than
        | only by the model hook. Two defaults would make the repository default
        | return whichever row the planner happened to pick — a bug that
        | manifests as prices silently changing between requests.
        */
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'CREATE UNIQUE INDEX currencies_single_default
                 ON currencies ((is_default)) WHERE is_default = true',
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
