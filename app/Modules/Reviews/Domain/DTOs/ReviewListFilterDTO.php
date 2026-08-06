<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * How a shopper narrowed the review list (ADR-069, Reviews.md §7).
 *
 * **TWO OF THESE FILTERS ARE THE OWNER'S REQUIREMENTS, NOT CONVENIENCES.**
 * `sellerStoreUuid` is "bu satıcıdan alanlar ne demiş" — the whole reason a
 * review carries a seller tag (ADR-066) rather than being written about a
 * seller — and `withImages` is the "sadece resimli" toggle, which is what a
 * shopper reaches for when the text starts looking generic.
 *
 * **THERE IS NO SORT FIELD.** Newest first, always. The obvious second option is
 * "most helpful", and there are no helpful-votes in v1 (§11) — offering a sort
 * with one choice would be a control that does nothing.
 *
 * NO STATUS FIELD EITHER, and that is a safety property rather than an omission:
 * the public list reads `Published` and nothing else, so a filter that could name
 * a status would be a way to ask for pending reviews.
 */
final class ReviewListFilterDTO extends BaseDTO
{
    public function __construct(
        public readonly ?string $sellerStoreUuid = null,
        public readonly bool $withImages = false,
        public readonly ?int $rating = null,
        public readonly int $page = 1,
        public readonly int $perPage = 20,
    ) {}
}
