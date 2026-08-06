<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * A moderator's verdict, and who gave it (ADR-068).
 *
 * **REVIEWS' OWN, NOT CATALOG'S `ModerationDecisionDTO`.** The two carry almost
 * the same fields and reusing one would mean importing Catalog — the thing this
 * module is built not to do. It would also couple two moderation flows that only
 * happen to look alike today: a product can be sent back for revision and a
 * review cannot (@see `ReviewStatus`), so the shapes are already diverging.
 *
 * **`reason` IS FOR THE REJECTION AND FOR THE INTERNAL RECORD.** The buyer is not
 * shown it in v1 (Reviews.md §6): telling somebody why their opinion was refused
 * invites an argument the platform has no process for. It is written so a second
 * moderator, or a support agent taking the complaint, can see what the first one
 * decided and why.
 */
final class ReviewModerationDTO extends BaseDTO
{
    public function __construct(
        public readonly int $moderatedBy,
        public readonly ?string $reason = null,
    ) {}
}
