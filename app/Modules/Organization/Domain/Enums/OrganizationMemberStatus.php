<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * Whether a membership is currently effective.
 *
 *   Active    — the member may act within the org, per their role.
 *   Suspended — the membership is frozen; the member cannot act, but the row is
 *               retained (they may be reinstated without re-inviting).
 *
 * @see App\Modules\Organization\Domain\Models\OrganizationMember
 */
enum OrganizationMemberStatus: string
{
    use HasEnumHelpers;

    case Active = 'active';
    case Suspended = 'suspended';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
