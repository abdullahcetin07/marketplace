<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @see App\Modules\Localization\Domain\Models\Country
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('iso2', 2)->unique();
            $table->string('iso3', 3)->unique();
            $table->string('numeric_code', 3)->nullable();

            $table->string('name');
            $table->string('native_name')->nullable();
            $table->string('phone_code', 8)->nullable();

            /*
            | BIGINT foreign keys — the project standard. Public identifiers
            | are the uuid columns; relationships are always by id.
            | @see docs/001_Architecture.md §"Identifiers"
            |
            | nullOnDelete rather than cascade: retiring a currency must not
            | silently delete the countries that used it.
            */
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->foreignId('timezone_id')->nullable()->constrained('timezones')->nullOnDelete();

            $table->string('flag', 16)->nullable();
            $table->string('capital')->nullable();
            $table->string('region', 64)->nullable();

            // Drives VAT/OSS handling in the future tax module.
            $table->boolean('is_eu_member')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestampsTz();

            $table->index(['is_active', 'sort_order']);
            $table->index('region');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
