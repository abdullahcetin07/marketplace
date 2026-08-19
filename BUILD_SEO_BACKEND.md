# BUILD — SEO backend items (post-launch priority, not launch-blocking)

**Status:** Ready. From a pre-launch SEO audit (2026-08-18). The storefront-side SEO
fixes are done separately (product/store/category/brand JSON-LD + metadata, shipping
schema, canonical, sitemap lastmod). These are the items that need the **backend** or a
**content decision**. None blocks launch (the launch-blockers are owner/infra: staging
noindex + DNS cutover + Cloudflare AI-bot policy — handled outside this file). Do these
when convenient; #1 is small, #2 is the highest SEO impact but a data decision.

## 1. Store-slugs list endpoint (small — unblocks store pages in the sitemap)

The storefront sitemap (`storefront/src/app/sitemap.ts`) currently omits every
`/magaza/{slug}` because there is no way to enumerate live stores. Add a **public** read
that lists the slugs (and ideally `updated_at`) of all **live** stores:

`GET /api/v1/stores` (public) → `{ data: [ { slug, updated_at }, … ] }` — live stores
only, same visibility rule the store page uses (a suspended/not-live store is a 404, so
it must not appear here either). Cacheable. The storefront will map these into the
sitemap. If a slug list already exists internally, exposing it read-only is enough.

## 2. Product descriptions are EMPTY across the catalog — the #1 SEO/citability gap

The audit confirmed `Product.description` is `""` on the sampled SKU, and given the bulk
importer (ADR-074) builds products from Excel/CSV and the offer feed (ADR-076) never
writes product data, **this is almost certainly systemic across the whole catalogue**.
Consequences: thin product pages (nothing to rank on beyond title/brand), no `<meta
description>` substance, and nothing for AI answer engines (AI Overviews / ChatGPT /
Perplexity) to cite. This is the single biggest organic-traffic lever on the platform.

**This is a data-sourcing decision, not just a code change** — pick one (or combine):
- Populate `description` at import time from the manufacturer/brand/GTIN feed where the
  source data has it.
- Require a description on seller authoring ("ürün aç") going forward.
- A semi-automated pass that drafts a description per GTIN from category + brand +
  attributes, reviewed before publish.
Whatever the source, a real 100–170 word Turkish description per product (what it is,
skin type/use, how to use, key ingredient) is the goal. Also surface the moderated
per-category **attributes** (ADR-038) as a visible "Ürün Özellikleri" table on the page.

## 3. (Optional) Expose `updated_at` on the slug/list reads for real sitemap `lastmod`

The sitemap has no `<lastmod>` on catalogue URLs because the storefront's slug lists
return slugs only. If the browse/slug/category/brand list endpoints return `updated_at`
per entity, the storefront can stamp accurate `lastmod` (freshness signal for a catalogue
whose price/stock change daily). Small addition; skip if costly.

## 4. (Optional) Seller-level `aggregateRating` for the store page

Reviews are product-level (ADR-069) but carry the seller they were bought from, so a
seller-level rating rollup is computable. If exposed on the `store/{slug}` payload
(`aggregateRating: { ratingValue, reviewCount }`, only when `reviewCount > 0`), the
storefront can render seller stars + add it to the store's `Organization` JSON-LD — a
real trust/rich-result signal. Only emit when there are reviews (never fabricate).

## 5. (Optional) Category / brand / store short description fields

Category, brand and store hub pages are pure product grids with no unique intro copy —
the "repetitive template, no unique value" pattern Google flags at scale. A short
editable `description` field per category/brand/store (rendered as an intro paragraph +
into the meta description) breaks the boilerplate. Editorial work; the field + render is
the code part.

---

## Not in this file (owner/infra — tell the owner)

- **Staging (`test.raftabul.com`) is fully indexable** and competes with production —
  add a sitewide `noindex` / `Disallow: /` on the staging host, or complete the DNS
  cutover so `raftabul.com` serves this app (it currently 301s to a WordPress
  placeholder whose `/sitemap.xml` 404s). **This is a real launch-blocker for SEO.**
- **Cloudflare "Block AI Bots" is ON** (GPTBot, ClaudeBot, Google-Extended blocked in
  robots.txt). Decision for the owner: keep blocking AI-training crawlers vs. allow the
  AI-**search** agents (GPTBot/OAI-SearchBot/PerplexityBot/Google-Extended) for
  ChatGPT/Gemini/Perplexity citation visibility.
