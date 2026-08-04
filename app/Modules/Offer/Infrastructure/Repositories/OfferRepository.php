<?php

declare(strict_types=1);

namespace App\Modules\Offer\Infrastructure\Repositories;

use App\Modules\Offer\Domain\Contracts\OfferRepositoryContract;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Models\Offer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Offer's read vocabulary.
 *
 * EAGER LOADS ARE DECLARED HERE, not at the call site (CLAUDE.md — strict mode
 * makes a lazy load throw). The list is short and always will be: an offer has
 * exactly one relation, its currency, because every other thing it references
 * lives in another bounded context and is held as a uuid (ADR-040). That is the
 * shape of a module that imports nothing — there is no `variants.attributeValues`
 * chain to forget here, because there is no relation to forget.
 *
 * @see App\Modules\Offer\Domain\Contracts\OfferRepositoryContract
 */
final class OfferRepository implements OfferRepositoryContract
{
    /**
     * Money is never rendered without a currency, so it is never left to a
     * lazy load.
     *
     * @var list<string>
     */
    private array $with = ['currency'];

    public function findByUuid(string $uuid): ?Offer
    {
        return Offer::query()->with($this->with)->where('uuid', $uuid)->first();
    }

    public function findOrFailByUuid(string $uuid): Offer
    {
        $offer = $this->findByUuid($uuid);

        if ($offer === null) {
            throw (new ModelNotFoundException)->setModel(Offer::class, [$uuid]);
        }

        return $offer;
    }

    /**
     * The application-layer half of §3.2. The database's partial unique index
     * is the real guarantee — it holds under a race this check cannot see — but
     * a seller deserves "you already sell this" rather than a constraint
     * violation.
     */
    public function duplicateFor(int $sellingOrgId, string $variantUuid): ?Offer
    {
        return Offer::query()
            ->with($this->with)
            ->where('selling_org_id', $sellingOrgId)
            ->where('variant_uuid', $variantUuid)
            ->blockingDuplicate()
            ->first();
    }

    /**
     * @param array<int, int> $organizationIds
     *
     * @return Collection<int, Offer>
     */
    public function forOrganizations(array $organizationIds): Collection
    {
        // A member of nothing gets nothing. `whereIn` on an empty array already
        // yields no rows, but stating it means the tenancy guarantee does not
        // rest on remembering that.
        if ($organizationIds === []) {
            return new Collection;
        }

        return Offer::query()
            ->with($this->with)
            ->whereIn('selling_org_id', $organizationIds)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Collection<int, Offer>
     */
    public function forProduct(string $productUuid): Collection
    {
        return Offer::query()
            ->with($this->with)
            ->forProduct($productUuid)
            ->orderBy('price_minor')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return Collection<int, Offer>
     */
    public function activeForProduct(string $productUuid): Collection
    {
        return Offer::query()
            ->with($this->with)
            ->forProduct($productUuid)
            ->where('status', OfferStatus::Active->value)
            ->get();
    }

    /**
     * @return Collection<int, Offer>
     */
    public function forStore(string $storeUuid): Collection
    {
        return Offer::query()
            ->with($this->with)
            ->where('store_uuid', $storeUuid)
            ->get();
    }

    /**
     * @return Collection<int, Offer>
     */
    public function cascadePausedForProduct(string $productUuid): Collection
    {
        return Offer::query()
            ->with($this->with)
            ->forProduct($productUuid)
            ->where('status', OfferStatus::Paused->value)
            ->where('paused_by_cascade', true)
            ->get();
    }
}
