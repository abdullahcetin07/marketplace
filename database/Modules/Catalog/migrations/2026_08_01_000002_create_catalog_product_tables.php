<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shared catalog's products and their SKUs (ADR-037/039).
 *
 * **THERE IS NO PRICE COLUMN AND NO STOCK COLUMN IN THIS FILE, BY DESIGN.**
 * That absence is the module boundary: it is what lets one product be sold by
 * many sellers at many prices without duplicating the product. Price and
 * condition belong to `offers`, on-hand quantity to `inventory` — both later
 * sprints (§0.2). If you are here to add one, you are in the wrong migration.
 *
 * @see App\Modules\Catalog\Domain\Models\Product
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // RESTRICT: a category with products cannot be deleted. Withdrawing
            // one is `is_active = false` (ADR-015), which leaves every product
            // still pointing somewhere real.
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();

            // Nullable: generic and unbranded goods are real, and a "Markasız"
            // placeholder brand would pollute the brand filter (§2.2).
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();

            $table->string('title_tr');
            $table->string('title_en')->nullable();
            $table->string('slug')->unique();
            $table->text('description_tr')->nullable();
            $table->text('description_en')->nullable();

            // The shared catalog's dedup key (§3.4). UNIQUE so two sellers
            // proposing the same manufactured product collide here rather than
            // both succeeding — but NULLABLE, because plenty of real goods have
            // no barcode, and both engines allow many NULLs under one unique
            // index. 14 characters covers GTIN-8/12/13/14.
            $table->string('gtin', 14)->nullable()->unique();

            // The moderation lifecycle lives on the product itself: approving
            // creates nothing new, so there is no separate request entity (§5).
            $table->string('status', 20)->default('draft')->index();

            // PROVENANCE, NOT OWNERSHIP (ADR-037/040). Catalog imports neither
            // Organization nor Store; there is no `organization()` relation and
            // never will be.
            //
            // THE PAIR, not just the uuid, exactly as `stores` carries
            // `organization_id` + `organization_uuid` (ADR-033). The id is what
            // the seller panel filters on, because the Core
            // `OrganizationAuthorizationContract` answers "which organizations
            // does this user belong to" in internal ids — and translating those
            // to uuids would mean importing Organization, which is the one
            // thing this module may not do. The FK is integrity-only.
            //
            // The uuid is the identifier that leaves the application and the
            // one a future Offer/event payload carries (non-negotiable #7).
            // Both indexed: the id is the tenancy filter on every seller-panel
            // request, the uuid is the provenance lookup.
            $table->foreignId('proposed_by_org_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->uuid('proposed_by_org_uuid')->nullable()->index();
            $table->foreignId('proposed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Who decided, and why. The reason is shown to the seller on a
            // rejection or a revision request, so it is not optional in
            // practice — the action requires it for those two outcomes.
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('moderation_reason')->nullable();

            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('moderated_at')->nullable();
            $table->timestampTz('published_at')->nullable();

            $table->timestampsTz();

            // Archived is the business end-state; `deleted_at` is the separate,
            // recoverable removal. A product Offers reference is never hard
            // deleted (§3.5).
            $table->softDeletesTz();

            // The moderation queue: pending products, oldest submission first.
            $table->index(['status', 'submitted_at']);

            // Category browsing, filtered by publication state.
            $table->index(['category_id', 'status']);
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Cascade: a variant has no meaning apart from its product
            // (ADR-014 — dependent child records).
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // GLOBALLY unique, not per-product (§3.3): a SKU is the handle a
            // seller, a warehouse and a courier all use, and two things sharing
            // one is a fulfilment error. Soft-deleted variants keep theirs —
            // recycling a SKU onto a different thing is exactly the confusion
            // the uniqueness exists to prevent.
            $table->string('sku')->unique();
            $table->string('barcode')->nullable()->unique();

            // The variant's attribute-value ids, sorted and delimited. A
            // DERIVED key because "unique across a variable-length set" is not
            // something a composite index can express (§3.3).
            $table->string('combination_key');

            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('position')->default(0);

            $table->timestampsTz();
            $table->softDeletesTz();

            // §3.3 — the combination is unique WITHIN a product. This is the
            // backstop under the action's readable refusal, and the reason a
            // concurrent double-generate cannot produce twin SKUs.
            $table->unique(['product_id', 'combination_key']);
        });

        // A product's DESCRIPTIVE attribute values (§2.4).
        Schema::create('product_attribute_value', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();

            // TWO WAYS TO CARRY A VALUE, one row either way: `select` picks an
            // AttributeValue, everything else stores a normalised string. Both
            // nullable so one table serves both; the action refuses the wrong
            // one for the attribute's type rather than guessing.
            $table->foreignId('attribute_value_id')->nullable()->constrained('attribute_values')->cascadeOnDelete();
            $table->string('value')->nullable();

            $table->timestampsTz();

            // One value per attribute per product — so "what colour is this"
            // always has exactly one answer.
            $table->unique(['product_id', 'attribute_id']);
        });

        // A variant's DEFINING values — its axes (ADR-039).
        Schema::create('variant_attribute_value', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();

            // NOT nullable, unlike the product pivot: a variant axis is always
            // an enumerated value, because a cartesian needs finite axes.
            $table->foreignId('attribute_value_id')->constrained('attribute_values')->cascadeOnDelete();

            $table->timestampsTz();

            // One value per axis per variant. "Size M and size L" is two
            // variants, not one variant with two sizes.
            $table->unique(['product_variant_id', 'attribute_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_attribute_value');
        Schema::dropIfExists('product_attribute_value');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
