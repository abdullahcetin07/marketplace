<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Infrastructure\Queries;

use App\Core\Domain\Contracts\ReviewQueryContract;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use Illuminate\Support\Facades\DB;

/**
 * What Reviews answers when another module asks (ADR-078).
 *
 * **THE FIRST TIME ANYTHING HAS ASKED REVIEWS A QUESTION.** The module was built
 * reading others through Core — Catalog for the product, Order for the delivery
 * gate — and answered nobody. "En Çok Değerlendirilenler" is asked by Catalog,
 * which owns the storefront's product strips and may not import Reviews.
 *
 * **THE QUERY BUILDER, NOT ELOQUENT.** This is an aggregate that returns a list of
 * strings; hydrating review models to throw them away would be the expensive way
 * to reach the same array, and it would pull one buyer's text through a read that
 * is about products.
 *
 * @see App\Core\Domain\Contracts\ReviewQueryContract
 */
final class ReviewQuery implements ReviewQueryContract
{
    /**
     * @return array<int, string>
     */
    public function mostReviewedProductUuids(int $limit): array
    {
        /** @var array<int, string> $uuids */
        $uuids = DB::table('reviews')
            ->select('product_uuid')
            /*
            | **PUBLISHED ONLY** (ADR-068). A pending review is one nobody has read
            | yet and a rejected one is a review the platform refused; counting
            | either would let a product climb the homepage on text no buyer will
            | ever see.
            */
            ->where('status', ReviewStatus::Published->value)
            ->groupBy('product_uuid')
            ->orderByRaw('count(*) desc')
            // A STABLE TIE-BREAK, so two products on the same count do not swap
            // places between requests and make the strip look alive when it is not.
            ->orderBy('product_uuid')
            ->limit(max(1, $limit))
            ->pluck('product_uuid')
            ->all();

        return $uuids;
    }
}
