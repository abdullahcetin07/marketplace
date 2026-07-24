<?php

declare(strict_types=1);

namespace App\Shared\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * Generic lifecycle status shared by any model using the HasStatus trait.
 *
 * Domain-specific lifecycles (store approval, offer negotiation, product
 * publication) have their own dedicated enums — do not overload this one.
 */
enum Status: string
{
    use HasEnumHelpers;

    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';
    case Suspended = 'suspended';
    case Archived = 'archived';

    /**
     * Statuses that make a record visible to the public storefront.
     *
     * @return array<int, self>
     */
    public static function visible(): array
    {
        return [self::Active];
    }

    /**
     * Tailwind/Filament colour token used by badges across both panels.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Inactive => 'gray',
            self::Pending => 'warning',
            self::Suspended => 'danger',
            self::Archived => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Draft => 'heroicon-o-pencil-square',
            self::Active => 'heroicon-o-check-circle',
            self::Inactive => 'heroicon-o-pause-circle',
            self::Pending => 'heroicon-o-clock',
            self::Suspended => 'heroicon-o-no-symbol',
            self::Archived => 'heroicon-o-archive-box',
        };
    }

    public function isVisible(): bool
    {
        return in_array($this, self::visible(), true);
    }

    /**
     * Whether the record may still be edited by its owner.
     */
    public function isMutable(): bool
    {
        return ! in_array($this, [self::Archived, self::Suspended], true);
    }
}
