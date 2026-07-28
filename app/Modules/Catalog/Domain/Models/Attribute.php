<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Catalog\Domain\Concerns\HasLocalizedText;
use App\Modules\Catalog\Domain\Enums\AttributeType;
use App\Shared\Traits\HasUuid;
use Database\Modules\Catalog\Factories\AttributeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A typed property a product can carry — Renk, Beden, Malzeme (Catalog.md §2.3).
 *
 * Platform-owned (ADR-038). An attribute exists once and is BOUND to the
 * categories it applies to; the binding, not the attribute, decides whether it
 * is required and whether it defines variants there. That indirection is the
 * point: Renk is a variant axis in Giyim and a plain description in Mobilya, and
 * one shared definition keeps both sides of that filtering on the same values.
 *
 * The flags here are the DEFAULTS a new binding starts from, not the effective
 * answer — always read `is_required` / `is_variant_defining` from the
 * `category_attribute` pivot when asking about a specific category.
 *
 * `code` is the stable machine handle (`color`, `size`). Labels are localized
 * and may be re-worded freely; the code is what an import, a search facet or a
 * later migration keys on, so it never changes.
 *
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property string $name_tr
 * @property string|null $name_en
 * @property AttributeType $type
 * @property bool $is_variant_defining
 * @property bool $is_filterable
 * @property bool $is_active
 * @property int $position
 * @property-read Collection<int, AttributeValue> $values
 *
 * @see docs/modules/Catalog.md §2.3
 */
final class Attribute extends Model
{
    /** @use HasFactory<AttributeFactory> */
    use HasFactory;

    use HasLocalizedText;
    use HasUuid;

    protected $table = 'attributes';

    protected static function newFactory(): AttributeFactory
    {
        return AttributeFactory::new();
    }

    protected $fillable = [
        'code',
        'name_tr',
        'name_en',
        'type',
        'is_variant_defining',
        'is_filterable',
        'is_active',
        'position',
    ];

    /**
     * @return array<int, string>
     */
    public static function localizedAttributes(): array
    {
        return ['name'];
    }

    /**
     * The enumerated values, for `Select` attributes only.
     *
     * @return HasMany<AttributeValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class)->orderBy('position');
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_attribute')
            ->withPivot(['is_required', 'is_variant_defining', 'is_filterable', 'position'])
            ->withTimestamps();
    }

    /**
     * Whether this attribute is eligible to be a variant axis anywhere.
     *
     * TWO CONDITIONS, both necessary: the type must be enumerable (ADR-039 — a
     * cartesian needs finite axes) and the attribute must be flagged for it. A
     * binding may only turn variant-defining ON for an attribute that passes
     * this; the type check is the one that cannot be overridden per category.
     */
    public function canDefineVariants(): bool
    {
        return $this->type->canDefineVariants();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, AttributeType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AttributeType::class,
            'is_variant_defining' => 'boolean',
            'is_filterable' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
