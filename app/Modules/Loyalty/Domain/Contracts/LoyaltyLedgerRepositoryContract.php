<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Contracts;

use App\Modules\Loyalty\Domain\Enums\LoyaltyPointSource;
use App\Modules\Loyalty\Domain\Models\LoyaltyLedgerEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Reads and the one write the ledger allows (ADR-081).
 *
 * There is no `update` and no `delete` here, and their absence is the interface
 * saying what the model enforces: a correction is another row.
 */
interface LoyaltyLedgerRepositoryContract
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): LoyaltyLedgerEntry;

    /**
     * Whether this source has already been credited — the idempotency read.
     */
    public function existsFor(LoyaltyPointSource $source, string $sourceUuid): bool;

    /**
     * **THE BALANCE IS A SUM, NOT A COLUMN** (ADR-081). Signed, so Phase 2's
     * redemptions subtract themselves without a second code path.
     */
    public function balanceFor(string $customerUuid): int;

    /**
     * How many points this basket has already had back.
     *
     * **THE RUNNING TOTAL A DELTA IS MEASURED AGAINST** (ADR-084). Partial refunds
     * arrive one at a time and in any order; each credits the difference between
     * where the basket should be and where it is, and this is the second half of
     * that subtraction.
     */
    public function reversedPointsFor(string $checkoutGroupUuid): int;

    /**
     * The one row written for this source, or null.
     *
     * **THE ROW, NOT JUST ITS NUMBER.** A reversal needs what was spent AND whose
     * points they were, and `(source_type, source_uuid)` is unique — so there is
     * exactly one row to hand back and no ambiguity about which.
     */
    public function entryFor(LoyaltyPointSource $source, string $sourceUuid): ?LoyaltyLedgerEntry;

    /**
     * @return LengthAwarePaginator<int, LoyaltyLedgerEntry>
     */
    public function historyFor(string $customerUuid, int $perPage): LengthAwarePaginator;
}
