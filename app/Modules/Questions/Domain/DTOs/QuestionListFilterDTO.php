<?php

declare(strict_types=1);

namespace App\Modules\Questions\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * How a shopper narrowed the Q&A list (ADR-070, Questions.md §8).
 *
 * **ONE FILTER, AND IT IS THE SELLER** — "bu satıcıya sorulanlar", the same shape
 * Reviews' seller filter has and for the same reason: one product page carries
 * every seller's Q&A, so narrowing to the merchant a shopper is actually buying
 * from is the question they have.
 *
 * NO RATING FILTER AND NO SORT, because there is no rating and no votes (§11).
 * Newest first, always — an unanswered-then-answered pair is most useful when it
 * is recent, and "most helpful" needs votes this module does not have.
 *
 * NO STATUS FIELD, which is the safety property: the public list reads answered
 * and un-hidden only, so a filter that could name a status would be a way to ask
 * for pending ones.
 */
final class QuestionListFilterDTO extends BaseDTO
{
    public function __construct(
        public readonly ?string $sellerStoreUuid = null,
        public readonly int $page = 1,
        public readonly int $perPage = 20,
    ) {}
}
