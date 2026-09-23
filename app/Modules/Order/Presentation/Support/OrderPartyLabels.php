<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Support;

use App\Core\Domain\Contracts\StoreQueryContract;
use App\Models\Customer;

/**
 * Who the two parties on an order actually are, by name (Order.md §15).
 *
 * **AN ORDER STORES UUIDS AND NOTHING ELSE ABOUT EITHER PARTY** (ADR-040): the
 * selling organization, the store and the customer are all bare identifiers, and
 * copying their names onto the row is the denormalisation ADR-037 refuses — a
 * shop renamed tomorrow would disagree with every stale copy forever. So the
 * names are fetched, and this memoises them for the life of one request.
 *
 * **BOUND `scoped`, WHICH IS THE ENTIRE POINT.** Resolved with `app()` from a
 * table column, an unbound class would be constructed fresh for every row and
 * the memo below would never be read — one query per row per column, which is
 * exactly the N+1 it exists to prevent.
 *
 * **THE STORE'S NAME, NOT THE COMPANY'S.** A buyer chose a shop and a seller
 * trades as one; the legal entity behind it is an accounting fact that belongs
 * on an invoice, not in a list somebody scans.
 *
 * Presentation-only: nothing here is a business rule, and no other layer may
 * depend on it.
 */
final class OrderPartyLabels
{
    /** @var array<string, string> */
    private array $stores = [];

    /** @var array<string, string> */
    private array $customers = [];

    /** @var array<string, true> Uuids already asked about, hit or miss. */
    private array $askedStores = [];

    /** @var array<string, true> */
    private array $askedCustomers = [];

    public function __construct(
        private readonly StoreQueryContract $storeQuery,
    ) {}

    /**
     * The shop's name, or a truncated uuid when it can no longer be resolved —
     * never blank, because an empty cell in an oversight table reads as a bug
     * rather than as a missing shop.
     */
    public function storeName(?string $storeUuid): string
    {
        if (! is_string($storeUuid) || $storeUuid === '') {
            return '—';
        }

        if (! isset($this->askedStores[$storeUuid])) {
            $this->askedStores[$storeUuid] = true;

            foreach ($this->storeQuery->publicProfilesFor([$storeUuid]) as $uuid => $profile) {
                $this->stores[$uuid] = (string) $profile['name'];
            }
        }

        return $this->stores[$storeUuid] ?? $this->short($storeUuid);
    }

    /**
     * The buyer's name, from their account.
     *
     * NOT the recipient on the parcel: the two differ on a gift, and the person
     * a seller or an agent needs to identify is the one who placed the order and
     * whose account any dispute runs through. The recipient is already on the
     * address, one click away.
     */
    public function customerName(?string $customerUuid): string
    {
        if (! is_string($customerUuid) || $customerUuid === '') {
            return '—';
        }

        if (! isset($this->askedCustomers[$customerUuid])) {
            $this->askedCustomers[$customerUuid] = true;

            $customer = Customer::query()
                ->where('uuid', $customerUuid)
                ->first(['first_name', 'last_name']);

            if ($customer instanceof Customer) {
                $this->customers[$customerUuid] = (string) $customer->display_name;
            }
        }

        return $this->customers[$customerUuid] ?? $this->short($customerUuid);
    }

    private function short(string $uuid): string
    {
        return mb_substr($uuid, 0, 8).'…';
    }
}
