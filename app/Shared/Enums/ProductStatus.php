<?php

declare(strict_types=1);

namespace App\Shared\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * Catalogue product publication lifecycle.
 *
 * A product is the shared catalogue entry that many sellers attach offers to,
 * so its moderation state is deliberately independent of OfferStatus.
 *
 * Declared in Sprint 0 as a cross-module contract; the Product module itself
 * arrives in a later sprint.
 */
enum ProductStatus: string
{
    use HasEnumHelpers;

    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Rejected = 'rejected';
    case Archived = 'archived';

    /**
     * Statuses visible on the storefront and indexed for search.
     *
     * @return array<int, self>
     */
    public static function public(): array
    {
        return [self::Published];
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingReview, self::Archived],
            self::PendingReview => [self::Published, self::Rejected],
            self::Published => [self::Unpublished, self::Archived],
            self::Unpublished => [self::Published, self::Archived],
            self::Rejected => [self::Draft, self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isPublic(): bool
    {
        return in_array($this, self::public(), true);
    }

    /**
     * Whether offers may be attached to a product in this state.
     */
    public function acceptsOffers(): bool
    {
        return in_array($this, [self::Published, self::Unpublished], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingReview => 'warning',
            self::Published => 'success',
            self::Unpublished => 'warning',
            self::Rejected => 'danger',
            self::Archived => 'gray',
        };
    }
}
