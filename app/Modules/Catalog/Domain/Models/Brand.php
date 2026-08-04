<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Catalog\Domain\Concerns\HasRegisteredSlug;
use App\Modules\Catalog\Domain\Enums\SluggableType;
use App\Shared\Traits\HasMedia;
use App\Shared\Traits\HasUuid;
use Database\Modules\Catalog\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia as HasMediaContract;

/**
 * A manufacturer or marque (Catalog.md §2.2).
 *
 * Platform-owned like the taxonomy: a seller picks a brand, never invents one.
 * Two spellings of "Samsung" split every brand filter and every brand page, and
 * merging them afterwards is manual work on live data.
 *
 * NOT LOCALIZED. A brand name is a proper noun — "Beko" is "Beko" in every
 * locale — so it gets one column, unlike category and product text (§13.5).
 *
 * Nullable on Product: generic and unbranded goods are real, and forcing a
 * "Markasız" placeholder brand would pollute the brand filter with a non-brand.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 *
 * @see docs/modules/Catalog.md §2.2
 */
final class Brand extends Model implements HasMediaContract
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    use HasMedia;
    use HasRegisteredSlug;
    use HasUuid;

    protected $table = 'brands';

    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * The brand logo — the first image in the shared `images` collection, which
     * `App\Shared\Traits\HasMedia` already points at the public disk. Catalog
     * imagery is meant to be seen (§6).
     */
    public function logoUrl(): ?string
    {
        return $this->imageUrl('thumb');
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

    /**
     * This entity's kind in the global slug namespace (ADR-059).
     */
    public function sluggableType(): SluggableType
    {
        return SluggableType::Brand;
    }

    protected static function newFactory(): BrandFactory
    {
        return BrandFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
