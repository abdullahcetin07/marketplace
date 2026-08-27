<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Modules\Catalog\Domain\Concerns\HasLocalizedText;
use App\Modules\Catalog\Domain\Concerns\HasRegisteredSlug;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\SluggableType;
use App\Modules\Catalog\Domain\Support\TurkishFold;
use App\Shared\Traits\HasMedia;
use App\Shared\Traits\HasUuid;
use Database\Modules\Catalog\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia as HasMediaContract;

/**
 * The canonical, PLATFORM-OWNED description of a product (ADR-037).
 *
 * ONE ENTRY, MANY SELLERS. A seller never gets a copy of a product; they will
 * (in the Offer sprint) create an Offer referencing one of this product's
 * variants by uuid. In Phase 1 a seller may only *propose* a product, which is
 * why `proposed_by_org_uuid` is provenance and not ownership — once published,
 * this row belongs to the catalog, not to whoever authored it.
 *
 * **NO PRICE AND NO STOCK.** The single most important line in this module. It
 * is exactly what lets one product be sold by many sellers at different prices
 * without duplication. Price/condition are an Offer; on-hand quantity is
 * Inventory. If you are reaching for a `price` column here, you are in the
 * wrong module (§0.2).
 *
 * THE PRODUCT IS THE REQUEST (§5). Approving a product creates nothing new — it
 * just moves to `Published` — so the moderation state is this row's own
 * `status`, and there is no separate request entity to reconcile.
 *
 * AUDITABLE (ADR-027). A catalog entry is a curated shared asset that many
 * sellers depend on; who changed a title, a category or an attribute — and when
 * — is a question that will be asked.
 *
 * REFERENCES NO OTHER MODULE (ADR-040). The proposing company is carried as
 * `proposed_by_org_id` + `proposed_by_org_uuid` — the id+uuid pair ADR-033
 * mandates and `stores` already uses — with DELIBERATELY NO `organization()`
 * RELATION. The id is the seller panel's tenancy filter (the Core
 * `OrganizationAuthorizationContract` answers in internal ids); the uuid is the
 * identifier that leaves the application. Catalog imports neither Organization
 * nor Store, and the FK exists for integrity only.
 *
 * @property int $id
 * @property string $uuid
 * @property int $category_id
 * @property int|null $brand_id
 * @property int|null $tax_rate_id
 * @property string $title_tr
 * @property string|null $title_en
 * @property string $slug
 * @property string|null $description_tr
 * @property string|null $description_en
 * @property string|null $gtin
 * @property ProductStatus $status
 * @property bool $is_sellable
 * @property string|null $search_text
 * @property int|null $proposed_by_org_id
 * @property string|null $proposed_by_org_uuid
 * @property int|null $proposed_by_user_id
 * @property int|null $moderated_by
 * @property string|null $moderation_reason
 * @property Carbon|null $submitted_at
 * @property Carbon|null $moderated_at
 * @property Carbon|null $published_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Category $category
 * @property-read Brand|null $brand
 * @property-read TaxRate|null $taxRate
 * @property-read Collection<int, ProductVariant> $variants
 *
 * @see docs/modules/Catalog.md §2.4
 */
final class Product extends Model implements HasMediaContract
{
    use Auditable;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use HasLocalizedText;
    use HasMedia;
    use HasRegisteredSlug;
    use HasUuid;
    use Searchable;
    use SoftDeletes;

    protected $table = 'products';

    protected $fillable = [
        'category_id',
        'brand_id',
        'tax_rate_id',
        'title_tr',
        'title_en',
        'slug',
        'description_tr',
        'description_en',
        'gtin',
        'status',
        'proposed_by_org_id',
        'proposed_by_org_uuid',
        'proposed_by_user_id',
        'moderated_by',
        'moderation_reason',
        'submitted_at',
        'moderated_at',
        'published_at',
    ];

    /**
     * @return array<int, string>
     */
    public static function localizedAttributes(): array
    {
        return ['title', 'description'];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * The brand's name without touching the relation. @see booted()
     */
    public function brandName(): ?string
    {
        if ($this->relationLoaded('brand')) {
            return $this->brand?->name;
        }

        return $this->brand_id === null
            ? null
            : Brand::query()->whereKey($this->brand_id)->value('name');
    }

    /**
     * The product's KDV bracket (ADR-056, §2.4).
     *
     * A CLASSIFICATION, NOT A PRICE. A book is %1 whoever sells it and whatever
     * they charge — which is the test that keeps this relation on the right side
     * of ADR-037's "a product has no price and no stock". Order reads the rate
     * through `CatalogQueryContract` and freezes it onto the line (ADR-053).
     *
     * Nullable in the schema for a deploy reason, not because a product without a
     * bracket is acceptable: the authoring form requires one and `isTaxable()` is
     * what the submission check asks.
     *
     * @return BelongsTo<TaxRate, $this>
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /**
     * Whether this product can produce a lawful order line.
     *
     * Asked at SUBMISSION rather than trusted from the form: the form is one way
     * in, and a product that reached `PendingReview` without a bracket would be
     * approved into a catalog where checkout cannot compute its KDV.
     */
    public function isTaxable(): bool
    {
        return $this->tax_rate_id !== null;
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }

    /**
     * The single variant of a product with no variant axes (ADR-039) — modelled
     * as a real variant flagged `is_default`, never as a special case.
     *
     * @return HasOne<ProductVariant, $this>
     */
    public function defaultVariant(): HasOne
    {
        return $this->hasOne(ProductVariant::class)->where('is_default', true);
    }

    /**
     * The product's DESCRIPTIVE attribute values (§2.4) — the ones that describe
     * the product as a whole. Variant-defining values live on the variant, not
     * here, which is what keeps "this product is 100% cotton" and "this variant
     * is size M" from being stored the same way.
     *
     * The pivot carries `attribute_value_id` for `Select` types and a raw
     * `value` string for Text/Number/Boolean — one row per attribute either way.
     *
     * NOT NAMED `attributes()`. Eloquent already has a `$attributes` property
     * for a model's raw column values, so a relation of that name works when
     * called as a method and SILENTLY returns the wrong thing when read as a
     * property — `$product->attributes` would hand back the column array. The
     * relation is renamed rather than the collision documented, because a trap
     * that only springs on one of two identical-looking syntaxes will be walked
     * into again.
     *
     * @return BelongsToMany<Attribute, $this>
     */
    public function descriptiveAttributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'product_attribute_value')
            ->withPivot(['attribute_value_id', 'value'])
            ->withTimestamps();
    }

    /**
     * The same pivot read from the value side, for rendering selected options
     * without a second lookup per row.
     *
     * @return BelongsToMany<AttributeValue, $this>
     */
    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(
            AttributeValue::class,
            'product_attribute_value',
            'product_id',
            'attribute_value_id',
        )->withPivot(['attribute_id', 'value'])->withTimestamps();
    }

    /**
     * Whether this proposal belongs to one of the given organizations — the
     * seller scoping wall (ADR-040).
     *
     * BY ID, taking the list the Core `OrganizationAuthorizationContract`
     * returns verbatim: a seller may belong to several companies (ADR-030), and
     * a scalar comparison would silently be wrong for all but the first.
     * Comparison rather than a membership join, because Catalog holds no
     * Organization relation.
     *
     * A product with no proposer (authored by staff) belongs to no seller and is
     * never seller-editable — which is why a null `proposed_by_org_id` matches
     * nothing rather than matching an empty list.
     *
     * @param array<int, int> $organizationIds
     */
    public function isProposedByAny(array $organizationIds): bool
    {
        return $this->proposed_by_org_id !== null
            && in_array((int) $this->proposed_by_org_id, $organizationIds, true);
    }

    /**
     * The uuid-side answer, for callers holding the public identifier — an event
     * payload or a downstream module reading through the Core contract.
     */
    public function isProposedBy(?string $organizationUuid): bool
    {
        return $this->proposed_by_org_uuid !== null
            && $organizationUuid !== null
            && $this->proposed_by_org_uuid === $organizationUuid;
    }

    /**
     * Whether the proposing seller may still edit the content. Status AND
     * provenance both have to hold — a published product is nobody's to edit
     * from the seller panel, however it got there.
     *
     * @param array<int, int> $organizationIds
     */
    public function isEditableBy(array $organizationIds): bool
    {
        return $this->status->isSellerEditable() && $this->isProposedByAny($organizationIds);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeWhereStatus(Builder $query, ProductStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::Published->value);
    }

    /**
     * The moderation queue's membership rule, in one place so the Filament
     * resource and the tests cannot drift apart.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeAwaitingModeration(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::PendingReview->value)
            ->orderBy('submitted_at');
    }

    /**
     * The seller panel's tenancy filter (ADR-030/040).
     *
     * THE EMPTY LIST MATCHES NOTHING, deliberately. `whereIn('x', [])` already
     * yields no rows, but stating it makes the intent unmissable: a seller who
     * belongs to no organization sees an empty table, never everyone's catalog.
     * That is the failure direction this scope exists to fix in one place rather
     * than in every resource that queries products.
     *
     * @param Builder<self> $query
     * @param array<int, int> $organizationIds
     *
     * @return Builder<self>
     */
    public function scopeProposedByAny(Builder $query, array $organizationIds): Builder
    {
        if ($organizationIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('proposed_by_org_id', $organizationIds);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeProposedBy(Builder $query, ?string $organizationUuid): Builder
    {
        // A null proposer must never match everything — an unscoped seller
        // query is the failure mode this guard exists to make impossible.
        return $organizationUuid === null
            ? $query->whereRaw('1 = 0')
            : $query->where('proposed_by_org_uuid', $organizationUuid);
    }

    /*
    |--------------------------------------------------------------------------
    | Search (§10)
    |--------------------------------------------------------------------------
    |
    | Products are indexed on `ProductPublished` and dropped on `ProductArchived`
    | (the listeners), and `shouldBeSearchable()` is the backstop under both: any
    | other save of a non-published product can never sneak a draft into the
    | index.
    |
    | PHASE 1 EXPOSES CATALOG SEARCH TO STAFF AND SELLERS ONLY. Buyer-facing
    | search waits on Offer — you cannot usefully search to buy something that
    | has no price and no seller. Indexing the attribute and variant facets NOW
    | means the filters are ready when Offer ships, which is cheaper than
    | reindexing a populated catalog later.
    */

    /**
     * Only published products belong in the index (§10).
     */
    public function shouldBeSearchable(): bool
    {
        return $this->status->isSearchable();
    }

    public function searchableAs(): string
    {
        return 'products';
    }

    /**
     * The document is keyed by UUID, not by the internal id.
     *
     * Scout otherwise stamps the primary key onto every document and would
     * overwrite the `id` below with the integer — putting an internal
     * identifier into an external system for no gain (non-negotiable #7). With
     * these two overrides the engine speaks the same identifier the rest of the
     * platform does, and `whereIn('uuid', …)` maps a result set back with no
     * translation step.
     */
    public function getScoutKey(): string
    {
        return $this->uuid;
    }

    public function getScoutKeyName(): string
    {
        return 'uuid';
    }

    /**
     * The document.
     *
     * `id` is the UUID, never the internal key — the search index is read by
     * clients and an internal id must not leave the application
     * (non-negotiable #7).
     *
     * NO PRICE AND NO STOCK FIELD (ADR-037). When Offer ships, price-sorted
     * search reads Offer's index, not this one.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->localized('title'),
            'title_en' => $this->title_en,
            'description' => $this->localized('description'),
            'brand' => $this->brand?->name,
            'category' => $this->category->localized('name'),
            // The path makes "everything under Giyim" a prefix filter on the
            // index, the same shape the database uses (§13.1).
            'category_path' => $this->category->path,
            'gtin' => $this->gtin,
            'status' => $this->status->value,
            /*
            | CATALOG'S OWN BUYABILITY FLAG (ADR-079), and the only reason the
            | ranking can lift products somebody can actually buy: it is derived
            | inside this module from events, so no Offer or Inventory field
            | crosses the boundary into the index (ADR-037/090).
            */
            'is_sellable' => (bool) $this->is_sellable,
            // Facets, ready for Offer (§10).
            'attributes' => $this->searchableAttributeValues(),
            'skus' => $this->variants->pluck('sku')->values()->all(),
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }

    /**
     * Fields `multi_match` targets, with relevance boosts.
     *
     * Title outranks everything: a shopper typing "tişört" means the product,
     * not a description that mentions one. Brand and category rank above free
     * text because they are curated and a match there is a stronger signal.
     *
     * @return array<int, string>
     */
    public function searchableFields(): array
    {
        return ['title^5', 'brand^3', 'category^2', 'skus^2', 'gtin', 'description'];
    }

    /**
     * Explicit mapping — the index is created with this on first write.
     *
     * Without it every field gets dynamic mapping, which produces unpredictable
     * types and, for Turkish, visibly wrong results: `İSTANBUL` would not match
     * `istanbul` and `ürün` would not match `urun` (docs/search.md).
     *
     * The `keyword` fields are filters, not text: a status or a SKU is matched
     * exactly or not at all, and analysing them would be actively harmful.
     *
     * @return array<string, array<string, mixed>>
     */
    public function searchableMapping(): array
    {
        return [
            'id' => ['type' => 'keyword'],
            'title' => ['type' => 'text', 'analyzer' => 'turkish_analyzer'],
            'title_en' => ['type' => 'text'],
            'description' => ['type' => 'text', 'analyzer' => 'turkish_analyzer'],
            'brand' => ['type' => 'text', 'analyzer' => 'turkish_analyzer',
                'fields' => ['raw' => ['type' => 'keyword']]],
            'category' => ['type' => 'text', 'analyzer' => 'turkish_analyzer',
                'fields' => ['raw' => ['type' => 'keyword']]],
            'category_path' => ['type' => 'keyword'],
            'gtin' => ['type' => 'keyword'],
            'status' => ['type' => 'keyword'],
            'attributes' => ['type' => 'keyword'],
            'skus' => ['type' => 'keyword'],
            'published_at' => ['type' => 'date'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function searchRelations(): array
    {
        return [
            'category',
            'brand',
            'descriptiveAttributes',
            'descriptiveAttributes.values',
            'variants',
            'variants.attributeValues',
            'variants.attributeValues.attribute',
        ];
    }

    /**
     * This entity's kind in the global slug namespace (ADR-059).
     */
    public function sluggableType(): SluggableType
    {
        return SluggableType::Product;
    }

    /**
     * Keep the folded search haystack current on every write.
     *
     * On `saving`, so the value goes out in the same statement as the change
     * that caused it — a second UPDATE would double every product write and
     * fire Scout twice.
     *
     * **The brand name is fetched with a VALUE QUERY, not the relation.** Strict
     * mode throws on lazy loading, and a product saved from an importer, an
     * action or a console command has no eager loads; asking `$this->brand`
     * would be a crash on the paths that matter most. When the relation IS
     * already loaded the loaded instance is used, so nothing is queried twice.
     *
     * **THE CATEGORY NAME IS DELIBERATELY NOT IN HERE.** It was, and it made a
     * leather jacket filed under "Tişört" answer a search for `tişört` — every
     * product in an aisle matching the aisle's name, which reads as a bug to
     * whoever typed it. Both browses already have a precise category FILTER;
     * this is the free-text box, and it should answer for what the product IS.
     *
     * A brand RENAME leaves every product that mentions it stale here. That is what `catalog:refresh-search-text` is for, the same way
     * `catalog:refresh-sellability` repairs its column (ADR-079) — a derived
     * value is allowed to drift as long as something rebuilds it.
     */
    protected static function booted(): void
    {
        self::saving(static function (self $product): void {
            $product->search_text = TurkishFold::haystack([
                $product->title_tr,
                $product->title_en,
                $product->brandName(),
                $product->description_tr,
                $product->description_en,
            ]);
        });
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    /**
     * Everything `toSearchableArray()` reads, so a full reindex never trips
     * strict mode's lazy-load guard — Scout loads models in chunks of its own
     * and does not go through the repositories.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with($this->searchRelations());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            /*
            | A BOOLEAN, NOT THE DATABASE'S 1/0 (ADR-079). The browse filters on
            | it in SQL where the cast never runs, but a listener and a test read
            | it as a value — and `1 === true` is false in PHP, which is the shape
            | of bug that passes review and fails at runtime.
            */
            'is_sellable' => 'boolean',
            'submitted_at' => 'datetime',
            'moderated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Attribute values as flat `code:value` keywords — the shape a facet filter
     * queries (`attributes: "renk:kirmizi"`), from both the product's own
     * descriptive values and its variants' axes.
     *
     * @return array<int, string>
     */
    private function searchableAttributeValues(): array
    {
        $facets = [];

        foreach ($this->descriptiveAttributes as $attribute) {
            $pivot = $attribute->getRelation('pivot');
            $valueId = $pivot->getAttribute('attribute_value_id');

            $value = $valueId !== null
                ? $attribute->values->firstWhere('id', (int) $valueId)?->value
                : $pivot->getAttribute('value');

            if (is_string($value) && $value !== '') {
                $facets[] = $attribute->code.':'.$value;
            }
        }

        foreach ($this->variants as $variant) {
            foreach ($variant->attributeValues as $value) {
                $facets[] = $value->attribute->code.':'.$value->value;
            }
        }

        return array_values(array_unique($facets));
    }
}
