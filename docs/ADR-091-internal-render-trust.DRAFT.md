# ADR-091 A Server-Side Render Is First-Party Traffic, and the Rate Limiter Must Not Count It as a Visitor

> **STATUS: PROPOSED — DRAFT, awaiting owner ratification (ADR-018).**
> **Already implemented** (commit `128d327`, 2026-09-03) as an outage fix — which is
> why it is written down now rather than before. It changes the trust boundary of a
> SECURITY control, so it is a decision and not a sprint detail: on approval this
> becomes ADR-091 in `Architecture_Decision_Record.md` and gets a row in the
> `001_Architecture.md` amendment log in the same change. If the owner rejects it, the
> exemption comes out and the limit is raised instead — see "Open questions".

## Context

`/urunler` answered **"Application error: a server-side exception has occurred"** on
the live site, fast (~0.4s) and only under load, while every external measurement said
the platform was healthy: thirty concurrent calls to `GET /api/v1/products` all
returned 200, the home page and product pages were fine, the feeds were fine. The
report that reached this session proposed PHP-FPM worker saturation, a dropped loopback
socket, or a wrong `INTERNAL_API_URL`.

**It was none of those. It was our own rate limiter.** The error digest resolved, in the
Next.js log, to:

```
⨯ Error [ApiError]: POST /api/v1/offers/prices failed   status: 429
```

There were **11,094** of them.

The mechanism is a topology mistake, not a capacity one. The storefront is a Next.js
application **on the same box**; its server-side renders fetch the API through a
loopback-only nginx vhost (`127.0.0.1:8081`). The `storefront` limiter is keyed
`'storefront:'.$request->ip()` at 300/minute — so **every shopper's render shared one
bucket**, because to the API they are all 127.0.0.1. A listing render spends two of
those calls (`/products`, then the bulk `POST /offers/prices` that prices the whole
page), so a few dozen shoppers a minute exhausted the minute's budget for everyone.

Two details explain why this hid for so long. Requests from **outside** were never
throttled in the same way — each browser has its own IP — so curl always looked
healthy. And the home page **swallows** its fetch errors and renders an empty strip,
while the listing threw; only one page turned a 429 into a 500.

## Decision

**A request that originates from this platform's own server-side render is first-party
traffic. The public rate limiter does not count it.**

The exemption is keyed on a **CGI parameter set by the loopback vhost**
(`fastcgi_param INTERNAL_RENDER 1`), and both halves of that sentence are the decision:

- **Not the socket address.** Every request reaches PHP-FPM from nginx over loopback —
  a shopper's included — so `REMOTE_ADDR` cannot tell a render from a visitor. On this
  topology an IP-based exemption is either useless or a hole.
- **Not a request header.** A client can send any header it likes. nginx exposes
  request headers to PHP as `HTTP_*`, so a client-supplied `Internal-Render: 1` arrives
  as `HTTP_INTERNAL_RENDER` and can never collide with the bare `INTERNAL_RENDER`
  variable the vhost sets. A test asserts exactly that, and it is the assertion that
  keeps this safe rather than merely convenient.
- **Not a shared secret**, which would be a credential to rotate, leak and forget. The
  listener is bound to 127.0.0.1: nothing outside the machine can reach it at all, so
  the trust boundary is enforced by the network, and the CGI variable only *reports*
  which side of it the request came from.

Concretely: `RateLimiter::for('storefront', …)` returns `Limit::none()` when
`INTERNAL_RENDER` is present, and the ordinary per-IP limit otherwise. The limiter
stays installed on every route — no route becomes unthrottled (the rule in
`routes/api.php` holds); one *class of client* stops being counted.

## Cost, stated

**A runaway server-side render is no longer bounded here.** If a future page loops over
the price endpoint, nothing in the limiter stops it; it will show up as load on our own
box. That is the right way round — it would be our bug, on our hardware, visible in
metrics — whereas the limiter exists to bound scraping *from outside*, which still
counts every browser-direct call by its real IP.

**The protection now depends on nginx configuration that lives outside this
repository.** If the loopback vhost is ever rebuilt without the `fastcgi_param` line,
the symptom returns as a mysterious 429 storm; if some future vhost sets the same
parameter on a **public** listener, the storefront limiter silently stops protecting
anything. Both are one grep away, and both are why the parameter is named after what it
means rather than something generic like `TRUSTED`.

**The real capacity question is deferred, not answered.** A listing page still costs two
API round trips per render and the price call is uncached; this change removed an
artificial ceiling, not a real one. When traffic grows enough to matter, the answer is
caching the buy-box prices per page (ADR-079's shape) — not a bigger number here.

**One number in the codebase is now load-bearing in a way it was not**: 300/minute
per IP was tuned when it was accidentally also throttling every render. It has never
been measured against real browser traffic alone, and it may now be too generous for
scraping. Unknown, and worth measuring rather than guessing.

## What stays out of scope

- **The other limiters** (`api`, `panel`, `search`, `auth`) are unchanged. They are not
  reached by server-side renders today; when one is, it gets the same treatment
  deliberately rather than by a shared helper nobody reads.
- **Caching the price call** — the right long-term fix for the same page's cost, and a
  separate decision with its own staleness trade-off.
- **Rate limiting at the edge.** Cloudflare already sits in front and is where a real
  abuse response belongs; this ADR does not move that.

## Open questions for ratification

1. **Exemption vs. a much larger limit.** `Limit::none()` for renders is the honest
   statement of "this is us". The alternative — a separate, high limit for internal
   renders — would keep a ceiling under a runaway loop at the cost of pretending we are
   policing ourselves. (Recommendation: keep the exemption; catch loops in review and
   in load, not in a limiter.)
2. **Should the marker be asserted at boot?** A tiny health check could fail loudly if
   `INTERNAL_RENDER` is missing from the loopback path, so a rebuilt vhost is caught in
   minutes rather than in an outage. (Recommendation: yes, as a follow-up.)
3. **Is 300/minute still the right public number** now that it faces only real browsers?
