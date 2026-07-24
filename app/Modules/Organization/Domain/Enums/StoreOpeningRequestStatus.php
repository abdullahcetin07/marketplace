<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * The lifecycle of a Store Opening Request (ADR-028, §7.1).
 *
 *   Draft     — the seller is composing it; invisible to admins.
 *   Pending   — submitted and in the admin review queue. (The spec's separate
 *               "Submitted" step is collapsed into Pending, as §7.1 permits —
 *               the seller's submit IS the entry into the queue.)
 *   Approved  — an admin approved it; `StoreOpeningApproved` fires and the Store
 *               module (future) creates the Store. No Store is created here.
 *   Rejected  — an admin declined it, with notes.
 *   Cancelled — the organization withdrew it before a decision.
 *
 * @see docs/modules/Organization.md §7
 */
enum StoreOpeningRequestStatus: string
{
    use HasEnumHelpers;

    case Draft = 'draft';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * Whether an admin may decide it — only a pending request is in the queue.
     */
    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Whether the organization may still withdraw it.
     */
    public function isCancellable(): bool
    {
        return $this === self::Draft || $this === self::Pending;
    }
}
