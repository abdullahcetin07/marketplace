<?php

declare(strict_types=1);

namespace App\Core\Domain\Contracts;

/**
 * What another module may ask Reviews (ADR-078).
 *
 * **THE FIRST CORE PORT REVIEWS HAS NEEDED.** Reviews was built reading OTHER
 * modules through Core — Catalog for the product, Order for the delivery gate —
 * and nothing had ever asked it a question back. "En Çok Değerlendirilenler" is
 * that question, and it arrives from Catalog, which owns the storefront's product
 * strips and may not import Reviews.
 *
 * **IT ANSWERS UUIDS, NOT REVIEWS.** A ranking needs identity and order; handing
 * back review models would put one buyer's text and rating inside a payload about
 * products, and would drag Reviews' shape into Catalog's. The caller hydrates.
 *
 * @see App\Core\Domain\Contracts\OrderQueryContract for the sibling ranking port
 */
interface ReviewQueryContract
{
    /**
     * Product uuids by published review count, most reviewed first.
     *
     * **PUBLISHED MEANS MODERATED** (ADR-068). A pending review is one nobody has
     * read yet and a rejected one is a review the platform refused; counting
     * either would let a product climb the homepage on text no buyer will ever
     * see. The count is computed on read, like the rating average (ADR-069), so a
     * deleted review changes the next read with nothing to invalidate.
     *
     * @return array<int, string> ranked, at most $limit, empty when nothing is
     *                            reviewed yet
     */
    public function mostReviewedProductUuids(int $limit): array;

    /**
     * Who wrote this review — the customer's uuid, or null (ADR-083).
     *
     * **ADDED SO LOYALTY CAN PAY THE RIGHT PERSON.** `ReviewPublished` carries the
     * review, the product and the moderator; it never carried the buyer, because
     * nothing had needed them. Widening the event was the alternative and the
     * worse one: a payload is a promise to every listener, and one listener's need
     * does not belong in it.
     *
     * Null when the review is gone — a deleted review pays nobody, and the caller
     * treats that as "no grant" rather than an error.
     */
    public function authorCustomerUuidFor(string $reviewUuid): ?string;

    /**
     * A shop's rating, rolled up from the reviews its buyers left (SEO audit #4).
     *
     * **REVIEWS ARE ABOUT THE PRODUCT, BUT THEY CARRY THE SELLER THEY WERE BOUGHT
     * FROM** (ADR-066) — a tag copied from the delivered order line, never chosen
     * by the buyer. That tag is what makes a shop-level rollup honest: it is the
     * same reviews, grouped by who sold the thing, not a separate opinion nobody
     * was asked for.
     *
     * **PUBLISHED ONLY** (ADR-068), like every other count on this port: a pending
     * review is one nobody has read and a rejected one the platform refused.
     *
     * **NULL WHEN THERE ARE NO REVIEWS, NEVER A ZERO.** The caller renders stars
     * and emits `aggregateRating` in JSON-LD; a shop with nothing to show must
     * render nothing, because "0.0 out of 5" is a claim, and structured data that
     * invents a rating is the kind of thing search engines penalise.
     *
     * @return array{rating: float, count: int}|null
     */
    public function sellerRatingFor(string $sellingOrgUuid): ?array;
}
