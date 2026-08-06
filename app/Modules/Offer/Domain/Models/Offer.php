<?php

declare(strict_types=1);

namespace App\Modules\Offer\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Shared\Traits\HasUuid;
use Database\Modules\Offer\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

/**
 * One seller organization's price and stock for one catalog variant (ADR-042).
 *
 * THE SELLER↔PRODUCT LINK THE CATALOG DELIBERATELY DOES NOT OWN (ADR-037). The
 * seller never copies the product; they attach this, which references the shared
 * `ProductVariant` by uuid. Many offers may target the same variant — that is
 * the entire point of a shared catalog, and it is why the product carries no
 * price.
 *
 * IT IMPORTS NO OTHER MODULE. Every cross-context reference is the ADR-040 pair
 * — an internal id where a tenancy filter needs one, a uuid for identity — and
 * there is deliberately NO `variant()`, `product()`, `organization()` or
 * `store()` relation to reach through. Validation of those references happens in
 * the Application layer against the Core contracts, never by a relation on this
 * model. The one relation that exists is `currency()`, and Localization is the
 * platform-wide exception every module shares.
 *
 * MONEY IS AN INTEGER OF MINOR UNITS (non-negotiable #6). `price_minor` is what
 * the buyer pays, KDV included; `list_price_minor` is the optional struck-through
 * comparison price and must be ≥ it. Neither ever leaves the application as an
 * integer — the API renders a decimal string.
 *
 * AUDITABLE (ADR-027). A price change is exactly the kind of fact a dispute
 * turns on: what was it yesterday, who changed it, when.
 *
 * OUT OF STOCK IS NOT A STATUS. It is `stock_quantity = 0`, derived on read
 * (ADR-043/045). @see OfferStatus
 *
 * @property int $id
 * @property string $uuid
 * @property string $variant_uuid
 * @property string $product_uuid
 * @property int $selling_org_id
 * @property string $selling_org_uuid
 * @property string $store_uuid
 * @property int $price_minor
 * @property int|null $list_price_minor
 * @property int $currency_id
 * @property int $stock_quantity
 * @property OfferStatus $status
 * @property OfferStatus|null $status_before_suspension
 * @property bool $paused_by_cascade
 * @property \Illuminate\Support\Carbon|null $suspended_at
 * @property int|null $suspended_by
 * @property string|null $suspension_reason
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Currency $currency
 *
 * @see docs/modules/Offer.md §2.1
 */
final class Offer extends Model
{
    use Auditable;

    /** @use HasFactory<OfferFactory> */
    use HasFactory;

    use HasUuid;
    use Searchable;
    use SoftDeletes;

    protected $table = 'offers';

    protected $fillable = [
        'variant_uuid',
        'product_uuid',
        'selling_org_id',
        'selling_org_uuid',
        'store_uuid',
        'price_minor',
        'list_price_minor',
        'currency_id',
        'stock_quantity',
        'status',
        'status_before_suspension',
        'paused_by_cascade',
        'suspended_at',
        'suspended_by',
        'suspension_reason',
    ];

    /**
     * The one permitted relation: money renders against a real currency, and
     * Localization is the platform-wide reference data every module reads.
     *
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Derived, never stored (ADR-043).
     */
    public function isInStock(): bool
    {
        return $this->stock_quantity > 0;
    }

    /**
     * Whether this offer may compete for the buy box (§5).
     *
     * The STATUS and STOCK halves only. The third condition — the selling org's
     * store must be Active — is a cross-context question answered through
     * `StoreQueryContract` in the query layer, because a Domain model may not
     * reach into another context to find out (ADR-033). A caller that needs the
     * full rule uses the repository's eligible scope, not this method.
     */
    public function isBuyBoxEligible(): bool
    {
        return $this->status->isBuyBoxEligible() && $this->isInStock();
    }

    /**
     * The discount the list price advertises, as whole percent, or null when
     * there is nothing to advertise.
     *
     * Guarded on `> price_minor` rather than `>=`: a list price equal to the
     * price is not a discount, and rendering "0% off" is worse than rendering
     * nothing.
     */
    public function discountPercent(): ?int
    {
        if ($this->list_price_minor === null || $this->list_price_minor <= $this->price_minor) {
            return null;
        }

        return (int) floor(
            ($this->list_price_minor - $this->price_minor) * 100 / $this->list_price_minor,
        );
    }

    /**
     * Offers that count toward the one-active-offer-per-(org, variant) rule
     * (§3.2) — everything except withdrawn.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeBlockingDuplicate(Builder $query): Builder
    {
        return $query->whereIn('status', array_column(OfferStatus::blockingDuplicates(), 'value'));
    }

    /**
     * The status+stock half of buy-box eligibility, as a query. The store-active
     * half is applied by the query layer, which is allowed to ask Store.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query
            ->where('status', OfferStatus::Active->value)
            ->where('stock_quantity', '>', 0);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeForProduct(Builder $query, string $productUuid): Builder
    {
        return $query->where('product_uuid', $productUuid);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeForVariant(Builder $query, string $variantUuid): Builder
    {
        return $query->where('variant_uuid', $variantUuid);
    }

    /*
    |--------------------------------------------------------------------------
    | Search (§10)
    |--------------------------------------------------------------------------
    |
    | THIS IS THE INDEX THAT MAKES BUYER SEARCH POSSIBLE. Catalog deferred it —
    | searching to buy something with no price and no seller makes no sense — and
    | said explicitly that when Offer ships, "price-sorted search reads Offer's
    | index, not this one" (Product::toSearchableArray()). This is that index.
    |
    | IT CARRIES NO CATALOG TEXT. No title, no brand, no description: those live
    | in Catalog's index, which owns them and keeps them current. Copying them
    | here would be the ADR-037 stale-copy problem wearing a different hat — a
    | renamed product would disagree with every offer document until something
    | happened to touch it. A buyer query matches text against Catalog's index
    | and price/availability against this one, joined on `product_uuid`.
    |
    | So what this index is FOR is the commercial half: which products can
    | actually be bought right now, from whom, and for how much.
    */

    /**
     * Only offers a buyer could actually order (§10).
     *
     * DELIBERATELY ONLY THIS MODULE'S OWN FACTS. Buy-box eligibility has a third
     * condition — the seller's store must be live — and it is NOT asked here:
     * this method runs once per record during a bulk reindex, so a cross-context
     * lookup would turn one import into one query per offer. The store's state
     * reaches the index through its lifecycle events instead
     * (`SyncOfferSearchIndex`), and `store_uuid` is in the document so a query
     * can exclude a dark store directly.
     *
     * The residual, stated rather than hidden: a full `scout:import` will index
     * offers belonging to a non-live store until that store next changes state.
     * The read path is authoritative — `OfferQueryContract` re-checks liveness —
     * so this can surface a result that then resolves to nothing, never a
     * purchasable offer from a closed shop.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->status->isBuyBoxEligible() && $this->isInStock();
    }

    public function searchableAs(): string
    {
        return 'offers';
    }

    /**
     * The document.
     *
     * `id` is the UUID, never the internal key — the index is read by clients
     * and an internal id must not leave the application (non-negotiable #7).
     * `price_minor` IS here, as an integer, because this is where sorting and
     * range filtering happen; it becomes a decimal string at the resource
     * boundary and nowhere else (005 §28).
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->uuid,
            // The join keys back to Catalog's index, and the buy-box grouping.
            'product_uuid' => $this->product_uuid,
            'variant_uuid' => $this->variant_uuid,
            // The seller-facing facets a buyer filters on ("bu satıcıdan").
            'selling_org_uuid' => $this->selling_org_uuid,
            'store_uuid' => $this->store_uuid,
            'price_minor' => $this->price_minor,
            'currency_id' => $this->currency_id,
            // The in-stock FILTER (§10). A boolean, not the count: the count is
            // competitive information and a facet nobody asked for.
            'in_stock' => $this->isInStock(),
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Explicit mapping — the index is created with this on first write.
     *
     * Every field is a `keyword` or a number, because not one of them is text:
     * a uuid, a status and a price are matched exactly, sorted or ranged, and
     * analysing them would be actively harmful. That is the whole shape of this
     * index — Catalog's holds the language, this one holds the commerce.
     *
     * @return array<string, array<string, mixed>>
     */
    public function searchableMapping(): array
    {
        return [
            'id' => ['type' => 'keyword'],
            'product_uuid' => ['type' => 'keyword'],
            'variant_uuid' => ['type' => 'keyword'],
            'selling_org_uuid' => ['type' => 'keyword'],
            'store_uuid' => ['type' => 'keyword'],
            'price_minor' => ['type' => 'long'],
            'currency_id' => ['type' => 'integer'],
            'in_stock' => ['type' => 'boolean'],
            'status' => ['type' => 'keyword'],
            'created_at' => ['type' => 'date'],
        ];
    }

    /**
     * Factories live under `database/Modules/Offer/Factories`, not the default
     * `database/factories`, so the model names its own — the discovery path
     * documented in `database/Modules/README.md`.
     */
    protected static function newFactory(): OfferFactory
    {
        return OfferFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'list_price_minor' => 'integer',
            'stock_quantity' => 'integer',
            'selling_org_id' => 'integer',
            'currency_id' => 'integer',
            'status' => OfferStatus::class,
            'status_before_suspension' => OfferStatus::class,
            'paused_by_cascade' => 'boolean',
            'suspended_at' => 'datetime',
        ];
    }
}
