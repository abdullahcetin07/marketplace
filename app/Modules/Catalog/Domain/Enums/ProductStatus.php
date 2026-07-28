<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * The moderation lifecycle of a catalog product (Catalog.md §2.6/§3.1).
 *
 * THE PRODUCT IS THE REQUEST (§5). Unlike a Store Opening Request — where
 * approval creates a *different* thing — approving a product creates nothing
 * new; the product simply moves to `Published`. So the moderation state lives
 * here, on the product's own status, and there is no separate
 * `ProductCreationRequest` entity to keep in sync.
 *
 *   Draft          — the seller is still authoring it. Not visible to anyone else.
 *   PendingReview  — submitted; sitting in the Category Manager's queue.
 *   NeedsRevision  — sent back with a reason. The seller edits and re-submits.
 *                    The humane default for a fixable problem, mirroring the
 *                    KYC document pattern already shipped (§3.1).
 *   Published      — live in the shared catalog. Sellers may attach Offers to it
 *                    once Offer exists; today it is simply the approved state.
 *   Rejected       — refused outright. Recoverable only by editing back to Draft.
 *   Archived       — delisted, terminal. NEVER hard-deleted, because future
 *                    Offers will reference it (§3.5).
 *
 * MODULE-OWNED (§2.6). A Sprint-0 placeholder `App\Shared\Enums\ProductStatus`
 * exists and is deliberately NOT reused — the Store precedent is that a module
 * owns its real status enum. The placeholder has no `NeedsRevision` case and an
 * `Unpublished` case this lifecycle does not use; leave it untouched.
 *
 * No `Enum` suffix (ADR-007).
 *
 * @see docs/modules/Catalog.md §3.1
 */
enum ProductStatus: string
{
    use HasEnumHelpers;

    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case NeedsRevision = 'needs_revision';
    case Published = 'published';
    case Rejected = 'rejected';
    case Archived = 'archived';

    /**
     * The states a moderator's review may set — never the states the seller
     * drives (Draft, PendingReview).
     *
     * @return array<int, self>
     */
    public static function moderationOutcomes(): array
    {
        return [self::Published, self::NeedsRevision, self::Rejected];
    }

    /**
     * Statuses that belong in the search index and, from the Offer sprint, may
     * carry offers.
     *
     * @return array<int, self>
     */
    public static function searchable(): array
    {
        return [self::Published];
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // Archiving a draft is how a seller abandons a proposal without
            // the row disappearing from the moderation record.
            self::Draft => [self::PendingReview, self::Archived],
            self::PendingReview => self::moderationOutcomes(),
            self::NeedsRevision => [self::PendingReview, self::Archived],
            self::Published => [self::Archived],
            // A rejected proposal is not a dead end: the seller may rework it
            // into a fresh draft, which re-enters the queue on submission.
            self::Rejected => [self::Draft, self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Whether the proposing seller may still edit the product's content.
     *
     * Deliberately excludes PendingReview: a product must not change underneath
     * the moderator who is reviewing it.
     */
    public function isSellerEditable(): bool
    {
        return in_array($this, [self::Draft, self::NeedsRevision, self::Rejected], true);
    }

    /**
     * Whether the product is waiting on a moderator right now — the queue's
     * membership test.
     */
    public function awaitsModeration(): bool
    {
        return $this === self::PendingReview;
    }

    public function isPublished(): bool
    {
        return $this === self::Published;
    }

    public function isSearchable(): bool
    {
        return in_array($this, self::searchable(), true);
    }

    /**
     * Terminal states hold no further transitions.
     */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Badge colour for the Filament panels.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingReview => 'warning',
            self::NeedsRevision => 'warning',
            self::Published => 'success',
            self::Rejected => 'danger',
            self::Archived => 'gray',
        };
    }
}
