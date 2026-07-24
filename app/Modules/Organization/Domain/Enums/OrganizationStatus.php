<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * The lifecycle of a legal seller company.
 *
 * A MODULE-SPECIFIC enum, not the shared `Status` — the shared enum has no
 * `Approved`/`Rejected`, and an organization's lifecycle is its own (the
 * `OrderStatus` precedent; CLAUDE.md "enum or lookup table"). No `Enum` suffix
 * (ADR-007).
 *
 *   Pending   — registered, KYC not yet passed; may not open stores.
 *   Approved  — verified and operational.
 *   Rejected  — KYC failed; terminal unless an admin re-opens it.
 *   Suspended — temporarily disabled by an admin; restorable.
 *   Archived  — retired; read-only. A business end-state, distinct from the
 *               recoverable removal that `deleted_at` represents.
 *
 * @see docs/modules/Organization.md §3.1
 */
enum OrganizationStatus: string
{
    use HasEnumHelpers;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Archived = 'archived';

    /**
     * Whether the organization may operate — the gate for opening stores and
     * acting as a seller. Only an approved company is live.
     */
    public function isOperational(): bool
    {
        return $this === self::Approved;
    }

    /**
     * Whether an admin may still act on a KYC decision from this state.
     */
    public function isPendingReview(): bool
    {
        return $this === self::Pending;
    }
}
