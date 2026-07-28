<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * A Category Manager's verdict on a product proposal (§5).
 *
 * The reason is REQUIRED for the two negative outcomes and carried on their
 * events, because the seller is shown it. A rejection with no stated cause is
 * the fastest way to lose a merchant, and "needs revision" with no note is not
 * actionable at all. Approval needs no reason, which is why this is one nullable
 * field rather than three actions with three signatures.
 */
final class ModerationDecisionDTO extends BaseDTO
{
    public function __construct(
        public readonly ?string $reason = null,
        public readonly ?int $moderatedBy = null,
    ) {}
}
