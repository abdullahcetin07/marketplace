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

    /**
     * The PUBLIC display facts for these stores, as
     * `uuid => ['name' => string, 'city' => string|null]`. Stores that are not
     * live are simply absent.
     *
     * ADDED FOR OFFER'S BUY BOX (2026-08-01) — the second change frozen Store has
     * granted a later module, on the same footing as
     * `liveStoresForOrganization()` above and recorded in the same amendment log.
     *
     * WHY IT WAS MISSING. Every method here answered a question about a store's
     * STATE — does it exist, is it live, who owns it — because that is all an
     * isolation check needs. Offer arrived with a different question: it holds
     * store uuids and has to put a merchant's NAME in front of a shopper. "Satıcı:
     * a1086566-10aa-…" is what the buy box renders without this.
     *
     * BATCHED, because a product page asks about every seller of one product and
     * asking one at a time is a query per row on the platform's busiest page.
     *
     * LIVE ONLY, and that is a safety property rather than a convenience: this is
     * the one method on this contract whose output reaches a public payload
     * verbatim, so a suspended shop cannot have its name rendered by a caller that
     * forgot to filter. It reuses `isLive()`'s definition so the storefront and
     * every downstream module keep agreeing on what live means.
     *
     * `city` IS NULL FOR NOW ON EVERY STORE, and the honesty matters more than the
     * field: it is read from the store's contact address, which no seller-facing
     * form yet writes (Store.md §2.6 — the contact block exists, the UI does not).
     * The read is here so the surface is complete the day that form ships, rather
     * than the API changing shape again then.
     *
     * `slug` JOINED IT (2026-08-06), and the difference from `name` is the whole
     * reason: a caller with a name can only SAY where something was bought, while
     * a caller with a slug can LINK to it. The storefront's store page is
     * path-addressed at `/magaza/{slug}` (ADR-035 — custom domains are cut from
     * v1), and the two surfaces that hold a store uuid and render its name — the
     * buy box and the customer's own order list — both wanted to be links.
     *
     * IT IS LIVE-ONLY FOR THE SAME SAFETY REASON `name` IS, and here it matters
     * more: a suspended shop that must not be NAMED in a public payload certainly
     * must not be LINKED from one. The filter is the query's, not the caller's,
     * so a surface that forgets cannot leak either field.
     *
     * NO OTHER PROFILE FIELDS. Logo, banner, announcement and support hours are a
     * public READ SURFACE (ADR-034), not a downstream contract — a module that
     * needs them is asking for the store page, which already exists.
     *
     * @param array<int, string> $storeUuids
     *
     * @return array<string, array{name: string, city: string|null, slug: string}>
     */
    public function publicProfilesFor(array $storeUuids): array;

    /**
     * Whether any store already trades under this name, case-insensitively.
     *
     * ADDED FOR THE ONBOARDING REFLOW (owner-approved, see the Store freeze
     * notice): store names are unique platform-wide. A buyer who is told to
     * "shop at Beko" must land at one shop, and two storefronts sharing a name
     * makes every support ticket, every invoice and every complaint ambiguous.
     *
     * ASKED AT REQUEST TIME, which is the whole reason it is on this contract.
     * A store is created only when an admin approves a request (ADR-028), so
     * without this the seller would fill in a name, wait for review, and be told
     * days later that it was taken. Organization asks Store here, and never
     * imports it.
     *
     * The database's unique index is the real guarantee — this is what turns a
     * constraint violation into a sentence a seller can act on.
     *
     * CASE-INSENSITIVE, matching that index. Postgres folds Turkish correctly;
     * SQLite's `LOWER` is ASCII-only, so under the test suite "İSTANBUL" and
     * "istanbul" are two names. Stated rather than hidden: it is the same
     * driver difference the catalog browse port documents.
     */
    public function storeNameExists(string $name): bool;
}
