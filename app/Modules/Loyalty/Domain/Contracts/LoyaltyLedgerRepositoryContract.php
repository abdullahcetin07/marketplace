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
     * @return LengthAwarePaginator<int, LoyaltyLedgerEntry>
     */
    public function historyFor(string $customerUuid, int $perPage): LengthAwarePaginator;
}
