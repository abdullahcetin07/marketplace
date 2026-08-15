<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Infrastructure\Repositories;

use App\Modules\Loyalty\Domain\Contracts\LoyaltyLedgerRepositoryContract;
use App\Modules\Loyalty\Domain\Enums\LoyaltyPointSource;
use App\Modules\Loyalty\Domain\Models\LoyaltyLedgerEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class LoyaltyLedgerRepository implements LoyaltyLedgerRepositoryContract
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): LoyaltyLedgerEntry
    {
        return LoyaltyLedgerEntry::query()->create($attributes);
    }

    public function existsFor(LoyaltyPointSource $source, string $sourceUuid): bool
    {
        return LoyaltyLedgerEntry::query()
            ->where('source_type', $source->value)
            ->where('source_uuid', $sourceUuid)
            ->exists();
    }

    public function entryFor(LoyaltyPointSource $source, string $sourceUuid): ?LoyaltyLedgerEntry
    {
        return LoyaltyLedgerEntry::query()
            ->where('source_type', $source->value)
            ->where('source_uuid', $sourceUuid)
            ->first();
    }

    public function balanceFor(string $customerUuid): int
    {
        /*
        | SUM IN THE DATABASE, not a fetch-and-add. A customer with two years of
        | history should not have two years of rows travel to PHP so one integer
        | can come back, and `sum()` of an empty set is 0 — which is the right
        | answer for somebody who has earned nothing.
        */
        return (int) LoyaltyLedgerEntry::query()
            ->where('customer_uuid', $customerUuid)
            ->sum('points');
    }

    /**
     * @return LengthAwarePaginator<int, LoyaltyLedgerEntry>
     */
    public function historyFor(string $customerUuid, int $perPage): LengthAwarePaginator
    {
        return LoyaltyLedgerEntry::query()
            ->where('customer_uuid', $customerUuid)
            // Newest first: the last thing that happened is what a shopper opened
            // the page to see.
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
