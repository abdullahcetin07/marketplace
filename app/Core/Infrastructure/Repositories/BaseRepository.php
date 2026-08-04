<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Repositories;

use App\Core\Domain\Contracts\RepositoryContract;
use Generator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Eloquent implementation of the persistence port.
 *
 * WHY REPOSITORIES IN A LARAVEL APP: not to abstract away the ORM — we are not
 * going to swap PostgreSQL for something else. The reason is containment. Query
 * logic that lives in controllers and Filament resources gets copy-pasted, and
 * the copies drift; six months later "active offers" means three different
 * things in three places. A repository is the one file where a module's query
 * vocabulary lives, and it is the seam that makes N+1 prevention enforceable
 * (see $with).
 *
 * Concrete repositories declare their model and their default eager loads:
 *
 *     final class StoreRepository extends BaseRepository
 *     {
 *         protected array $with = ['owner'];
 *
 *         public function model(): string { return Store::class; }
 *
 *         public function approved(): Collection
 *         {
 *             return $this->query()->whereStatus(StoreStatus::Approved)->get();
 *         }
 *     }
 *
 * @template TModel of Model
 *
 * @implements RepositoryContract<TModel>
 */
abstract class BaseRepository implements RepositoryContract
{
    /**
     * Relations eager-loaded on every query this repository builds.
     *
     * This is the primary defence against N+1: because strict mode makes lazy
     * loading throw (see AppServiceProvider), the relations a module always
     * needs are declared once, here, instead of being rediscovered by whoever
     * hits the exception next.
     *
     * @var array<int, string>
     */
    protected array $with = [];

    /**
     * Relation counts loaded on every query.
     *
     * @var array<int, string>
     */
    protected array $withCount = [];

    /**
     * Default ordering applied by all() and paginate().
     *
     * @var array<string, string>
     */
    protected array $orderBy = ['id' => 'desc'];

    /**
     * @return class-string<TModel>
     */
    abstract public function model(): string;

    /**
     * @return TModel|null
     */
    public function find(int|string $id): ?Model
    {
        return $this->query()->find($id);
    }

    /**
     * @return TModel
     *
     * @throws ModelNotFoundException<TModel>
     */
    public function findOrFail(int|string $id): Model
    {
        return $this->query()->findOrFail($id);
    }

    /**
     * @return TModel|null
     */
    public function findByUuid(string $uuid): ?Model
    {
        return $this->query()->where('uuid', $uuid)->first();
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return TModel|null
     */
    public function findBy(array $criteria): ?Model
    {
        return $this->applyCriteria($this->query(), $criteria)->first();
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return Collection<int, TModel>
     */
    public function all(array $criteria = []): Collection
    {
        return $this->applyOrder($this->applyCriteria($this->query(), $criteria))->get();
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(int $perPage = 25, array $criteria = []): LengthAwarePaginator
    {
        return $this->applyOrder($this->applyCriteria($this->query(), $criteria))
            ->paginate(min($perPage, 100));
    }

    /**
     * Memory-safe iteration for exports and back-fills. Never load a full
     * marketplace table with all().
     *
     * @param array<string, mixed> $criteria
     *
     * @return Generator<int, TModel>
     */
    public function cursor(array $criteria = []): Generator
    {
        yield from $this->applyCriteria($this->query(), $criteria)->lazyById(500);
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return TModel
     */
    public function create(array $attributes): Model
    {
        return $this->query()->create($attributes);
    }

    /**
     * @param TModel $model
     * @param array<string, mixed> $attributes
     *
     * @return TModel
     */
    public function update(Model $model, array $attributes): Model
    {
        $model->fill($attributes)->save();

        return $model->refresh();
    }

    /**
     * @param TModel $model
     */
    public function delete(Model $model): bool
    {
        return (bool) $model->delete();
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function exists(array $criteria): bool
    {
        return $this->applyCriteria($this->newQuery(), $criteria)->exists();
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria = []): int
    {
        return $this->applyCriteria($this->newQuery(), $criteria)->count();
    }

    /**
     * Query with this repository's eager loads applied. Use inside concrete
     * repository methods.
     *
     * @return Builder<TModel>
     */
    protected function query(): Builder
    {
        $query = $this->newQuery();

        if ($this->with !== []) {
            $query->with($this->with);
        }

        if ($this->withCount !== []) {
            $query->withCount($this->withCount);
        }

        return $query;
    }

    /**
     * Raw query with no eager loads — for exists()/count() where loading
     * relations would be wasted work.
     *
     * @return Builder<TModel>
     */
    protected function newQuery(): Builder
    {
        return $this->model()::query();
    }

    /**
     * Translate a criteria array into where clauses.
     *
     * Supported shapes:
     *   ['status' => 'active']                  => where
     *   ['status' => ['active', 'pending']]     => whereIn
     *   ['deleted_at' => null]                  => whereNull
     *   ['price' => ['>=', 1000]]               => operator comparison
     *
     * @param Builder<TModel> $query
     * @param array<string, mixed> $criteria
     *
     * @return Builder<TModel>
     */
    protected function applyCriteria(Builder $query, array $criteria): Builder
    {
        foreach ($criteria as $column => $value) {
            match (true) {
                $value === null => $query->whereNull($column),
                // [operator, value] pair, e.g. ['>=', 1000]
                is_array($value) && count($value) === 2 && is_string($value[0] ?? null)
                    && in_array($value[0], ['=', '!=', '<', '<=', '>', '>=', 'like', 'ilike'], true) => $query->where($column, $value[0], $value[1]),
                is_array($value) => $query->whereIn($column, $value),
                default => $query->where($column, $value),
            };
        }

        return $query;
    }

    /**
     * @param Builder<TModel> $query
     *
     * @return Builder<TModel>
     */
    protected function applyOrder(Builder $query): Builder
    {
        foreach ($this->orderBy as $column => $direction) {
            $query->orderBy($column, $direction);
        }

        return $query;
    }
}
