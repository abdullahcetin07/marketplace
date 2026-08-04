<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Catalog\Domain\Concerns\HasLocalizedText;
use App\Shared\Traits\HasUuid;
use Database\Modules\Catalog\Factories\AttributeValueFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One allowed value of a `Select` attribute — Renk → Kırmızı (Catalog.md §2.3).
 *
 * TWO FIELDS, DELIBERATELY: `value` is the stable machine handle (`red`) and
 * `label_*` is what a human reads. The handle is what a variant combination
 * hashes on and what an import matches, so re-wording "Kırmızı" to "Ateş
 * Kırmızısı" must not silently create a second colour.
 *
 * This is also the unit a variant axis is built from (ADR-039): a variant IS a
 * set of these, one per variant-defining attribute of its category.
 *
 * @property int $id
 * @property string $uuid
 * @property int $attribute_id
 * @property string $value
 * @property string $label_tr
 * @property string|null $label_en
 * @property bool $is_active
 * @property int $position
 * @property-read Attribute $attribute
 *
 * @see docs/modules/Catalog.md §2.3
 */
final class AttributeValue extends Model
{
    /** @use HasFactory<AttributeValueFactory> */
    use HasFactory;

    use HasLocalizedText;
    use HasUuid;

    protected $table = 'attribute_values';

    protected $fillable = [
        'attribute_id',
        'value',
        'label_tr',
        'label_en',
        'is_active',
        'position',
    ];

    /**
     * @return array<int, string>
     */
    public static function localizedAttributes(): array
    {
        return ['label'];
    }

    /**
     * @return BelongsTo<Attribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function newFactory(): AttributeValueFactory
    {
        return AttributeValueFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
