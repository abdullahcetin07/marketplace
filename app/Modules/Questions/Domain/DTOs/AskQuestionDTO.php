<?php

declare(strict_types=1);

namespace App\Modules\Questions\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * "Satıcıya sor" — what the shopper actually supplies (ADR-070).
 *
 * **IT CARRIES NO SELLER, AND THAT IS THE WHOLE SECURITY MODEL.** The target is
 * read from the featured offer inside `AskQuestionAction` and snapshotted there,
 * so a shopper cannot aim a question at a shop that is not selling the product —
 * and a merchant cannot be made to answer for goods that were never theirs. A
 * field here would be a field somebody could set.
 *
 * **AND NO PURCHASE, EITHER** — the mirror of `SubmitReviewDTO`, which is built
 * around one. A question is asked to decide WHETHER to buy, so gating it on a
 * purchase would defeat the feature; being a signed-in customer is the whole bar
 * (ADR-070).
 *
 * `askerName` ARRIVES ALREADY MASKED ("Abdullah Ç."), computed at the controller
 * from the authenticated actor. Not from input: a display name a client could set
 * is one it could set to somebody else's.
 */
final class AskQuestionDTO extends BaseDTO
{
    public function __construct(
        public readonly string $productUuid,
        public readonly string $body,
        public readonly int $customerId,
        public readonly string $customerUuid,
        public readonly string $askerName,
    ) {}
}
