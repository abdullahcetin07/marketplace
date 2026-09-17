<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;
use Illuminate\Http\Response;

/**
 * An attempt to break the ownership invariant (ADR-029).
 *
 * The Owner cannot be removed and their role cannot be reassigned directly;
 * ownership changes only by an atomic transfer to another active Seller member.
 * These are expected domain refusals, not bugs — a 4xx, never a 500.
 *
 * @see docs/modules/Organization.md §3.9–3.10
 */
final class OwnershipViolation extends BaseException
{
    protected int $status = Response::HTTP_UNPROCESSABLE_ENTITY;

    /**
     * The Owner's membership cannot be deleted — transfer ownership first.
     */
    public static function ownerCannotBeRemoved(): self
    {
        return self::make(__('organization.errors.owner_cannot_be_removed'))
            ->withContext(['reason' => 'owner_cannot_be_removed']);
    }

    /**
     * The Owner's role cannot be changed by a role edit — only by transfer.
     */
    public static function ownerRoleImmutable(): self
    {
        return self::make(__('organization.errors.owner_role_immutable'))
            ->withContext(['reason' => 'owner_role_immutable']);
    }

    /**
     * Ownership can only be transferred to an ACTIVE member of the same org.
     */
    public static function transferTargetInactive(): self
    {
        return self::make(__('organization.errors.transfer_target_inactive'))
            ->withContext(['reason' => 'transfer_target_inactive']);
    }

    /**
     * A Seller Employee cannot own — only a Seller may hold ownership.
     */
    public static function transferTargetCannotOwn(): self
    {
        return self::make(__('organization.errors.transfer_target_cannot_own'))
            ->withContext(['reason' => 'transfer_target_cannot_own']);
    }
}
