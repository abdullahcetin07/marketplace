<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Repositories;

use App\Modules\Catalog\Domain\Contracts\AttributeRepositoryContract;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\AttributeValue;
use App\Modules\Catalog\Domain\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The attribute schema, loaded whole.
 *
 * `values` is on `$with` because an attribute is almost never useful without
 * its options — every schema read feeds a form or a validation pass that needs
 * them, and strict mode makes discovering that at the call site an exception.
 *
 * `schemaFor` is the load every §3.2/§3.3 rule reads. `requiredFor` and
 * `variantDefiningFor` narrow it with `wherePivot`, so "required HERE" and
 * "variant-defining HERE" are answered by the binding row rather than by the
 * attribute's global default — which is the whole point of the pivot carrying
 * those flags (§2.3).
 *
 * @see App\Modules\Catalog\Domain\Contracts\AttributeRepositoryContract
 */
final class AttributeRepository implements AttributeRepositoryContract
{
    /**
     * @var list<string>
     */
    private array $with = ['values'];

    public function findByUuid(string $uuid): ?Attribute
    {
        return Attribute::query()->with($this->with)->where('uuid', $uuid)->first();
    }

    public function findOrFailByUuid(string $uuid): Attribute
    {
        $attribute = $this->findByUuid($uuid);

        if ($attribute === null) {
            throw (new ModelNotFoundException)->setModel(Attribute::class, [$uuid]);
        }

        return $attribute;
    }

    public function findByCode(string $code): ?Attribute
    {
        return Attribute::query()->with($this->with)->where('code', $code)->first();
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return Attribute::query()
            ->where('code', $code)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function findValueByUuid(string $uuid): ?AttributeValue
    {
        return AttributeValue::query()->where('uuid', $uuid)->first();
    }

    /**
     * @param  array<int, string>  $uuids
     * @return Collection<int, AttributeValue>
     */
    public function valuesByUuids(array $uuids): Collection
    {
        if ($uuids === []) {
            return AttributeValue::query()->whereRaw('1 = 0')->get();
        }

        return AttributeValue::query()->whereIn('uuid', $uuids)->get();
    }

    /**
     * A category's bound attributes, pivot flags included.
     *
     * @return Collection<int, Attribute>
     */
    public function schemaFor(Category $category): Collection
    {
        return $category->attributes()->with($this->with)->get();
    }

    /**
     * @return Collection<int, Attribute>
     */
    public function variantDefiningFor(Category $category): Collection
    {
        return $category->attributes()
            ->with($this->with)
            ->wherePivot('is_variant_defining', true)
            ->get();
    }

    /**
     * @return Collection<int, Attribute>
     */
    public function requiredFor(Category $category): Collection
    {
        return $category->attributes()
            ->with($this->with)
            ->wherePivot('is_required', true)
            ->get();
    }

    /**
     * @return Collection<int, Attribute>
     */
    public function active(): Collection
    {
        return Attribute::query()->with($this->with)->active()->orderBy('position')->orderBy('code')->get();
    }
}
