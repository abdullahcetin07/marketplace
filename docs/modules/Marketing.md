# Marketing — Outbound Ad-Platform Conversions

**Status: BUILT (2026-09-14); browser signals added 2026-09-15.** One integration: the **Meta Conversions API**, sending a
server-side `Purchase` for every paid checkout group. Live when
`META_CAPI_ENABLED=true` and a token is set; inert otherwise.

## Why it exists

The storefront's browser Meta Pixel loads **only after the shopper accepts marketing
cookies** (KVKK). Measured live over 14 days on two active campaigns: **~1,951 ad
clicks → 10 landing-page views → 0 purchases** seen by Meta. The campaigns were
optimising towards an event Meta almost never received.

The Conversions API sends the same `Purchase` from the server, on the PayTR callback —
the platform's source of truth for money — so it does not depend on the browser, the
redirect, an ad blocker or the consent banner.

## How it works

```
PayTR callback → SettlePaymentCallbackAction::after()
  → PaymentSucceeded (by class-string)
    → SendMetaPurchase           reads customer + lines via OrderQueryContract
      → SendMetaConversionJob    queued (default queue)
        → MetaConversionsApiClient  POST graph.facebook.com/{v}/{pixel}/events
```

| Decision | Why | Cost |
|---|---|---|
| **`event_id` = payment uuid** | The browser pixel sends the identical value as `eventID` on `/odeme/sonuc` (`PaymentResult.tsx` → `MetaPixel.tsx`). Meta merges the pair: a consented shopper counts once, an un-consented one still counts. | Dedup silently breaks if either side changes its id. Both are documented at the source. |
| **`content_ids` = product uuid** | Matches the catalogue feed's `g:id`, so dynamic ads resolve the item. | Variant-level attribution is not possible. |
| **E-mail (SHA-256 of the trimmed, lower-cased address) + the browser signals below** | The callback has no browser, so the signals are taken on the pay request and joined back by checkout group. | A payment whose pay request was not captured (disabled at the time, capture failed) goes out on the e-mail alone. |
| **Queued job, not an inline HTTP call** | PayTR retries until it hears `OK`; a slow Graph must not turn into failed payments. | Conversions stop while no worker runs (Horizon). |
| **The listener swallows and reports every failure** | It runs synchronously inside the callback's `after()`, next to the listeners that confirm orders and open shipments. An escaping exception would 500 the callback and skip every later listener. | A broken integration is visible only in the error log / Sentry, never as a failed payment. |
| **Registered last** in `bootstrap/providers.php` | Listeners run in registration order; an observer of money goes after everything that acts on it. | — |
| **The token travels in the request BODY** | A connection failure's exception message quotes the URL, and that message lands in `failed_jobs` and the log. A rejected response logs the HTTP status only, since Graph errors can echo input. | — |
| **Money stays in minor units** until the client builds decimal strings (ADR-005) | Platform rule. | — |

`value` is the payment's `amount_minor` — **what the card was charged**. A basket paid
partly with loyalty points reports the card amount; one paid entirely with points
reports `0.00`.

## Browser signals (2026-09-15)

```
POST /api/v1/checkout/{group}/pay
  → CaptureCheckoutSignals (Marketing middleware, routes/api.php)
      owner check via OrderQueryContract → upsert marketing_checkout_signals
  → PaymentController (unaware)
…
PaymentSucceeded → SendMetaPurchase → joins the row by checkout_group_uuid
```

| Signal | Source | Sent for |
|---|---|---|
| `client_ip_address` | `$request->ip()` — the same value PayTR receives as `user_ip` | every payer |
| `client_user_agent` | request header, cut to 512 chars | every payer |
| `fbp` | `_fbp` cookie, sent in the pay body by the storefront | **only with marketing consent** |
| `fbc` | `_fbc` cookie, same | **only with marketing consent** |

**The consent rule is the owner's decision (2026-09-15):** IP and user agent are on
the payment request itself and describe the transaction, so they go for everyone;
the Meta cookies go only when `raftabul.consent === 'granted'`, which the storefront
re-checks at pay time so leftover cookies of a shopper who later refused stay home.
**No `fbclid` is captured for an un-consented visitor** — that would be click tracking
without consent, and it was explicitly declined.

| Decision | Why | Cost |
|---|---|---|
| **Middleware BEFORE the controller** | A points-only basket settles inside the pay request and fires `PaymentSucceeded` synchronously; the row must already exist. | It runs before Payment's ownership guard, so it repeats that check itself (one extra indexed read per pay). |
| **A Marketing table, not columns on `payments`** | Tracking data does not belong on the money table, and Payment stays unaware. | A second write per pay request. |
| **Shape-validated cookies** (`fb.N.<ts>.<id>`) | A client must not be able to park arbitrary text. | A future Meta format change silently drops the value until the regex follows. |
| **Upsert per checkout group** | A retried pay reflects the latest browser state. | — |
| **Pruned after 7 days** (`META_CAPI_SIGNAL_RETENTION_DAYS`, scheduled 04:00) | The purpose ends when the payment settles. | Needs the scheduler, like everything else here. |
| **Inert while disabled** | No capture without a consumer (data minimisation). | Enabling CAPI does not enrich payments whose pay request came earlier. |

## Boundaries

**Marketing imports no module** (`LayeringTest`: "Marketing imports no module at all",
"no module depends on Marketing"). `PaymentSucceeded` arrives by class-string; the
customer and lines come through `OrderQueryContract`. Removing the provider leaves
payments exactly as they were.

## Configuration (`config/marketing.php`)

```
META_CAPI_ENABLED=false            # the switch
META_PIXEL_ID=2082722212251736     # same dataset as NEXT_PUBLIC_META_PIXEL_ID
META_CAPI_TOKEN=                   # Events Manager → dataset → Settings → Conversions API → Generate access token
META_API_VERSION=v21.0
META_CAPI_TEST_EVENT_CODE=         # only while verifying in Events Manager → Test events
```

Both the listener and the client gate on `enabled`; the client also refuses to send
without a pixel id and token. After editing `.env`: `config:cache`, then restart
Horizon so the workers read it.

## Verifying

1. Set `META_CAPI_TEST_EVENT_CODE`, enable, `config:cache`, restart Horizon.
2. Complete a real payment.
3. Events Manager → **Test events** shows a server `Purchase` whose `event_id` is the
   payment uuid; with cookies accepted the browser event appears beside it, marked
   **Deduplicated**.
4. Clear the test code, `config:cache`, restart Horizon.

## Not built

- **fbclid capture for un-consented visitors.** Declined (see Browser signals).
- **Other events** (`InitiateCheckout`, `AddToCart`) server-side, and other platforms
  (TikTok Events API, Google Enhanced Conversions) — each another
  `ConversionsApiContract` implementation.
- **Refund reporting.** A refunded purchase stays a conversion at Meta.

## Open question for the owner

The KVKK position of sending a hashed e-mail, IP address and user agent for a completed
purchase without cookie consent. It is a server-side record of a transaction the
customer made, not tracking — but it should be reflected in the privacy notice.
