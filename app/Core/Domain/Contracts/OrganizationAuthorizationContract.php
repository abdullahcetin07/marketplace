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

    /**
     * The organization's PUBLIC identifier for an internal id; null when no
     * such organization exists.
     *
     * ADDED FOR OFFER — a change frozen Organization explicitly permits, being
     * one "a later module explicitly requires", alongside the `StoreManage`
     * capability Store required. Recorded in the `001_Architecture.md`
     * amendment log.
     *
     * WHY A DOWNSTREAM MODULE NEEDS IT. ADR-040 says a cross-context reference
     * is an id/uuid PAIR: the internal id for tenancy filters, the uuid for
     * every payload and event. Every other method here speaks internal ids —
     * which is right, they answer isolation questions — so a module that
     * resolves a company through them has the filtering half and no way to get
     * the public half. Offer persists `selling_org_uuid` and puts it on every
     * event; without this it would have to accept that uuid from a form, where
     * a forged value would attribute a listing to the wrong company.
     *
     * Deliberately one-way: id → uuid. The reverse would let a caller turn a
     * public identifier into an internal one, which is the direction ADR-040
     * exists to prevent.
     */
    public function organizationUuidFor(int $organizationId): ?string;
}
