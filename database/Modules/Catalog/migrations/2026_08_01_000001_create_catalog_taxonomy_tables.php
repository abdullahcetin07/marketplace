<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform taxonomy: categories, brands, attributes and the per-category
 * attribute schema (ADR-038).
 *
 * All of it is Category-Manager-owned reference data, so every table here uses
 * `is_active` rather than a status enum (ADR-015) and nothing is ever hard
 * deleted out from under the products pointing at it.
 *
 * @see App\Modules\Catalog\Domain\Models\Category
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Adjacency list + materialised path (§13.1, ruled). RESTRICT, not
            // cascade: deleting a node with children would silently take a
            // whole branch of the taxonomy — and the products under it — with
            // it. Withdrawing a category is `is_active = false`.
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();

            // A closed chain of internal ids — `/3/17/42/`. Ids so a rename
            // cannot invalidate the tree; closed on both ends so `/1/` does not
            // prefix-match `/17/`. Indexed because descendant reads are a
            // prefix scan on this column — the whole reason it exists.
            $table->string('path')->index();
            $table->unsignedSmallInteger('depth')->default(0);

            // Per-locale columns, tr + en from the start (§13.5). English is
            // nullable: a category is usable the moment it has a Turkish name,
            // and an empty English column is cheaper than the migration that
            // would add it later.
            $table->string('name_tr');
            $table->string('name_en')->nullable();

            // Globally unique, not per-level: a category slug is a public URL
            // segment (§3.5) and scoping uniqueness to a parent would make the
            // URL ambiguous the first time two branches both had "aksesuar".
            $table->string('slug')->unique();

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('position')->default(0);

            $table->timestampsTz();

            // "The children of this node, in display order" — the tree render.
            $table->index(['parent_id', 'position']);
        });

        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Not localized: a brand name is a proper noun (§2.2).
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true)->index();

            $table->timestampsTz();
        });

        Schema::create('attributes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // The stable machine handle. Labels get re-worded; this does not,
            // because search facets and importers key on it.
            $table->string('code')->unique();

            $table->string('name_tr');
            $table->string('name_en')->nullable();

            $table->string('type', 20)->index();

            // DEFAULTS for a new category binding, not the effective answer —
            // the per-category truth lives on `category_attribute` below.
            $table->boolean('is_variant_defining')->default(false);
            $table->boolean('is_filterable')->default(true);

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('position')->default(0);

            $table->timestampsTz();
        });

        Schema::create('attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Cascade is right here and nowhere else in this module: a value
            // has no meaning apart from its attribute (ADR-014 — cascade for
            // dependent child records only).
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();

            // `value` is the machine handle (`red`), `label_*` what a human
            // reads — so re-wording "Kırmızı" cannot create a second colour.
            $table->string('value');
            $table->string('label_tr');
            $table->string('label_en')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);

            $table->timestampsTz();

            $table->unique(['attribute_id', 'value']);
        });

        // The attribute schema (§2.3) — and the reason the flags are here
        // rather than on `attributes`: Renk is a variant axis in Giyim and a
        // plain description in Mobilya, while both filter on the same values.
        Schema::create('category_attribute', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();

            $table->boolean('is_required')->default(false);
            $table->boolean('is_variant_defining')->default(false);
            $table->boolean('is_filterable')->default(true);
            $table->unsignedInteger('position')->default(0);

            $table->timestampsTz();

            // One binding per attribute per category. Without this a double
            // submit would give a category two contradictory schemas for the
            // same attribute and no way to say which one won.
            $table->unique(['category_id', 'attribute_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_attribute');
        Schema::dropIfExists('attribute_values');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
    }
};
