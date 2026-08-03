<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The administrative geography a client offers as a cascade — province →
 * district → neighbourhood (ADR-056 amendment, 2026-08-03).
 *
 * REFERENCE DATA, NOT THE ADDRESS. A customer address stores `city`, `district`
 * and `neighborhood` as free strings and holds no foreign key into these tables,
 * deliberately (ADR-056 stands): a neighbourhood is renamed, merged or created by
 * administrative act, and an address saved last year must not become invalid —
 * or unreadable — because the registry moved on. These tables exist so a form can
 * offer a pick instead of a free-typed field. They do not get a vote on what is
 * valid.
 *
 * IN LOCALIZATION, which is the one module every other module may read
 * (`LayeringTest`'s single exception). Geography is platform-wide reference data
 * of exactly the kind `countries`, `currencies` and `timezones` already are, and
 * putting it anywhere else would mean Order importing that module to render an
 * address form.
 *
 * A TABLE RATHER THAN AN ENUM, by the project's own test (CLAUDE.md): an operator
 * must be able to add a neighbourhood — Turkey creates and merges them by decree
 * several times a year — without a release. Hence `is_active` on each level too,
 * the lookup-table convention (ADR-015): a merged neighbourhood is DEACTIVATED,
 * never deleted, because addresses out there still name it.
 *
 * SCOPED BY COUNTRY even though only TR is seeded. The tables are called `geo_*`
 * rather than `tr_*`, and a generic name with a hidden Turkish assumption is the
 * kind of thing that is discovered by the second country rather than by reading.
 * One column now beats a migration and a backfill later.
 *
 * NO SORT COLUMN, and that is a decision rather than an omission — see
 * `GeoRepository`. Turkish alphabetical order (…c, ç… g, ğ… i, ı… o, ö… s, ş…
 * u, ü…) is not what `ORDER BY name` produces under SQLite's byte comparison or
 * a default-collation Postgres, and the result sets here are one parent's
 * children — 81 provinces, ~30 districts, ~75 neighbourhoods — so they are
 * ordered with a `tr` collator after the query, correctly on every driver.
 *
 * @see App\Modules\Localization\Domain\Models\GeoProvince
 * @see docs/Architecture_Decision_Record.md ADR-056
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_provinces', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Localization's own table, so this FK crosses no boundary.
            // RESTRICT: a country with geography under it is deactivated, never
            // deleted.
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();

            $table->string('name');
            /*
            | The plate code (01–81) for TR — a stable, universally known handle
            | that a name is not: "Afyonkarahisar" was "Afyon" within living
            | memory, and 03 was 03 throughout. Nullable because it is a TR
            | concept and another country's provinces may have no such code.
            */
            $table->string('code', 8)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            // Unique WITHIN a country, not globally: two countries may each have
            // a province of the same name, and forbidding that would be the
            // Turkish assumption this table is scoped to avoid.
            $table->unique(['country_id', 'name']);
            $table->index(['country_id', 'is_active']);
        });

        Schema::create('geo_districts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // CASCADE down the tree, unlike the country FK above. A province row
            // only ever disappears when a whole dataset is being replaced, and
            // leaving orphaned districts behind would be worse than the delete.
            $table->foreignId('geo_province_id')->constrained('geo_provinces')->cascadeOnDelete();

            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['geo_province_id', 'name']);
            $table->index(['geo_province_id', 'is_active']);
        });

        Schema::create('geo_neighborhoods', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('geo_district_id')->constrained('geo_districts')->cascadeOnDelete();

            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            /*
            | ~73k rows, so the indexes are not decoration. Every read is
            | "children of this district", which the composite below serves; the
            | UNIQUE is what makes the seeder's upsert idempotent and what stops
            | a duplicate from ever reaching a dropdown.
            */
            $table->unique(['geo_district_id', 'name']);
            $table->index(['geo_district_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_neighborhoods');
        Schema::dropIfExists('geo_districts');
        Schema::dropIfExists('geo_provinces');
    }
};
