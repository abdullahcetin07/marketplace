# BUILD — Loyalty: public earn-preview endpoint (small)

**Status:** Ready. Small addition to the **Loyalty** module (Phase 1 is live). Independent
of P2 — can land any time. Spec context: `docs/modules/Loyalty.md` §3.3/§4, ADR-082/083.

The storefront product page has a "Kampanyalar" card that shows **how many points buying
this product earns**. It needs one public read that turns a TL amount into an integer
point count — because the frontend must never multiply a price string itself (ADR-005),
the backend does the arithmetic.

## The endpoint

`GET /api/v1/loyalty/earn-preview?amount=<decimal>` — **public** (no auth; the product
page is public and signed-out shoppers see it too).

- `amount` — a decimal string in TL (e.g. `129.90`; accept `129,90` too, like the feed).
  Convert to minor units at the boundary; reject a non-numeric / negative amount with 422.
- Response:
  ```json
  { "enabled": true, "points": 6, "currency": "TRY" }
  ```
  `points = floor(amount_TL × settings('loyalty.earn.purchase_rate'))` — the SAME
  computation the purchase sweep uses (ADR-082), so the preview never disagrees with what
  actually gets credited. When `settings('loyalty.enabled')` is false, return
  `{ "enabled": false, "points": 0 }` (the storefront then renders nothing).

Read-only, no state, cacheable like the other public reads. It reads only `settings()` —
no ledger, no customer, no cart.

## Boundary

Lives in the Loyalty module's public read surface. It imports no other module and needs
no Core contract — it's Loyalty answering a question about its own rate. Catalog/Offer are
untouched: the storefront already has the featured price and passes it in as `amount`, so
there is no Catalog→Loyalty or Offer→Loyalty coupling.

## Tests (Feature)

1. `points == floor(amount × rate)` for a few amounts; the floor matches the sweep's.
2. `loyalty.enabled=false` → `{ enabled:false, points:0 }`.
3. `129,90` (comma) is accepted; a non-numeric / negative amount → 422.
4. No auth required (a signed-out request succeeds).

## After it lands

`make check` green; no migration. The storefront `ProductCampaigns` card is already built
against this response and stays hidden until the endpoint answers — so once it ships, the
"Bu ürünü alınca X puan kazan" card lights up on product pages on its own.
