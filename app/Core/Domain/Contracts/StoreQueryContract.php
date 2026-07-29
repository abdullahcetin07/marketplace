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

    /**
     * An organization's LIVE stores as `uuid => display name`, empty when it
     * has none.
     *
     * ADDED FOR OFFER (Offer.md §3.4) — a change frozen Store explicitly
     * permits, being one "a later module explicitly requires", and the same
     * shape as the `StoreManage` capability frozen Organization gained for
     * Store. Recorded in the `001_Architecture.md` amendment log.
     *
     * WHY IT WAS MISSING. Every method above walks store → org, which is all an
     * isolation check needs: you hold a store uuid and ask who owns it. Offer
     * asks from the other end — "may this company sell at all, and under which
     * storefront?" — with no store uuid yet to ask about. Without it the
     * precondition ("the selling org must have an Active store") is
     * unanswerable, and the seller's offer form has nothing to attribute a
     * listing to.
     *
     * WHY IT CARRIES THE NAME. A downstream module holds uuids and nothing
     * else, so a store picker built from uuids alone would ask a seller to
     * choose between two identifiers. One method answering both the
     * precondition (`=== []`) and the picker is better than two that overlap.
     *
     * Still no models and no internal store ids, so the boundary is unchanged.
     * Plural because an organization may hold several stores (ADR-028's store
     * limit is a number, not a flag) — the caller picks.
     *
     * @return array<string, string>
     */
    public function liveStoresForOrganization(int $organizationId): array;
}
