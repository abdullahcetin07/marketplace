<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Models;

use App\Shared\Traits\HasMedia;
use App\Shared\Traits\HasUuid;
use Database\Modules\Catalog\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia as HasMediaContract;

/**
 * The SKU — a unique combination of a product's variant-defining attribute
 * values, and the unit everything downstream actually transacts on (ADR-039).
 *
 * Offer, Inventory, cart lines and order lines will all reference a VARIANT, not
 * a product. That is why a product with no variant axes still gets one real
 * `is_default` variant instead of a "simple product" branch: retrofitting the
 * sellable unit later would mean rewriting all four of those contexts.
 *
 * **NO PRICE AND NO STOCK HERE EITHER** (ADR-037). A variant says *what* the
 * thing is; an Offer says what a given seller charges for it and Inventory says
 * how many they have.
 *
 * UNIQUENESS (§3.3) is enforced by `combination_key` — the variant's
 * attribute-value ids, sorted and delimited — under a UNIQUE index with
 * `product_id`. A derived key rather than a trio of nullable columns, because
 * "unique across a variable-length set" is not something a composite index can
 * express. Sorted so `{Renk:Kırmızı, Beden:M}` and `{Beden:M, Renk:Kırmızı}` are
 * the same variant, which they are.
 *
 * `sku` is GLOBALLY unique (§3.3), not per-product: it is the handle a seller,
 * a warehouse and a courier all use, and two different things sharing one is a
 * fulfilment error, not a catalog inconvenience.
 *
 * @property int $id
 * @property string $uuid
 * @property int $product_id
 * @property string $sku
 * @property string|null $barcode
 * @property string $combination_key
 * @property bool $is_default
 * @property int $position
 * @property-read Product $product
 * @property-read Collection<int, AttributeValue> $attributeValues
 *
 * @see docs/modules/Catalog.md §2.5, §3.3
 */
final class ProductVariant extends Model implements HasMediaContract
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    use HasMedia;
    use HasUuid;
    use SoftDeletes;

    /**
     * Delimiter closing both ends of every id in `combination_key`, so `-1-`
     * cannot prefix-match `-12-`.
     */
    public const string KEY_SEPARATOR = '-';

    /**
     * The key of a variant with no axes — the single default variant of a
     * product whose category defines no variant-defining attributes. A constant
     * rather than an empty string, so the UNIQUE index still forbids a second
     * axis-less variant on the same product.
     */
    public const string NO_AXES_KEY = '-default-';

    protected $table = 'product_variants';

    protected static function newFactory(): ProductVariantFactory
    {
        return ProductVariantFactory::new();
    }

    protected $fillable = [
        'product_id',
        'sku',
        'barcode',
        'combination_key',
        'is_default',
        'position',
    ];

    /**
     * The canonical key for a set of attribute-value ids.
     *
     * THE single definition — the generator, the upsert action, the tests and
     * any future importer all call this rather than re-deriving the format. It
     * sorts, so member order never produces a "different" variant, and it
     * de-duplicates, so a caller passing the same value twice cannot smuggle a
     * near-duplicate past the UNIQUE index.
     *
     * @param array<int, int> $attributeValueIds
     */
    public static function combinationKeyFor(array $attributeValueIds): string
    {
        $ids = array_values(array_unique(array_map('intval', $attributeValueIds)));

        if ($ids === []) {
            return self::NO_AXES_KEY;
        }

        sort($ids);

        return self::KEY_SEPARATOR.implode(self::KEY_SEPARATOR, $ids).self::KEY_SEPARATOR;
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The variant-defining values that distinguish this variant (§2.5).
     *
     * @return BelongsToMany<AttributeValue, $this>
     */
    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'variant_attribute_value')
            ->withPivot('attribute_id')
            ->withTimestamps();
    }

    /**
     * Whether this variant has no axes at all — the default variant of a
     * "simple" product.
     */
    public function hasNoAxes(): bool
    {
        return $this->combination_key === self::NO_AXES_KEY;
    }

    /**
     * A human label for the combination — "Kırmızı / M" — for the moderation
     * queue and the authoring table. Falls back to the SKU when the variant has
     * no axes, because an empty cell reads as a bug.
     *
     * Expects `attributeValues` to be loaded; the repositories declare it on
     * `$with` since strict mode makes a lazy load throw.
     */
    public function combinationLabel(string $separator = ' / '): string
    {
        if ($this->hasNoAxes()) {
            return $this->sku;
        }

        $parts = $this->attributeValues
            ->map(static fn (AttributeValue $value): string => $value->localized('label'))
            ->filter(static fn (string $label): bool => $label !== '')
            ->all();

        return $parts === [] ? $this->sku : implode($separator, $parts);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'position' => 'integer',
        ];
    }
}
