<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Contracts;

use App\Modules\Order\Domain\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persistence port for the address book (ADR-056).
 *
 * EVERY METHOD IS CUSTOMER-SCOPED, without exception, and that is the point: an
 * address is the most sensitive thing this module stores — it is where a person
 * lives — and a repository that could return one without being told whose it is
 * would make the ownership check something a caller remembers rather than
 * something the port enforces.
 *
 * @see App\Modules\Order\Infrastructure\Repositories\CustomerAddressRepository
 */
interface CustomerAddressRepositoryContract
{
    /**
     * @return Collection<int, CustomerAddress>
     */
    public function forCustomer(int $customerId): Collection;

    /**
     * One address, BY UUID AND BY OWNER.
     *
     * Both arguments are required rather than the uuid alone, so "not yours" and
     * "does not exist" resolve to the same null — a caller cannot accidentally
     * distinguish them, and an attacker cannot use the difference to confirm an
     * address uuid is real.
     */
    public function findForCustomer(string $uuid, int $customerId): ?CustomerAddress;

    public function defaultShippingFor(int $customerId): ?CustomerAddress;

    public function defaultBillingFor(int $customerId): ?CustomerAddress;

    /**
     * Clear the given default flag on every other address of this customer.
     *
     * The action's half of the "one default per purpose" guarantee — the partial
     * unique index only exists on PostgreSQL (see the migration), so this is what
     * the suite exercises and what keeps both engines consistent.
     */
    public function clearDefault(int $customerId, string $column, ?int $exceptId = null): void;
}
