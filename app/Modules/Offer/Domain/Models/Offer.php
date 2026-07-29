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
    /** @use HasFactory<OfferFactory> */
    use HasFactory;

    use Auditable;
    use HasUuid;
    use SoftDeletes;

    protected $table = 'offers';

    /**
     * Factories live under `database/Modules/Offer/Factories`, not the default
     * `database/factories`, so the model names its own — the discovery path
     * documented in `database/Modules/README.md`.
     */
    protected static function newFactory(): OfferFactory
    {
        return OfferFactory::new();
    }

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
     * @param  Builder<self>  $query
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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query
            ->where('status', OfferStatus::Active->value)
            ->where('stock_quantity', '>', 0);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForProduct(Builder $query, string $productUuid): Builder
    {
        return $query->where('product_uuid', $productUuid);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForVariant(Builder $query, string $variantUuid): Builder
    {
        return $query->where('variant_uuid', $variantUuid);
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
