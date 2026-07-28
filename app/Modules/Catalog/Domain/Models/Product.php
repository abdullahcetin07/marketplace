<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Modules\Catalog\Domain\Concerns\HasLocalizedText;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
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
 * @property string $title_tr
 * @property string|null $title_en
 * @property string $slug
 * @property string|null $description_tr
 * @property string|null $description_en
 * @property string|null $gtin
 * @property ProductStatus $status
 * @property int|null $proposed_by_org_id
 * @property string|null $proposed_by_org_uuid
 * @property int|null $proposed_by_user_id
 * @property int|null $moderated_by
 * @property string|null $moderation_reason
 * @property Carbon|null $submitted_at
 * @property Carbon|null $moderated_at
 * @property Carbon|null $published_at
 * @property-read Category $category
 * @property-read Brand|null $brand
 * @property-read Collection<int, ProductVariant> $variants
 *
 * @see docs/modules/Catalog.md §2.4
 */
final class Product extends Model implements HasMediaContract
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use Auditable;
    use HasLocalizedText;
    use HasMedia;
    use HasUuid;
    use SoftDeletes;

    protected $table = 'products';

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    protected $fillable = [
        'category_id',
        'brand_id',
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
     * @return BelongsToMany<Attribute, $this>
     */
    public function attributes(): BelongsToMany
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
     * @param  array<int, int>  $organizationIds
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
     * @param  array<int, int>  $organizationIds
     */
    public function isEditableBy(array $organizationIds): bool
    {
        return $this->status->isSellerEditable() && $this->isProposedByAny($organizationIds);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWhereStatus(Builder $query, ProductStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * @param  Builder<self>  $query
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
     * @param  Builder<self>  $query
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
     * @param  Builder<self>  $query
     * @param  array<int, int>  $organizationIds
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
     * @param  Builder<self>  $query
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'submitted_at' => 'datetime',
            'moderated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
