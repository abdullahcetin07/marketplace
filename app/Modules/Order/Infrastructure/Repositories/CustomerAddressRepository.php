<?php

declare(strict_types=1);

namespace App\Modules\Order\Infrastructure\Repositories;

use App\Modules\Order\Domain\Contracts\CustomerAddressRepositoryContract;
use App\Modules\Order\Domain\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Collection;

/**
 * The address book's read vocabulary — every method customer-scoped (ADR-056).
 *
 * `country` IS EAGER LOADED because every rendering of an address shows it and
 * `toSnapshot()` reads it: freezing an order's address with a lazy load would
 * throw in development and issue a query per line in production.
 *
 * @see App\Modules\Order\Domain\Contracts\CustomerAddressRepositoryContract
 */
final class CustomerAddressRepository implements CustomerAddressRepositoryContract
{
    /**
     * @var list<string>
     */
    private array $with = ['country'];

    /**
     * @return Collection<int, CustomerAddress>
     */
    public function forCustomer(int $customerId): Collection
    {
        /** @var Collection<int, CustomerAddress> $addresses */
        $addresses = CustomerAddress::query()
            ->with($this->with)
            ->forCustomer($customerId)
            // Defaults first: the picker's top entries are the ones a customer
            // almost always wants.
            ->orderByDesc('is_default_shipping')
            ->orderByDesc('is_default_billing')
            ->orderBy('label')
            ->get();

        return $addresses;
    }

    /**
     * BOTH ARGUMENTS REQUIRED, so "not yours" and "does not exist" are the same
     * null — an attacker cannot use the difference to confirm an address uuid is
     * real.
     */
    public function findForCustomer(string $uuid, int $customerId): ?CustomerAddress
    {
        return CustomerAddress::query()
            ->with($this->with)
            ->forCustomer($customerId)
            ->where('uuid', $uuid)
            ->first();
    }

    public function defaultShippingFor(int $customerId): ?CustomerAddress
    {
        return CustomerAddress::query()
            ->with($this->with)
            ->forCustomer($customerId)
            ->where('is_default_shipping', true)
            ->first();
    }

    public function defaultBillingFor(int $customerId): ?CustomerAddress
    {
        return CustomerAddress::query()
            ->with($this->with)
            ->forCustomer($customerId)
            ->where('is_default_billing', true)
            ->first();
    }

    /**
     * The action's half of "one default per purpose".
     *
     * A SET-BASED UPDATE rather than a loop: a customer can hold many addresses,
     * and this runs inside the transaction that is about to set the new default —
     * the shorter that transaction, the smaller the window in which a concurrent
     * read sees two defaults or none.
     *
     * The column name is restricted to the two real flags rather than passed
     * through: this method writes a column name into a query, and an unvalidated
     * one would be an injection point in the one place a repository looks
     * innocent.
     */
    public function clearDefault(int $customerId, string $column, ?int $exceptId = null): void
    {
        if (! in_array($column, ['is_default_shipping', 'is_default_billing'], true)) {
            return;
        }

        CustomerAddress::query()
            ->forCustomer($customerId)
            ->where($column, true)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->update([$column => false]);
    }
}
