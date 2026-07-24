# Search

Laravel Scout with a hand-written OpenSearch engine.

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
make search-index M="App\Modules\Catalogue\Domain\Product"
php artisan scout:flush "App\...\Product"
```

Indexing is queued (`SCOUT_QUEUE=true`) onto the `search` queue in every
environment except tests — a synchronous index put an HTTP round-trip inside the
user's save request.

`SCOUT_DRIVER=null` in `.env.testing`, so the suite never reaches a cluster.

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
