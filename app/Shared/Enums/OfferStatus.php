<?php

declare(strict_types=1);

namespace App\Shared\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * Lifecycle of a seller's offer (a priced listing against a catalogue product).
 *
 * Declared in Sprint 0 as a cross-module contract; the Offer module itself
 * arrives in a later sprint.
 */
enum OfferStatus: string
{
    use HasEnumHelpers;

    case Draft = 'draft';
    case Pending = 'pending';
    case Active = 'active';
    case Paused = 'paused';
    case OutOfStock = 'out_of_stock';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';

    /**
     * Statuses in which the offer competes for the buy box.
     *
     * @return array<int, self>
     */
    public static function purchasable(): array
    {
        return [self::Active];
    }

    /**
     * Statuses that still occupy a listing slot and stay indexed in
     * OpenSearch (greyed out rather than removed).
     *
     * @return array<int, self>
     */
    public static function searchable(): array
    {
        return [self::Active, self::OutOfStock];
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Pending, self::Withdrawn],
            self::Pending => [self::Active, self::Rejected],
            self::Active => [self::Paused, self::OutOfStock, self::Expired, self::Withdrawn],
            self::Paused => [self::Active, self::Withdrawn],
            self::OutOfStock => [self::Active, self::Withdrawn],
            self::Rejected => [self::Draft],
            self::Expired => [self::Draft],
            self::Withdrawn => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isPurchasable(): bool
    {
        return in_array($this, self::purchasable(), true);
    }

    public function isSearchable(): bool
    {
        return in_array($this, self::searchable(), true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Pending => 'warning',
            self::Active => 'success',
            self::Paused, self::OutOfStock => 'warning',
            self::Rejected, self::Expired => 'danger',
            self::Withdrawn => 'gray',
        };
    }
}
