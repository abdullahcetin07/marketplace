<?php

declare(strict_types=1);

namespace App\Shared\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * Merchant onboarding and standing lifecycle.
 *
 * Declared in Sprint 0 because the enum is a contract other modules code
 * against; the Store module itself arrives in a later sprint.
 *
 * Flow: Pending -> UnderReview -> Approved -> Active
 *                      \-> Rejected
 *       Active <-> Suspended, Active -> Closed (terminal)
 */
enum StoreStatus: string
{
    use HasEnumHelpers;

    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Active = 'active';
    case Suspended = 'suspended';
    case Rejected = 'rejected';
    case Closed = 'closed';

    /**
     * Statuses in which a store may list and sell.
     *
     * @return array<int, self>
     */
    public static function sellable(): array
    {
        return [self::Active];
    }

    /**
     * Legal transitions. Any transition not listed here must be rejected by
     * the domain service rather than silently applied.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::UnderReview, self::Rejected],
            self::UnderReview => [self::Approved, self::Rejected],
            self::Approved => [self::Active, self::Suspended],
            self::Active => [self::Suspended, self::Closed],
            self::Suspended => [self::Active, self::Closed],
            self::Rejected => [self::Pending],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function canSell(): bool
    {
        return in_array($this, self::sellable(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending, self::UnderReview => 'warning',
            self::Approved, self::Active => 'success',
            self::Suspended, self::Rejected => 'danger',
            self::Closed => 'gray',
        };
    }
}
