<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Infrastructure\Repositories;

use App\Modules\Reviews\Domain\Contracts\ReviewRepositoryContract;
use App\Modules\Reviews\Domain\DTOs\ReviewListFilterDTO;
use App\Modules\Reviews\Domain\Models\Review;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reviews' own table, read the four ways its surfaces need it.
 *
 * **THE SUMMARY IS COMPUTED, NEVER STORED** (ADR-069, the same discipline as the
 * buy box's price in ADR-045). There is no `products.rating_average` to go stale
 * against the rows it summarises, and deleting a review — which a buyer may do —
 * changes the next read with nothing to invalidate. Its cost, stated: two grouped
 * queries per product page instead of one indexed column read. Bounded by
 * `(product_uuid, status)`, and if it ever stops being bounded the fix is a
 * counter maintained on `ReviewPublished` BEHIND these same methods, so no
 * surface changes.
 *
 * **`Published`-ONLY IS ENFORCED HERE, NOT ASKED FOR.** Every public read applies
 * the scope itself and no method takes a status parameter — the alternative is a
 * caller that can request pending reviews, which is a leak the first time somebody
 * passes the wrong constant.
 *
 * @see App\Modules\Reviews\Domain\Contracts\ReviewRepositoryContract
 */
final class ReviewRepository implements ReviewRepositoryContract
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Review
    {
        return Review::query()->create($attributes);
    }

    public function findByUuid(string $uuid): ?Review
    {
        return Review::query()->where('uuid', $uuid)->first();
    }

    /**
     * @return LengthAwarePaginator<int, Review>
     */
    public function publishedForProduct(string $productUuid, ReviewListFilterDTO $filter): LengthAwarePaginator
    {
        return Review::query()
            ->published()
            ->forProduct($productUuid)
            // "Bu satıcıdan alanlar ne demiş" (ADR-066) — a filter on one set,
            // not a second set.
            ->when($filter->sellerStoreUuid !== null, fn ($q) => $q->where('store_uuid', $filter->sellerStoreUuid))
            ->when($filter->withImages, fn ($q) => $q->where('has_photos', true))
            ->when($filter->rating !== null, fn ($q) => $q->where('rating', $filter->rating))
            // NEWEST FIRST, ALWAYS. The obvious second sort is "most helpful",
            // and there are no votes in v1 — a control with one option is a
            // control that does nothing.
            ->orderByDesc('id')
            ->paginate(perPage: $filter->perPage, page: $filter->page);
    }

    /**
     * @return array{average: string, count: int, distribution: array<int, int>, with_images_count: int, sellers: array<int, array{store_uuid: string, count: int}>}
     */
    public function summaryForProduct(string $productUuid): array
    {
        /*
        | ONE GROUPED QUERY CARRIES THE AVERAGE, THE COUNT AND THE DISTRIBUTION.
        | They are three readings of the same five numbers, so fetching them
        | separately would be three scans that can disagree if a review is
        | published between them.
        */
        $byRating = Review::query()
            ->published()
            ->forProduct($productUuid)
            ->selectRaw('rating, COUNT(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $distribution = [];
        $count = 0;
        $weighted = 0;

        foreach ([5, 4, 3, 2, 1] as $star) {
            $bucket = (int) ($byRating[$star] ?? 0);
            // FILLED FOR 1..5 INCLUDING THE ZEROS: a bar chart with missing
            // buckets is a bar chart the client has to repair.
            $distribution[$star] = $bucket;
            $count += $bucket;
            $weighted += $star * $bucket;
        }

        return [
            'average' => self::average($weighted, $count),
            'count' => $count,
            'distribution' => $distribution,
            'with_images_count' => Review::query()
                ->published()
                ->forProduct($productUuid)
                ->where('has_photos', true)
                ->count(),
            'sellers' => Review::query()
                ->published()
                ->forProduct($productUuid)
                ->selectRaw('store_uuid, COUNT(*) as total')
                ->groupBy('store_uuid')
                ->orderByDesc('total')
                ->get()
                ->map(static fn (Review $row): array => [
                    'store_uuid' => $row->store_uuid,
                    'count' => (int) $row->getAttribute('total'),
                ])
                ->all(),
        ];
    }

    /**
     * @param array<int, string> $productUuids
     *
     * @return array<string, array{average: string, count: int}>
     */
    public function summariesForProducts(array $productUuids): array
    {
        if ($productUuids === []) {
            return [];
        }

        /*
        | ONE QUERY FOR A WHOLE GRID, which is the entire reason this method
        | exists beside `summaryForProduct`. A listing page renders forty cards;
        | forty summary calls would be eighty grouped queries for eighty numbers,
        | on the busiest anonymous route the platform has.
        |
        | AND IT FETCHES ONLY WHAT A CARD SHOWS — an average and a count. No
        | distribution, no seller breakdown: a star badge has no room for them.
        */
        return Review::query()
            ->published()
            ->whereIn('product_uuid', $productUuids)
            ->selectRaw('product_uuid, COUNT(*) as total, SUM(rating) as weighted')
            ->groupBy('product_uuid')
            ->get()
            ->mapWithKeys(static function (Review $row): array {
                $count = (int) $row->getAttribute('total');

                return [$row->product_uuid => [
                    'average' => self::average((int) $row->getAttribute('weighted'), $count),
                    'count' => $count,
                ]];
            })
            ->all();
        // An unreviewed product is simply not in the map — never "0.0", which a
        // card renders as "rated badly" rather than "not rated yet".
    }

    /**
     * @return Collection<int, Review>
     */
    public function forCustomer(int $customerId): Collection
    {
        // EVERY STATUS (Reviews.md §8): a buyer must see their own pending
        // review, or they will write it again believing it was lost.
        return Review::query()
            ->where('customer_id', $customerId)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function reviewedOrderLineUuids(int $customerId, string $productUuid): array
    {
        /*
        | EVERY STATUS AGAIN, AND HERE IT IS A CORRECTNESS RULE RATHER THAN A
        | COURTESY. This is what the eligibility read subtracts, so a PENDING
        | review must still hide its line: offering it back would let a buyer
        | submit a second review of one purchase and meet the unique index as a
        | 500 instead of a tidy "already reviewed".
        */
        return Review::query()
            ->where('customer_id', $customerId)
            ->forProduct($productUuid)
            ->pluck('order_line_uuid')
            ->all();
    }

    public function delete(Review $review): void
    {
        // HARD (Reviews.md §8) — it frees the `order_line_uuid` so the buyer can
        // write a replacement from the same purchase. A soft delete would leave a
        // ghost colliding with the unique index, making the line permanently
        // unreviewable.
        $review->delete();
    }

    /**
     * `$weighted / $count` to one decimal, as a STRING.
     *
     * NEVER A FLOAT ACROSS THE WIRE. A rating is not money and the minor-units
     * rule does not apply — but most clients parse a JSON number as a float, and
     * "4.3" is not representable as one. The platform renders every amount as a
     * decimal string (005 §28) and an average is one more decimal that must not
     * drift on the way out.
     *
     * ZERO REVIEWS IS `"0.0"` HERE, and the batch endpoint omits such products
     * entirely rather than sending it — this method answers "what is the
     * average", and the honest answer for nothing is zero.
     */
    private static function average(int $weighted, int $count): string
    {
        if ($count <= 0) {
            return '0.0';
        }

        return number_format($weighted / $count, 1, '.', '');
    }
}
