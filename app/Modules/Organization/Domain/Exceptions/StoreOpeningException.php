<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;
use Illuminate\Http\Response;

/**
 * A Store Opening Request could not proceed (ADR-028).
 *
 * Expected domain refusals — the store limit is reached, or the request is not
 * in a state that permits the transition. Never a 500.
 *
 * @see docs/modules/Organization.md §7
 */
final class StoreOpeningException extends BaseException
{
    protected int $status = Response::HTTP_UNPROCESSABLE_ENTITY;

    /**
     * The organization has used its whole store allowance (override → plan →
     * config). Carries the limit for the message.
     */
    public static function limitReached(?int $limit): self
    {
        return self::make('This organization has reached its store limit.')
            ->withContext(['reason' => 'store_limit_reached', 'limit' => $limit]);
    }

    /**
     * The request is not in a state that allows this transition (e.g. approving
     * something already decided, submitting a non-draft).
     */
    public static function invalidTransition(): self
    {
        return self::make('This request cannot change state that way.')
            ->withContext(['reason' => 'invalid_transition']);
    }
}
