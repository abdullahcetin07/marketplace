<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One buyer's verdict on one delivered purchase (ADR-066/067).
 *
 * **`order_line_uuid` IS UNIQUE, AND THAT SINGLE INDEX IS THE INTEGRITY MODEL.**
 * One delivered line earns at most one review. A buyer who bought the same
 * product in two orders gets two reviews — each is a distinct purchase
 * experience (owner decision) — which is precisely why the uniqueness sits on the
 * LINE and not on `(customer_id, product_uuid)`: that pair would have refused the
 * second and called it a duplicate.
 *
 * **NOT ONE FOREIGN KEY LEAVES THIS MODULE.** The product, the variant, the order
 * line, the store and the selling org are all bare uuids. Reviews imports no
 * module (ADR-002) and a database-level FK would be the same coupling wearing a
 * different hat — it would also make Order unable to delete an order without
 * asking Reviews first, which is exactly the entanglement the boundary exists to
 * prevent.
 *
 * **`author_name` IS STORED MASKED** ("Abdullah Ç."). Not a join to `users`, and
 * not masked at render: a column that never holds a full name cannot leak one,
 * and a buyer who later changes their name does not retroactively re-sign a
 * review they wrote under the old one.
 *
 * **`has_photos` IS DENORMALISED ON PURPOSE.** The "sadece resimli" filter and
 * the summary's `with_images_count` both read it on the product page — the
 * hottest anonymous route this module has — and the honest alternative is a join
 * against the media table on every row of every page. It is written in the same
 * action that attaches the photos, so the two cannot drift without somebody
 * bypassing `CreateReviewAction`.
 *
 * **`rating` IS A TINYINT AND THERE IS NO MONEY HERE.** 1–5, checked by the
 * request and again by the action; the minor-units rule does not apply to this
 * module at all. The only decimal Reviews produces is the AVERAGE, which is
 * computed on read (ADR-069) and never stored — so there is no column here to go
 * stale against the rows it summarises.
 *
 * @see App\Modules\Reviews\Domain\Models\Review
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            $table->uuid('product_uuid');
            // Nullable: a product may have no variants, and a review of the
            // product itself is still a review.
            $table->uuid('variant_uuid')->nullable();

            // THE GATE (ADR-067). @see the class docblock for why the uniqueness
            // is here and not on (customer, product).
            $table->uuid('order_line_uuid')->unique();

            /*
            | The buyer, as the ADR-040 id/uuid pair. The id is what every scoped
            | query filters on and what is compared against the authenticated
            | actor; the uuid is what may leave the application — except that
            | neither ever does on a public surface, because a review is signed
            | with a masked name and nothing else.
            */
            $table->unsignedBigInteger('customer_id');
            $table->uuid('customer_uuid');
            $table->string('author_name');

            // The seller tag (ADR-066) — copied from the order line, never typed.
            $table->uuid('store_uuid');
            $table->uuid('selling_org_uuid');

            $table->unsignedTinyInteger('rating');
            $table->text('body')->nullable();

            $table->string('status', 20)->default('pending_review');
            $table->boolean('has_photos')->default(false);

            // The moderator's side. `moderated_by` is a `users` id and never
            // leaves the application — a public review does not name the person
            // who approved it.
            $table->timestampTz('moderated_at')->nullable();
            $table->unsignedBigInteger('moderated_by')->nullable();
            $table->text('moderation_reason')->nullable();

            $table->timestampsTz();

            // The product page: list AND summary, both filtered to published.
            $table->index(['product_uuid', 'status']);
            // The moderation queue, which reads one status across all products.
            $table->index('status');
            // "Değerlendirmelerim".
            $table->index('customer_id');
            // "Bu satıcıdan alanlar ne demiş" — the ADR-066 filter.
            $table->index('store_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
