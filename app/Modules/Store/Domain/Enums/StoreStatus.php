<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * The operational lifecycle of a storefront (ADR-034 §3.1).
 *
 * A MODULE-SPECIFIC enum, not the shared `Status` — a storefront's lifecycle is
 * its own and must not be overloaded onto the generic column (the
 * `OrganizationStatus` / `OrderStatus` precedent; CLAUDE.md "enum or lookup
 * table"). No `Enum` suffix (ADR-007).
 *
 *   Draft     — created from an approved request; not publicly reachable. The
 *               seller completes branding/domains/locale, then activates.
 *   Active    — live and serving the storefront.
 *   Paused    — vacation mode: temporarily not selling; the page shows a notice.
 *               Seller-controlled and self-reversible.
 *   Closed    — the seller closed the store; reversible by reopening.
 *   Suspended — an admin froze it (policy breach); only an admin reinstates.
 *   Archived  — retired; read-only end-state, distinct from the recoverable
 *               removal that `deleted_at` represents.
 *
 * @see docs/modules/Store.md §3.1
 */
enum StoreStatus: string
{
    use HasEnumHelpers;

    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Closed = 'closed';
    case Suspended = 'suspended';
    case Archived = 'archived';

    /**
     * Whether the storefront is meant to serve the public. Being Active is the
     * status half of "live"; a verified serving domain is the other half, which
     * only the Store aggregate can answer (Store::isLive()).
     */
    public function isServing(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the public storefront may render at all. Only a live store is
     * publicly visible (ADR-034); every other state 404s the public surface.
     */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether a seller may still change the operational state. A suspended store
     * is frozen (admin-only) and an archived one is terminal.
     */
    public function isSellerMutable(): bool
    {
        return ! in_array($this, [self::Suspended, self::Archived], true);
    }

    /**
     * Search engines should not index a non-live storefront (§5) — a paused,
     * draft or closed page must not leak into results.
     */
    public function robotsDirective(): string
    {
        return $this === self::Active ? 'index,follow' : 'noindex,nofollow';
    }
}
