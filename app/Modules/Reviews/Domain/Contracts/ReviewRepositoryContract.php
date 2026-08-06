<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\Contracts;

use App\Modules\Reviews\Domain\DTOs\ReviewListFilterDTO;
use App\Modules\Reviews\Domain\Models\Review;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * How this module reaches its own table.
 *
 * MODULE-INTERNAL, NOT A CORE PORT. Nothing outside Reviews resolves this: it is
 * the persistence seam every module keeps between its actions and Eloquent, not
 * a question another context may ask. The public questions are HTTP endpoints
 * (ADR-069).
 *
 * **THE READ METHODS ARE SHAPED BY TWO SURFACES AND IT SHOWS.** `publishedForProduct`
 * + `summaryForProduct` are one product page; `summariesForProducts` is a whole
 * grid of listing cards in one call. Those are different queries, not one query
 * called differently, because a card needs an average and a count and nothing
 * else — fetching distributions for forty products to render forty stars would be
 * the N+1 the batch endpoint exists to prevent.
 *
 * **EVERY PUBLIC READ IS `Published`-ONLY, INSIDE THE REPOSITORY.** A caller
 * cannot ask for pending reviews because no method offers the choice — the
 * alternative is a status parameter, and a status parameter is a way to leak an
 * unmoderated review the day somebody passes the wrong one.
 */
interface ReviewRepositoryContract
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Review;

    public function findByUuid(string $uuid): ?Review;

    /**
     * Published reviews for a product, filtered and paginated. Newest first.
     *
     * @return LengthAwarePaginator<int, Review>
     */
    public function publishedForProduct(string $productUuid, ReviewListFilterDTO $filter): LengthAwarePaginator;

    /**
     * A product's rating summary, computed from PUBLISHED reviews only.
     *
     * **`average` IS A DECIMAL STRING** (`"4.3"`), never a float. It is not money
     * — the minor-units rule does not apply here — but it crosses to a client
     * the same way money does, and for the same reason: a JSON number is parsed
     * as a float by most of them, and "4.3" is not representable. The platform
     * already renders every amount this way (005 §28); a rating is one more
     * decimal that must not drift.
     *
     * `distribution` is always filled for 1..5, including the zeros — a bar chart
     * with missing buckets is a bar chart the client has to repair.
     *
     * @return array{average: string, count: int, distribution: array<int, int>, with_images_count: int, sellers: array<int, array{store_uuid: string, count: int}>}
     */
    public function summaryForProduct(string $productUuid): array;

    /**
     * Averages and counts for a whole page of listing cards, in ONE query.
     *
     * **AN UNREVIEWED PRODUCT IS SIMPLY ABSENT FROM THE MAP**, never `"0.0"` with
     * a count of zero. A card that receives a zero renders "★ 0.0", which reads
     * as "rated badly" rather than "not rated yet" — the one wrong answer this
     * shape can give, and it is avoided by not answering.
     *
     * @param array<int, string> $productUuids
     *
     * @return array<string, array{average: string, count: int}>
     */
    public function summariesForProducts(array $productUuids): array;

    /**
     * One customer's own reviews, every status, newest first.
     *
     * ALL STATUSES ON PURPOSE (Reviews.md §8): a buyer must be able to see their
     * own pending review, or they will write it again believing it was lost.
     *
     * @return Collection<int, Review>
     */
    public function forCustomer(int $customerId): Collection;

    /**
     * The order lines this customer has already turned into reviews for a
     * product — what the eligibility read subtracts.
     *
     * @return array<int, string>
     */
    public function reviewedOrderLineUuids(int $customerId, string $productUuid): array;

    /**
     * A HARD delete (Reviews.md §8). A review is not an audit record — the
     * append-only rule belongs to Audit and Activity — and deleting must FREE the
     * `order_line_uuid` so the buyer can write a replacement from the same
     * purchase. A soft delete would leave a ghost row colliding with the unique
     * index and make the line permanently unreviewable.
     */
    public function delete(Review $review): void;
}
