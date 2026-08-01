<?php

declare(strict_types=1);

namespace App\Modules\Store\Infrastructure\Queries;

use App\Core\Domain\Contracts\StoreQueryContract;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/**
 * Store's implementation of the downstream read port (ADR-033).
 *
 * Returns only the minimum a foreign module needs — existence, liveness, owning
 * org — never a model, so callers cannot reach into Store's internals through
 * it. `isLive` mirrors the public-visibility rule so a downstream module and the
 * public surface agree on what "live" means.
 *
 * @see App\Core\Domain\Contracts\StoreQueryContract
 */
final class StoreQuery implements StoreQueryContract
{
    public function exists(string $storeUuid): bool
    {
        return Store::query()->where('uuid', $storeUuid)->exists();
    }

    public function isLive(string $storeUuid): bool
    {
        return Store::query()
            ->where('uuid', $storeUuid)
            ->where('status', StoreStatus::Active->value)
            ->exists();
    }

    public function organizationIdFor(string $storeUuid): ?int
    {
        $id = Store::query()->where('uuid', $storeUuid)->value('organization_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Added for Offer (§3.4) — see the contract for why the store → org
     * direction above could not answer it.
     *
     * `Active` rather than any other liveness notion, deliberately reusing
     * `isLive()`'s definition: a downstream module and the public storefront
     * must agree on what "live" means, or a seller could list under a store no
     * buyer can reach.
     *
     * @return array<string, string>
     */
    public function liveStoresForOrganization(int $organizationId): array
    {
        /** @var array<string, string> $stores */
        $stores = Store::query()
            ->where('organization_id', $organizationId)
            ->where('status', StoreStatus::Active->value)
            ->orderBy('id')
            ->pluck('name', 'uuid')
            ->all();

        return $stores;
    }

    /**
     * Added for Offer's buy box — see the contract for why a name was the one
     * public fact a downstream module could not get.
     *
     * ONE QUERY WITH THE CONTACT EAGER-LOADED. Strict mode makes lazy loading
     * throw, and this runs once per product page with every seller on it, so the
     * N+1 this avoids is the whole reason the method is batched.
     *
     * THE CITY COMES OUT OF A JSON BLOB and is defensive about it on purpose:
     * `store_contacts.address` is a free-form `jsonb` column with no enforced
     * shape (§2.6), so anything that is not a non-empty string reads as "not
     * held". A cast or an undefined-index notice reaching a public product page
     * would be a 500 caused by one seller's malformed profile.
     *
     * @param  array<int, string>  $storeUuids
     * @return array<string, array{name: string, city: string|null}>
     */
    public function publicProfilesFor(array $storeUuids): array
    {
        if ($storeUuids === []) {
            return [];
        }

        $profiles = [];

        $stores = Store::query()
            ->whereIn('uuid', $storeUuids)
            // Live only — a suspended shop's name must not reach a public payload
            // through a caller that forgot to check (see the contract).
            ->where('status', StoreStatus::Active->value)
            ->with('contact')
            ->get();

        foreach ($stores as $store) {
            // A store may have no contact row at all, and the row's `address` is
            // free-form: neither the array nor the key is guaranteed.
            $address = $store->contact?->address;
            $city = is_array($address) ? ($address['city'] ?? null) : null;

            $profiles[$store->uuid] = [
                'name' => $store->name,
                'city' => is_string($city) && trim($city) !== '' ? trim($city) : null,
            ];
        }

        return $profiles;
    }

    /**
     * Added for the onboarding reflow — see the contract for why the question
     * has to be answerable before a store exists.
     *
     * `LOWER` on BOTH sides rather than lowering in PHP, so the comparison uses
     * exactly the expression the unique index is built on and the two can never
     * disagree about what counts as the same name.
     *
     * Soft-deleted stores are excluded by the default scope, deliberately: a
     * closed shop should not reserve its name forever.
     */
    public function storeNameExists(string $name): bool
    {
        return Store::query()
            ->whereRaw('LOWER(name) = LOWER(?)', [trim($name)])
            ->exists();
    }
}
