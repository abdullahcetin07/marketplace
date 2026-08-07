<?php

declare(strict_types=1);

namespace App\Modules\Questions\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * An admin takes a question down (ADR-071).
 *
 * **THE REASON IS REQUIRED HERE AND NULLABLE IN THE COLUMN**, deliberately: the
 * rule belongs where the actor is known. A hide is reversible and invisible to
 * everyone it affects, so the note explaining it is the only trace of WHY — and
 * the next admin looking at a hidden question deserves better than a blank field.
 *
 * NOBODY OUTSIDE THE PANEL SEES IT. Not the asker, not the seller: telling a
 * shopper their question was hidden and why invites an argument the platform has
 * no process for, and telling the seller invites them to relay it.
 */
final class HideQuestionDTO extends BaseDTO
{
    public function __construct(
        public readonly int $hiddenBy,
        public readonly string $reason,
    ) {}
}
