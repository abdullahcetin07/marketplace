# ADR-090 Free-Text Search Runs Through a Typo-Tolerant Engine, Ranked, With the Database Fold as Its Fallback

> **STATUS: PROPOSED — DRAFT, awaiting owner ratification (ADR-018).**
> Not implemented. On approval this becomes ADR-090 in
> `Architecture_Decision_Record.md` and gets a row in the `001_Architecture.md`
> amendment log in the same change. It **supersedes the search half** of the
> Tier-1 fold (the folded `search_text` LIKE path) by demoting it to a fallback,
> and it changes infrastructure — so it is a decision, not a sprint detail.

## Context

ADR-079's `search_text` fold (Tier 1) fixed the catastrophe — `gunes` now finds
`güneş`, `avene` finds `Avène`, brand and multi-token queries match. But a folded
`LIKE '%…%'` is still a **substring filter, not search**. Three things it cannot do,
each measured missing on the live catalogue:

- **Typo tolerance.** `seurm`→serum, `uriaj`→uriage, `depidem`→depiderm all return
  nothing. A shopper who misspells gets a dead end, not the product.
- **Relevance ranking.** A `LIKE` result set has no order but the default sort. A
  query for a brand returns its 100 products with the exact-name match buried, not
  first. There is no "closest match on top."
- **As-you-type suggestion.** There is no prefix/instant surface, so the search box
  is a blind text field — the opposite of the Trendyol/Hepsiburada box a shopper
  expects, where products, brands and categories drop down as they type.

These are not `LIKE` tuning problems. They are what a search **engine** exists to do.

## Decision

**The free-text (`q`) path moves onto Meilisearch, driven through Laravel Scout,
which is already wired on `Product` (`Searchable`, `toSearchableArray`,
`SyncProductSearchIndex`). The Tier-1 folded `search_text` LIKE stays in the codebase
as the fallback the query uses when the engine is unreachable.**

Four parts, in order of how load-bearing they are.

**1. Meilisearch, self-hosted, over the alternatives.** Meilisearch ships typo
tolerance, prefix/instant search, synonyms, diacritic-and-case normalization, faceting
and configurable ranking out of the box; it runs as a single self-hosted binary; and
Scout has an official driver. Typesense is a near-equal and the fallback choice if a
future need (vector/semantic search) argues for it — deferred, not chosen. Algolia is
rejected: it is hosted, priced per search, and sends the catalogue to a third party
(cost and a KVKK surface we do not need). Elasticsearch/OpenSearch is rejected as
operational weight this catalogue's size does not earn. **Self-hosting is the point** —
the data stays on our infrastructure and the licence cost is zero; what we pay is
operating a service (below).

**2. The engine ranks; the database still filters price and stock — and price never
enters the index.** This is the one real design decision. Meilisearch returns
relevance-ranked **Catalog** product ids for the text query. The existing Offer-aware
listing query then takes that id set and applies price bounds, stock and the user's
explicit sort, exactly as today. When the user has not chosen a price sort, the
listing **preserves Meilisearch's relevance order**; when they sort by price, the match
set is Meilisearch's and the order is price's. **`price` and `stock` are never indexed
in Meilisearch** — they live in Offer, and `CatalogBoundaryTest` fails the build if
they leak into the Catalog. The index carries only what a product *is*.

**3. The fold is the fallback, not deleted.** If Meilisearch is down or reindexing, the
`q` path degrades to the Tier-1 folded `search_text` LIKE rather than to an empty page —
the same resilience posture as ADR-084. Search gets worse (no typo, no ranking); it does
not disappear. This keeps the engine from becoming a new single point of failure for the
one thing every shopper starts with.

**4. Autocomplete is a new read, and the storefront owns its surface.** A
`GET /api/v1/search/suggest?q=` endpoint runs a Meilisearch prefix search and returns the
top **products, brands and categories**. The storefront renders the drop-down under the
search box — debounced, keyboard-navigable, product image + price, Enter to the full
results page, and an empty state that offers a "did you mean" + popular queries. This is
the visible half of the Trendyol feel and it is a storefront change (ADR-058 split).

### Index shape and ranking

- **Searchable attributes, in priority order:** title, then brand, then the notable
  product attributes. Nothing from Offer.
- **Filterable:** category path and brand (for facets). **Not price** — that filter
  stays in the Offer-aware query.
- **Ranking rules:** Meilisearch's defaults (words → typo → proximity → attribute →
  exactness) followed by a **custom rule** that lifts higher-selling and in-stock
  products, so the exact, popular, buyable match sits on top.
- **Typo tolerance:** one edit at ≥5 characters, two at ≥9 (tunable).
- **Synonyms:** a maintained list — `güneş kremi ↔ güneş koruyucu ↔ spf`,
  `nemlendirici ↔ nemlendirme`, known brand spellings (`uriaj ↔ uriage`). Diacritic and
  case folding is the engine's; synonyms cover meaning, not spelling.
- **GTIN/SKU stay exact.** A barcode is the one you hold or a different product; it is
  matched exactly, never fuzzily, as today.

### Operations

Meilisearch runs as a managed service (systemd or a container) with a **master key the
owner puts in the production `.env`** — this repository never handles that key.
`SCOUT_QUEUE=true` keeps indexing on the `search` queue, so **a queue worker is part of
the feature** — without it the index goes stale exactly as ADR-074's import does. Rollout
is a full `scout:import`, after which the existing `SyncProductSearchIndex` listener keeps
it fresh; `catalog:refresh-search-text` (Tier 1) still rebuilds the fallback haystack.

## Cost, stated

**A search engine is a second datastore to keep alive and consistent.** The index can
drift from the catalogue — a bug in `toSearchableArray`, a worker that stopped, a reindex
that half-ran — and a drifted index is a product that is right in Postgres and unfindable
to every shopper, the failure Tier 1's derived-column note already warned about, now one
layer further out. Consistency is only ever eventual: a moderation change reaches the
index when the queue drains, not on commit.

**The reconciliation between engine relevance and Offer filtering is real complexity**,
and it is where correctness bugs will hide — an id set from one system, ordered by it,
then filtered and sometimes re-sorted by another. Pagination across that boundary
(Meilisearch ranks, the database paginates the filtered subset) is the fiddly part and
must be tested against the exact query cases, not assumed.

**Two things now depend on infrastructure the application cannot self-heal:** the
Meilisearch service being up, and its master key being present and correct. The fallback
means an outage degrades rather than breaks search — but degraded search running silently
is its own trap, because the site looks fine while typo tolerance and ranking are quietly
gone; the degraded state must be observable, not invisible.

**Synonyms and ranking are editorial, not set-once.** They will need tending as the
catalogue and the language around it grow, and a stale synonym list is a slow, unmeasured
loss rather than a visible error.

**What this does not buy:** it is still lexical search, not semantic — it will not
understand "yaz sonrası cilt onarımı" as a concept, only as words. That is the Typesense
vector door left open, deliberately, for a later ADR if the need is ever proven.

## What stays out of scope

- **Price/stock in the index** — Offer's, never Catalog's (`CatalogBoundaryTest`).
- **Semantic/vector search** — deferred; revisit only with evidence.
- **Personalised ranking** — out; ranking is catalogue-wide, not per-user.
- **Deleting the fold** — it is the fallback; it stays.

## Open questions for ratification

1. **Meilisearch confirmed** over Typesense for v1? (Recommendation: yes.)
2. **Relevance-order pagination** across the Meili→Offer boundary — acceptable as
   designed, or does a price-sorted-by-default listing change the reconciliation?
3. **Synonym ownership** — who maintains the list, and through what surface (config,
   admin, or a flat file)?
4. **Degraded-search observability** — where the "engine down, running on fallback"
   signal lands (log, health check, admin banner).
