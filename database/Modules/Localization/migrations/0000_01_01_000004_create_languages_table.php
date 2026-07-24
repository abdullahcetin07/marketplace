<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @see App\Modules\Localization\Domain\Models\Language
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Laravel locale and URL segment: 'tr'.
            $table->string('code', 10)->unique();
            // BCP-47 for <html lang>, hreflang, Accept-Language: 'tr-TR'.
            $table->string('locale', 16);

            $table->string('name');          // English exonym, for admin lists
            $table->string('native_name');   // endonym — how speakers write it

            // 'ltr' | 'rtl'. RTL support is structural, not a later retrofit:
            // adding Arabic must not require touching every layout.
            $table->string('direction', 3)->default('ltr');

            $table->string('flag', 16)->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestampsTz();

            $table->index(['is_active', 'sort_order']);
        });

        // Exactly one default language. @see the currencies migration for the
        // reasoning behind enforcing this in the database as well as the model.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'CREATE UNIQUE INDEX languages_single_default
                 ON languages ((is_default)) WHERE is_default = true',
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
