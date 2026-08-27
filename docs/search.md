# Search

Laravel Scout. **The engine in production is Meilisearch (ADR-090, 2026-08-27)**;
the hand-written OpenSearch engine described below is the earlier design and is
still in the codebase.

> **READ THIS BEFORE THE REST OF THE FILE.** What actually runs is:
>
> - **Meilisearch**, self-hosted, bound to `127.0.0.1:7700`, one process shared
>   by staging and production and separated by `SCOUT_PREFIX`. The systemd unit
>   is `meilisearch`; its master key lives in `/etc/meilisearch.env` and in each
>   `.env` as `MEILISEARCH_KEY`, never in this repository.
> - Index settings — searchable fields, facets, ranking, typo thresholds,
>   synonyms — come from `config/catalog.php` and are pushed by
>   **`search:sync-settings`**. They are not configured by hand through the API.
> - The buyer listing asks the engine through
>   `App\Modules\Catalog\Domain\Contracts\ProductSearchContract`. When it answers
>   **`null`** — no driver, or the engine threw — the listing falls back to the
>   folded `products.search_text` `LIKE` (ADR-089) and logs a warning;
>   `GET /api/v1/health` reports `search: up | down | disabled`.
> - **`App\Core\Infrastructure\Search\OpenSearchEngine` is not what serves
>   search today.** It is kept because it is the only place the platform's
>   Turkish analyser work is written down, and because nothing forced a
>   deletion; treat the sections below as background, not as deployment truth.
>
> The seller-facing product picker (`CatalogBrowse`) deliberately stays on the
> fold: exactness beats typo tolerance in an internal panel, and it must keep
> working when the engine does not.

---

## Why hand-written

The community Scout↔OpenSearch bridges are thin wrappers that lag Laravel
releases and hide the query DSL behind an abstraction we would fight the moment
search relevance matters — which, on a marketplace, is immediately.

`App\Core\Infrastructure\Search\OpenSearchEngine` is ~250 lines against the
official `opensearch-project/opensearch-php` client. It has no upgrade risk
beyond Scout's own interface, and the raw DSL stays reachable.

---

## Making a model searchable

The worked example below is no longer hypothetical: `App\Modules\Catalog\Domain\Models\Product`
is the first Searchable model in the platform, and it is the one to copy. It
indexes on `ProductPublished` and drops on `ProductArchived` through a listener
rather than Scout's automatic save hook — see `SyncProductSearchIndex` for why.

```php
final class Product extends Model
{
    use Searchable;

    public function searchableAs(): string
    {
        return 'products';   // prefixed with scout.prefix → mos_products
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category?->name,
            'status' => $this->status->value,
        ];
    }

    // Fields multi_match targets, with relevance boosts.
    public function searchableFields(): array
    {
        return ['name^3', 'category^2', 'description'];
    }

    // Explicit mapping — the index is created with this on first write.
    public function searchableMapping(): array
    {
        return [
            'name' => ['type' => 'text', 'analyzer' => 'turkish_analyzer'],
            'description' => ['type' => 'text', 'analyzer' => 'turkish_analyzer'],
            'category' => ['type' => 'keyword'],
            'status' => ['type' => 'keyword'],
        ];
    }
}
```

`searchableFields()` and `searchableMapping()` are our additions — Scout has no
hook for either, and without them every model gets `fields: ['*']` and dynamic
mapping, which produces poor relevance and unpredictable field types.

---

## Turkish analysis

This is not optional polish. Without the analyser in `config/opensearch.php`,
Turkish search results are visibly wrong:

| Filter | Fixes |
|---|---|
| `apostrophe` | `Ankara'dan` must match `Ankara` |
| `turkish_lowercase` | The dotted/dotless i — a plain lowercase filter turns `İSTANBUL` into something that will not match `istanbul` |
| `turkish_stemmer` | Agglutinative suffixes; otherwise every inflected form is a separate term |
| `asciifolding` | `ürün` matches `urun` |

A separate `turkish_autocomplete` analyser handles search-as-you-type. Edge
n-grams are applied at **index time only** — applying them at query time too
would match every prefix of every query term.

---

## Querying

```php
Product::search('kablosuz kulaklık')
    ->where('status', 'published')
    ->whereIn('category', ['audio', 'accessories'])
    ->orderBy('price', 'asc')
    ->paginate(24);
```

Scout `where`/`whereIn` become OpenSearch **filters**, not queries — filter
context is cacheable and does not contribute to the relevance score, which is
what you want for a category or price constraint.

The text query is a `multi_match` with `fuzziness: AUTO`, because a real search
box receives typos constantly.

Escape hatch for the raw DSL:

```php
Product::search('...', function (Client $client, string $query, array $options) {
    return $client->search([...]);  // full control
});
```

---

## Behaviour worth knowing

**Missing index returns empty, not 500.** `performSearch()` checks index
existence first. This matters on first deploy and whenever indexing has not
caught up.

**Relevance order is preserved.** OpenSearch returns hits by score; a `whereIn`
against PostgreSQL returns rows in arbitrary order. `map()` re-sorts by hit
position — without it, relevance ranking is silently discarded.

**Bulk errors are surfaced.** A bulk request returns HTTP 200 even when
individual documents fail. `SearchIndexingFailed` is thrown and **is
reportable** — a document that fails to index is silent data loss from the
customer's view: the product exists but cannot be found.

**Indexes are auto-created** with the model's mapping and the Turkish analysis
settings on first write.

---

## Operations

```bash
make search-index M="App\Modules\Catalog\Domain\Models\Product"
php artisan scout:flush "App\...\Product"
```

Indexing is queued (`SCOUT_QUEUE=true`) onto the `search` queue in every
environment except tests — a synchronous index put an HTTP round-trip inside the
user's save request.

`SCOUT_DRIVER=null` in `.env.testing`, so the suite never reaches a cluster.

**Also `null` on the bare-metal test box** (owner-approved 2026-08-02): there is
no OpenSearch there, and `OPENSEARCH_HOST=opensearch` is a Compose service name
that resolves to nothing on it, so every index job was failing. Scout falls back
to its `NullEngine` — the calls succeed and do nothing.

**Nothing was removed and nothing should be.** `Searchable` models, index jobs and
mappings are all still here; standing the cluster up and setting the driver back
to `opensearch` is the whole of that task. What is unavailable meanwhile is
buyer-facing relevance search — the public browse and the seller's catalogue
browse read Postgres by design (Offer.md §8.2) and are unaffected.

`scout.prefix` (`mos_`) namespaces indexes so staging and production can share a
cluster without overwriting each other's documents.

---

## Configuration notes

- **`ssl_verification` must be `true` in production.** An unverified TLS
  connection to the search cluster is an unauthenticated one in practice. It is
  `false` only for the local self-signed container.
- Single shard by default. Over-sharding is the most common cause of a slow
  small cluster; raise it deliberately, with measurement.
- The local `opensearch` container runs with the security plugin disabled. The
  production cluster does not.
