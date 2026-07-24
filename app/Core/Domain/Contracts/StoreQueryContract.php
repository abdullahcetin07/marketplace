<?php

declare(strict_types=1);

namespace App\Core\Domain\Contracts;

/**
 * The read port other modules use to ask about a store — WITHOUT importing the
 * Store module (ADR-033).
 *
 * The mirror of OrganizationAuthorizationContract pointing downstream. Future
 * selling contexts (Product, Catalog, Offer, Order, Payment) reference a store
 * by UUID and ask their questions here; Store provides the implementation and
 * stays the single source of truth for storefront state. They never
 * `use App\Modules\Store\...`.
 *
 * Deliberately minimal — exists / is-live / which-org — the least a downstream
 * module needs to validate a reference and enforce isolation. Anything richer is
 * a public read surface (ADR-034), not this contract.
 *
 * @see App\Modules\Store\Infrastructure\Queries\StoreQuery
 */
interface StoreQueryContract
{
    /**
     * Whether a store with this UUID exists (and is not soft-deleted).
     */
    public function exists(string $storeUuid): bool;

    /**
     * Whether the store is live and serving the public (ADR-034) — Active with a
     * verified serving domain once domains exist (Phase 3).
     */
    public function isLive(string $storeUuid): bool;

    /**
     * The internal id of the organization that owns the store, for isolation
     * checks (ADR-030); null when no such store exists.
     */
    public function organizationIdFor(string $storeUuid): ?int;
}
