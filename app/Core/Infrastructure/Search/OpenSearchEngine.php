<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Search;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use OpenSearch\Client;

/**
 * Laravel Scout engine for OpenSearch.
 *
 * WHY HAND-WRITTEN: the community Scout↔OpenSearch bridges are thin wrappers
 * that lag Laravel releases and hide the query DSL behind an abstraction we
 * would fight the moment search relevance matters — which, on a marketplace,
 * is immediately. This engine is ~250 lines against the official
 * opensearch-project/opensearch-php client, has no upgrade risk beyond Scout's
 * own interface, and leaves the raw DSL reachable through Builder::options().
 *
 * A searchable model may implement any of these to control its document and
 * its index, none of which Scout provides hooks for by default:
 *
 *   toSearchableArray(): array          — the document (Scout's own hook)
 *   searchableMapping(): array          — explicit field mappings
 *   searchableFields(): array           — fields multi_match queries target,
 *                                         with optional ^boost suffixes
 *
 * Registered as the `opensearch` driver by SearchServiceProvider.
 *
 * @see docs/search.md
 */
final class OpenSearchEngine extends Engine
{
    public function __construct(
        private readonly Client $client,
        private readonly bool $softDelete = false,
    ) {}

    /**
     * Index or reindex a set of models.
     *
     * @param  EloquentCollection<int, Model>  $models
     */
    public function update($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        /** @var Model&\Laravel\Scout\Searchable $first */
        $first = $models->first();
        $index = $this->indexName($first);

        $this->ensureIndexExists($first);

        $body = [];

        foreach ($models as $model) {
            /** @var Model&\Laravel\Scout\Searchable $model */
            $document = $model->toSearchableArray();

            if ($document === []) {
                continue;
            }

            if ($this->softDelete && method_exists($model, 'pushSoftDeleteMetadata')) {
                $document = array_merge($document, $model->scoutMetadata());
            }

            $body[] = ['index' => [
                '_index' => $index,
                '_id' => $model->getScoutKey(),
            ]];

            $body[] = $document;
        }

        if ($body === []) {
            return;
        }

        $response = $this->client->bulk(['refresh' => false, 'body' => $body]);

        // A bulk request returns 200 even when individual documents fail.
        // Surfacing that is the difference between "search is stale" being
        // noticed now versus by a customer next week.
        if (($response['errors'] ?? false) === true) {
            throw SearchIndexingFailed::fromBulkResponse($index, $response);
        }
    }

    /**
     * @param  EloquentCollection<int, Model>  $models
     */
    public function delete($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        /** @var Model&\Laravel\Scout\Searchable $first */
        $first = $models->first();
        $index = $this->indexName($first);

        $body = [];

        foreach ($models as $model) {
            /** @var Model&\Laravel\Scout\Searchable $model */
            $body[] = ['delete' => [
                '_index' => $index,
                '_id' => $model->getScoutKey(),
            ]];
        }

        $this->client->bulk(['refresh' => false, 'body' => $body]);
    }

    /**
     * @return array<string, mixed>
     */
    public function search(Builder $builder): array
    {
        return $this->performSearch($builder, array_filter([
            'size' => $builder->limit,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function paginate(Builder $builder, $perPage, $page): array
    {
        return $this->performSearch($builder, [
            'size' => $perPage,
            'from' => ($page - 1) * $perPage,
        ]);
    }

    /**
     * @param  array<string, mixed>  $results
     * @return Collection<int, mixed>
     */
    public function mapIds($results): Collection
    {
        return collect($results['hits']['hits'] ?? [])
            ->pluck('_id')
            ->values();
    }

    /**
     * Hydrate hits into models.
     *
     * Order matters: OpenSearch returns hits by relevance, but a whereIn query
     * returns rows in whatever order PostgreSQL likes. Restoring the hit order
     * is the point of the sortBy below — without it, relevance ranking is
     * silently discarded.
     *
     * @param  array<string, mixed>  $results
     * @return EloquentCollection<int, Model>
     */
    public function map(Builder $builder, $results, $model): EloquentCollection
    {
        if ($this->getTotalCount($results) === 0) {
            return $model->newCollection();
        }

        $ids = $this->mapIds($results)->all();
        $positions = array_flip($ids);

        /** @var Model&\Laravel\Scout\Searchable $model */
        return $model->getScoutModelsByIds($builder, $ids)
            ->filter(static fn (Model $found): bool => in_array((string) $found->getScoutKey(), array_map('strval', $ids), true))
            ->sortBy(static fn (Model $found): int => $positions[(string) $found->getScoutKey()] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $results
     * @return LazyCollection<int, Model>
     */
    public function lazyMap(Builder $builder, $results, $model): LazyCollection
    {
        return LazyCollection::make($this->map($builder, $results, $model)->all());
    }

    /**
     * @param  array<string, mixed>  $results
     */
    public function getTotalCount($results): int
    {
        return (int) ($results['hits']['total']['value'] ?? 0);
    }

    public function flush($model): void
    {
        /** @var Model&\Laravel\Scout\Searchable $model */
        $index = $this->indexName($model);

        if (! $this->indexExists($index)) {
            return;
        }

        $this->client->deleteByQuery([
            'index' => $index,
            'body' => ['query' => ['match_all' => (object) []]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function createIndex($name, array $options = []): array
    {
        if ($this->indexExists($name)) {
            return [];
        }

        return $this->client->indices()->create([
            'index' => $name,
            'body' => [
                'settings' => array_merge(
                    (array) config('opensearch.index_settings'),
                    [
                        'analysis' => array_merge(
                            (array) config('opensearch.analysis'),
                            [
                                'filter' => array_merge(
                                    (array) config('opensearch.analysis.filter'),
                                    ['edge_ngram_filter' => [
                                        'type' => 'edge_ngram',
                                        'min_gram' => 2,
                                        'max_gram' => 20,
                                    ]],
                                ),
                            ],
                        ),
                    ],
                ),
                'mappings' => $options['mappings'] ?? (object) [],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteIndex($name): array
    {
        if (! $this->indexExists($name)) {
            return [];
        }

        return $this->client->indices()->delete(['index' => $name]);
    }

    public function indexExists(string $index): bool
    {
        return (bool) $this->client->indices()->exists(['index' => $index]);
    }

    /**
     * Build and run the query.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function performSearch(Builder $builder, array $options = []): array
    {
        /** @var Model&\Laravel\Scout\Searchable $model */
        $model = $builder->model;
        $index = $this->indexName($model);

        if (! $this->indexExists($index)) {
            // An unbuilt index is an empty result set, not a 500. This matters
            // on first deploy and in any environment where indexing has not
            // caught up yet.
            return ['hits' => ['total' => ['value' => 0], 'hits' => []]];
        }

        // An escape hatch: a caller that needs the raw DSL passes a callback
        // to Model::search() and bypasses everything below.
        if ($builder->callback !== null) {
            return (array) call_user_func($builder->callback, $this->client, $builder->query, $options);
        }

        $body = [
            'query' => [
                'bool' => [
                    'must' => [$this->matchQuery($builder, $model)],
                    'filter' => $this->filters($builder),
                ],
            ],
        ];

        if ($builder->orders !== []) {
            $body['sort'] = array_map(
                static fn (array $order): array => [$order['column'] => ['order' => $order['direction']]],
                $builder->orders,
            );
        }

        return (array) $this->client->search([
            'index' => $index,
            'body' => array_merge($body, array_filter($options, static fn (mixed $v): bool => $v !== null)),
        ]);
    }

    /**
     * An empty query means "everything" (a filtered browse); a non-empty one
     * is a multi_match across the model's declared searchable fields.
     *
     * @return array<string, mixed>
     */
    private function matchQuery(Builder $builder, Model $model): array
    {
        if (blank($builder->query)) {
            return ['match_all' => (object) []];
        }

        $fields = method_exists($model, 'searchableFields')
            ? $model->searchableFields()
            : ['*'];

        return [
            'multi_match' => [
                'query' => $builder->query,
                'fields' => $fields,
                // best_fields with fuzziness tolerates the typos that a real
                // search box receives constantly.
                'type' => 'best_fields',
                'fuzziness' => 'AUTO',
                'operator' => 'and',
            ],
        ];
    }

    /**
     * Translate Scout's where/whereIn clauses into OpenSearch filters.
     *
     * Filters, not queries: filter context is cacheable and does not
     * contribute to the relevance score, which is what you want for a
     * category or price constraint.
     *
     * @return array<int, array<string, mixed>>
     */
    private function filters(Builder $builder): array
    {
        $filters = [];

        foreach ($builder->wheres as $field => $value) {
            $filters[] = ['term' => [$field => $value]];
        }

        foreach ($builder->whereIns as $field => $values) {
            $filters[] = ['terms' => [$field => array_values($values)]];
        }

        foreach ($builder->whereNotIns as $field => $values) {
            $filters[] = ['bool' => ['must_not' => [['terms' => [$field => array_values($values)]]]]];
        }

        return $filters;
    }

    /**
     * Create the index with the model's mapping if it is not there yet.
     */
    private function ensureIndexExists(Model $model): void
    {
        $index = $this->indexName($model);

        if ($this->indexExists($index)) {
            return;
        }

        $this->createIndex($index, [
            'mappings' => method_exists($model, 'searchableMapping')
                ? ['properties' => $model->searchableMapping()]
                : (object) [],
        ]);
    }

    private function indexName(Model $model): string
    {
        /** @var Model&\Laravel\Scout\Searchable $model */
        return config('scout.prefix').$model->searchableAs();
    }
}
