<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;
use App\Modules\Store\Domain\Enums\StoreStatus;
use Illuminate\Http\Response;

/**
 * A storefront could not make an operational transition.
 *
 * Expected domain refusals — pausing a draft, activating an archived store — not
 * incidents. Never a 500.
 *
 * @see docs/modules/Store.md §7
 */
final class StoreException extends BaseException
{
    protected int $status = Response::HTTP_UNPROCESSABLE_ENTITY;

    /**
     * The store is not in a state that allows the requested transition.
     */
    public static function invalidTransition(StoreStatus $from, string $to): self
    {
        return self::make('This store cannot change state that way.')
            ->withContext([
                'reason' => 'invalid_transition',
                'from' => $from->value,
                'to' => $to,
            ]);
    }
}
