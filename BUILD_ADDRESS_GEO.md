# Work order — Address geo: Mahalle select (backend)

**Status:** approved by owner 2026-08-03. Disposable — `git rm` this file when the
work lands.

**Session split:** this is BACKEND work → the **server session**. The frontend
(desktop session) already shipped the İl → İlçe cascade for TR (bundled dataset in
`storefront/src/lib/tr-geo.ts`, still sent as the existing `city` / `district`
strings — no API change). This order adds the **Mahalle** level, which the frontend
cannot do alone: it needs a stored field and a data source too large to bundle
(~50k neighborhoods). Do **not** touch `storefront/`.

---

## Why (and the ADR-056 tension — read first)

ADR-056 made the customer address **country-agnostic**: `city` and `district` are
loose free text because "validating world addresses structurally is a project of its
own." That decision stands for storage. This change does **not** structure the world
— it adds:

1. one **optional** `neighborhood` column (nullable; free for any country), and
2. a **TR-only reference dataset** that powers client dropdowns.

An address from any other country is unaffected: no neighborhood, free-text city/
district, exactly as today. So this is an **enrichment for TR**, not a schema
philosophy change — but because it edits a documented decision, it needs the
amendment below applied **in the same change** (CLAUDE.md: amend the ADR *and* the
`001_Architecture.md` amendment log together).

### ADR-056 amendment text (append to the ADR entry; log it too)

> **Amendment (2026-08-03):** The customer address gains an **optional
> `neighborhood`** (mahalle) field — nullable, free text in storage, like `city`
> and `district`. Addresses remain country-agnostic: `neighborhood` is null for
> non-TR addresses and never required by the API. A separate, operator-manageable
> **geo reference dataset** (provinces → districts → neighborhoods) is added as
> Localization lookup tables to let TR clients offer a cascade; it is reference
> data, not part of the address aggregate, and imposes no validation on stored
> addresses (a client MAY send any string). Rationale: usability for the TR market
> (the platform's launch market) without re-opening structured world-address
> validation. Cost: a TR-specific dataset to seed and keep current, and a read
> surface to serve it.

---

## Task 1 — `neighborhood` on the customer address

- Add nullable `neighborhood` (string) to the customer addresses table + model +
  `AddressDTO` + API resource + create/update request validation (`nullable|string|
  max:*`). It is **snapshotted onto the order** alongside the rest of the address at
  placement (ADR-053) — add it to the shipping/billing address snapshot too so a
  placed order keeps the mahalle it was shipped to.
- No `required`. Non-TR and legacy addresses keep working with it null.

## Task 2 — Geo reference dataset (Localization lookup tables)

Per the enum-or-table rule (operator can add a row without a release → **table**):
three lookup tables in **Localization** — `geo_provinces`, `geo_districts`
(FK province), `geo_neighborhoods` (FK district). `is_active` on each (lookup-table
convention, ADR-015). Seed TR from a public il/ilçe/mahalle dataset.

**Make il/ilçe the source of truth** and match the names the frontend already ships
(`storefront/src/lib/tr-geo.ts`) so the two never disagree; once this endpoint is
live the frontend will migrate its İl/İlçe off the bundle onto the API too (single
source). Flag any il/ilçe name mismatch in your report rather than silently
diverging.

## Task 3 — Read API (public or customer-auth; match the storefront's other geo reads)

The frontend needs exactly these. Names in, names out (the frontend stores the NAME
string, to match how `city`/`district` are stored):

```
GET /api/v1/geo/provinces
    → [{ "id": "...", "name": "İstanbul" }, ...]   (active, tr-sorted)

GET /api/v1/geo/districts?province=İstanbul
    → [{ "id": "...", "name": "Kadıköy" }, ...]

GET /api/v1/geo/neighborhoods?district=Kadıköy&province=İstanbul
    → [{ "id": "...", "name": "Caferağa" }, ...]
```

- `province`/`district` may be passed by name (what the client has) — resolve to the
  row and return its children. Accepting `?district_id=` too is fine; the frontend
  will use whatever you document back.
- Cache-friendly (this is static-ish reference data). ADR-009 envelope like every
  other endpoint.

## Boundaries / non-negotiables (unchanged)

- Money rule N/A here. Strict types, UUID public ids, append-only where it applies.
- `LayeringTest` / `CatalogBoundaryTest` must stay green. Geo lives in Localization
  (platform-wide reference data — the one module everything may read), so no new
  cross-module import is introduced.
- `make check` green before you call it done. Report: migration, table row counts
  seeded, any il/ilçe name deltas vs the frontend list, and the final endpoint
  signatures.

## Frontend follow-up (desktop session — NOT you)

Once Task 3 is live, the desktop session will: add the Mahalle `<select>` to
`AddressForm` (fed by `/geo/neighborhoods`), send `neighborhood`, and optionally move
İl/İlçe onto the API to retire the bundled `tr-geo.ts`. Nothing for you in
`storefront/`.
