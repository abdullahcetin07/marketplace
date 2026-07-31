<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;
use Illuminate\Http\Response;

/**
 * Stock could not be moved the way somebody asked.
 *
 * EXPECTED DOMAIN REFUSALS, NOT INCIDENTS — the last unit going to whoever asked
 * first is the system working, not failing. `$reportable` stays false
 * (BaseException's default): two buyers racing for one item is the ordinary case
 * this module exists to arbitrate, and paging somebody at 3am for it would be
 * alarming on the platform's happy path.
 *
 * Each carries a machine-readable `reason`, so a caller — Order, when it exists
 * — can branch on the specific refusal rather than parse a sentence.
 *
 * @see docs/modules/Inventory.md §3.2
 */
final class InventoryException extends BaseException
{
    protected int $status = Response::HTTP_UNPROCESSABLE_ENTITY;

    /**
     * §3.2 — the pool cannot cover the request.
     *
     * THE REFUSAL THIS MODULE EXISTS FOR. It carries both numbers because a
     * caller that asked for three and can have two needs to know which, and
     * re-querying would race against the very concurrency this guards.
     */
    public static function insufficientStock(string $variantUuid, int $requested, int $available): self
    {
        return self::make('There is not enough stock to reserve that quantity.')
            ->withContext([
                'reason' => 'insufficient_stock',
                'variant_uuid' => $variantUuid,
                'requested' => $requested,
                'available' => $available,
            ]);
    }

    /**
     * No stock pool exists for this (org, variant).
     *
     * Distinct from "not enough" on purpose: one means the seller has sold out,
     * the other means they never listed it. A caller told the wrong one would
     * retry something that can never succeed.
     */
    public static function stockItemNotFound(string $variantUuid, string $sellingOrgUuid): self
    {
        return self::make('That seller has no stock record for this variant.')
            ->withContext([
                'reason' => 'stock_item_not_found',
                'variant_uuid' => $variantUuid,
                'selling_org_uuid' => $sellingOrgUuid,
            ]);
    }

    /**
     * `release` or `commit` was handed a reference nothing was ever reserved
     * under.
     *
     * NOT the same as acting on an already-finished reservation — that is a
     * no-op by design (§3.2), because a retried commit must not be an error.
     * This is a reference that never existed, which is a caller bug worth
     * surfacing.
     */
    public static function reservationNotFound(string $reference): self
    {
        return self::make('No reservation exists under that reference.')
            ->withContext([
                'reason' => 'reservation_not_found',
                'reference' => $reference,
            ]);
    }

    /**
     * A quantity that cannot mean anything — zero or negative units.
     */
    public static function invalidQuantity(int $quantity): self
    {
        return self::make('A stock quantity must be a positive number.')
            ->withContext([
                'reason' => 'invalid_quantity',
                'quantity' => $quantity,
            ]);
    }
}
