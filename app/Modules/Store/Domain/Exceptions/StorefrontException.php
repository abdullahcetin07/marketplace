<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;
use Illuminate\Http\Response;

/**
 * The requested public storefront is not available.
 *
 * Returned for BOTH a missing slug and a non-live store (draft/paused/closed/
 * suspended/archived) — one message, one status, so the public surface never
 * discloses that a non-active store exists (ADR-034).
 *
 * @see docs/modules/Store.md §12
 */
final class StorefrontException extends BaseException
{
    protected int $status = Response::HTTP_NOT_FOUND;

    public static function unavailable(): self
    {
        return self::make(__('errors.store_unavailable'))
            ->withContext(['reason' => 'store_unavailable']);
    }
}
