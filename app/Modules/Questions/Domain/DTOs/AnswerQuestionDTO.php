<?php

declare(strict_types=1);

namespace App\Modules\Questions\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * The seller's answer, and who at the seller wrote it (ADR-071).
 *
 * **`answeredBy` IS A USER, NOT AN ORGANISATION**, because a Seller Employee may
 * answer (§6) and "which colleague said this" is the question a merchant asks
 * when a customer disputes an answer. The public payload never carries it — the
 * shopper sees the SHOP's answer, not an individual's.
 *
 * NO STATUS FIELD. Answering IS the transition, so a DTO that could also carry
 * one would be a second way to say the same thing — and a way to say a different
 * one.
 */
final class AnswerQuestionDTO extends BaseDTO
{
    public function __construct(
        public readonly string $answerBody,
        public readonly int $answeredBy,
    ) {}
}
