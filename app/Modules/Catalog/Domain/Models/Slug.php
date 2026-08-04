<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Catalog\Domain\Enums\SluggableType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One public storefront address (ADR-059).
 *
 * A ROW IS EITHER THE CANONICAL ADDRESS OF SOMETHING, OR A RETIRED ALIAS THAT
 * STILL POINTS AT IT. That is the whole model: `is_canonical` distinguishes the
 * two, and the resolver returns both the slug asked for and the canonical one so
 * a storefront can 301 rather than 404.
 *
 * NO UUID, unusually for this codebase, and it is not an oversight. Every other
 * public-facing row here carries one because the internal id must never leave
 * (non-negotiable #7) — but a slug IS the public identifier of this row, and it
 * is already unique. A second public key on a table whose only column of interest
 * is a public key would be a key nobody could have a use for.
 *
 * NO WRITES FROM ANYWHERE BUT THE REGISTRY. Uniqueness, reserved words and the
 * demote-on-rename dance all live in `SlugRegistry`; a model that could be
 * `create()`d directly would be a second path around all three.
 *
 * @property int $id
 * @property string $slug
 * @property SluggableType $sluggable_type
 * @property int $sluggable_id
 * @property bool $is_canonical
 *
 * @see App\Modules\Catalog\Infrastructure\Registries\SlugRegistry
 */
final class Slug extends Model
{
    protected $table = 'slugs';

    protected $fillable = [
        'slug',
        'sluggable_type',
        'sluggable_id',
        'is_canonical',
    ];

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeCanonical(Builder $query): Builder
    {
        return $query->where('is_canonical', true);
    }

    /**
     * Every slug an entity owns, canonical first.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeOwnedBy(Builder $query, SluggableType $type, int $id): Builder
    {
        return $query
            ->where('sluggable_type', $type->value)
            ->where('sluggable_id', $id)
            ->orderByDesc('is_canonical');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sluggable_type' => SluggableType::class,
            'sluggable_id' => 'integer',
            'is_canonical' => 'boolean',
        ];
    }
}
