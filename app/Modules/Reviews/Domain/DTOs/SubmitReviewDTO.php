<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * "Bunu değerlendirmek istiyorum" — what a buyer actually supplies (ADR-067).
 *
 * **IT CARRIES NO SELLER, NO STORE AND NO VARIANT, AND THAT IS THE POINT.** Those
 * three are copied from the delivered order line inside `CreateReviewAction`,
 * authoritatively (ADR-066) — a buyer cannot attribute their review to a seller
 * they did not buy from, and a seller cannot be damned by a review of a purchase
 * that was never theirs. A field here would be a field somebody could set.
 *
 * **`orderLineUuid` IS THE ONLY THING THAT DECIDES WHAT IS BEING REVIEWED.** Not
 * the product — the product is carried for the eligibility lookup, and the
 * action re-verifies that the line is this customer's, delivered, and unreviewed
 * before it trusts either. The storefront's eligibility read is a convenience,
 * never the authority.
 *
 * **`authorName` ARRIVES ALREADY MASKED** ("Abdullah Ç."), computed at the
 * controller from the authenticated actor and stored as given. It is not taken
 * from input: a display name a client could set is a display name a client could
 * set to somebody else's.
 */
final class SubmitReviewDTO extends BaseDTO
{
    public function __construct(
        public readonly string $orderLineUuid,
        public readonly int $rating,
        public readonly ?string $body,
        public readonly string $productUuid,
        public readonly int $customerId,
        public readonly string $customerUuid,
        public readonly string $authorName,
    ) {}
}
