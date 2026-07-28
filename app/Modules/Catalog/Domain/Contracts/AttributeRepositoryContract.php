<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Contracts;

use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\AttributeValue;
use App\Modules\Catalog\Domain\Models\Category;
use Illuminate\Database\Eloquent\Collection;

/**
 * The persistence port for attributes, their values, and the category bindings
 * that give them meaning (§2.3).
 *
 * `schemaFor` is the one every integrity rule in §3.2/§3.3 depends on: it
 * returns a category's bound attributes WITH their pivot flags, so "is Renk
 * required here" and "is Renk a variant axis here" are answered from one load
 * rather than re-queried per attribute.
 *
 * @see App\Modules\Catalog\Infrastructure\Repositories\AttributeRepository
 */
interface AttributeRepositoryContract
{
    public function findByUuid(string $uuid): ?Attribute;

    public function findOrFailByUuid(string $uuid): Attribute;

    public function findByCode(string $code): ?Attribute;

    public function codeExists(string $code, ?int $exceptId = null): bool;

    public function findValueByUuid(string $uuid): ?AttributeValue;

    /**
     * @param array<int, string> $uuids
     * @return Collection<int, AttributeValue>
     */
    public function valuesByUuids(array $uuids): Collection;

    /**
     * A category's bound attributes with their per-category pivot flags.
     *
     * @return Collection<int, Attribute>
     */
    public function schemaFor(Category $category): Collection;

    /**
     * The subset of `schemaFor` marked variant-defining IN THIS CATEGORY — the
     * axes a variant combination is built from (ADR-039).
     *
     * @return Collection<int, Attribute>
     */
    public function variantDefiningFor(Category $category): Collection;

    /**
     * The subset marked required IN THIS CATEGORY — checked on publish, not on
     * draft, so authoring stays incremental (§3.2).
     *
     * @return Collection<int, Attribute>
     */
    public function requiredFor(Category $category): Collection;

    /**
     * @return Collection<int, Attribute>
     */
    public function active(): Collection;
}
