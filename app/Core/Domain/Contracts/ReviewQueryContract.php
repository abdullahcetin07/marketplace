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
}
