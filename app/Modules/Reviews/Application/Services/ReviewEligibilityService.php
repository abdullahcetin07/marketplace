<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application\Services;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Reviews\Domain\Contracts\ReviewRepositoryContract;

/**
 * Which purchases a buyer may still write about (ADR-067).
 *
 * **ONE SUBTRACTION, AND IT IS THE WHOLE SERVICE**: the delivered lines Order
 * knows about, minus the ones this module has already turned into reviews. Two
 * modules each hold half the answer and neither may read the other's table, so
 * the arithmetic has to happen somewhere — here, once, rather than in the
 * controller that lists them and again in the action that verifies them.
 *
 * **THAT SINGLE HOME IS THE POINT.** The eligibility screen and the create
 * action ask the same question, and if they asked it two different ways the gap
 * between the two spellings would be the exploit: a line the screen refuses to
 * show but the action accepts is a review of a purchase that never happened.
 *
 * A SERVICE, NOT AN ACTION (CLAUDE.md): it owns no transaction and writes
 * nothing. It cannot be named with one verb and one noun because it is a read
 * that composes two others.
 *
 * @see docs/modules/Reviews.md §8
 */
final readonly class ReviewEligibilityService
{
    public function __construct(
        private OrderQueryContract $orders,
        private ReviewRepositoryContract $reviews,
    ) {}

    /**
     * Delivered lines for (customer, product) that have not been reviewed yet.
     *
     * The same array shape `deliveredPurchaseLines()` returns, filtered — the
     * caller gets the seller tag and the purchase label it needs to say WHICH
     * purchase it is offering, without a second read.
     *
     * **ALREADY-REVIEWED INCLUDES PENDING ONES.** A line whose review is still
     * waiting for a moderator is not eligible: offering it back would let the
     * buyer submit a second review of one purchase and meet the unique index as
     * a 500 rather than a refusal.
     *
     * @return array<int, array{order_line_uuid: string, store_uuid: string, selling_org_uuid: string, variant_uuid: string|null, variant_label: string|null, product_title: string, purchased_at: string|null}>
     */
    public function eligibleLines(int $customerId, string $customerUuid, string $productUuid): array
    {
        $reviewed = $this->reviews->reviewedOrderLineUuids($customerId, $productUuid);

        if ($reviewed === []) {
            return $this->orders->deliveredPurchaseLines($customerUuid, $productUuid);
        }

        return array_values(array_filter(
            $this->orders->deliveredPurchaseLines($customerUuid, $productUuid),
            static fn (array $line): bool => ! in_array($line['order_line_uuid'], $reviewed, true),
        ));
    }

    /**
     * The one eligible line with this uuid, or null.
     *
     * **WHAT THE CREATE ACTION TRUSTS INSTEAD OF THE REQUEST.** It returns the
     * LINE rather than a boolean because the answer is not only "may they" — it
     * is also the seller tag, the org and the variant the review will be stamped
     * with (ADR-066). A boolean here would have forced the action to read the
     * client's values for those, which is the attribution hole the whole design
     * closes.
     *
     * @return array{order_line_uuid: string, store_uuid: string, selling_org_uuid: string, variant_uuid: string|null, variant_label: string|null, product_title: string, purchased_at: string|null}|null
     */
    public function eligibleLine(int $customerId, string $customerUuid, string $productUuid, string $orderLineUuid): ?array
    {
        foreach ($this->eligibleLines($customerId, $customerUuid, $productUuid) as $line) {
            if ($line['order_line_uuid'] === $orderLineUuid) {
                return $line;
            }
        }

        return null;
    }
}
