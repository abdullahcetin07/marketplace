<?php

declare(strict_types=1);

namespace App\Core\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The persistence port every repository implements.
 *
 * It lives in the Domain layer while its Eloquent implementation lives in
 * Infrastructure. That direction of dependency is the whole point: domain
 * services type-hint this interface and never mention Eloquent, so a module's
 * business rules can be unit-tested against an in-memory fake.
 *
 * @template TModel of Model
 */
interface RepositoryContract
{
    /**
     * @return class-string<TModel>
     */
    public function model(): string;

    /**
     * @return TModel|null
     */
    public function find(int|string $id): ?Model;

    /**
     * @return TModel
     */
    public function findOrFail(int|string $id): Model;

    /**
     * @param array<string, mixed> $criteria
     *
     * @return TModel|null
     */
    public function findBy(array $criteria): ?Model;

    /**
     * @param array<string, mixed> $criteria
     *
     * @return Collection<int, TModel>
     */
    public function all(array $criteria = []): Collection;

    /**
     * @param array<string, mixed> $criteria
     *
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(int $perPage = 25, array $criteria = []): LengthAwarePaginator;

    /**
     * @param array<string, mixed> $attributes
     *
     * @return TModel
     */
    public function create(array $attributes): Model;

    /**
     * @param TModel $model
     * @param array<string, mixed> $attributes
     *
     * @return TModel
     */
    public function update(Model $model, array $attributes): Model;

    /**
     * @param TModel $model
     */
    public function delete(Model $model): bool;

    /**
     * @param array<string, mixed> $criteria
     */
    public function exists(array $criteria): bool;

    /**
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria = []): int;
}
