<?php

declare(strict_types=1);

namespace App\Core\Domain\Contracts;

/**
 * The cross-context authorization port: business questions about a user's
 * standing in an organization, answered WITHOUT the caller importing the
 * Organization module (ADR-033, Store.md §9.1/§20.1).
 *
 * Organization provides the implementation and remains the single source of
 * truth for memberships and capabilities. Store — and every future seller-owned
 * module (Product, Catalog, Offer, Order, Payment) — depends only on this
 * contract. No replicated read model, no event-sync.
 *
 * Intentionally small and business-oriented. It asks questions ("can this user
 * manage this organization?"), never exposes roles, capabilities, or membership
 * records. How Organization answers them — its role→capability matrix — stays
 * entirely private to Organization.
 *
 * @see docs/modules/Store.md §20.1
 */
interface OrganizationAuthorizationContract
{
    /**
     * Whether the user may manage this organization's operational surfaces
     * (its stores and their settings). The gate for seller store management.
     *
     * (A higher-bar `canManageOrganizationDomains` question is intentionally
     * absent: v1 stores are path-addressed with no domains, ADR-035. It returns
     * with custom domains under a future ADR.)
     */
    public function canManageOrganization(int $userId, int $organizationId): bool;

    /**
     * Whether the user is an active member of the organization at all — the
     * baseline for any read access to its resources (ADR-030 isolation).
     */
    public function isActiveMember(int $userId, int $organizationId): bool;

    /**
     * The ids of every organization the user actively belongs to — the scope a
     * seller's cross-org listing is confined to (ADR-030). A member of nothing
     * gets an empty list, never everyone's data.
     *
     * @return array<int, int>
     */
    public function organizationIdsForUser(int $userId): array;
}
