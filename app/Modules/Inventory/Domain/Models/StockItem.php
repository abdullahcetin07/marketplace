<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Shared\Traits\HasUuid;
use Database\Modules\Inventory\Factories\StockItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * How much of one variant one seller can actually sell (ADR-048).
 *
 * ONE POOL PER (selling org, variant) — no warehouse dimension (ADR-051). A
 * seller with real multiple warehouses cannot model them yet; there is no Order
 * or Shipping module for a location to feed, so building it now would be
 * untestable structure with no consumer.
 *
 * `available` IS COMPUTED, NEVER STORED. It is `on_hand − reserved`, and storing
 * it would be a third number to keep in step with two that already move
 * independently — the same reasoning that keeps the buy box computed (ADR-045).
 *
 * `on_hand` AND `reserved` ARE PROJECTIONS of the movement ledger (ADR-050),
 * written in the same transaction as the movement that changed them and
 * rebuildable from it. They exist because summing a growing ledger on every buy
 * box read is not a trade worth making; the ledger remains the source of truth.
 *
 * IT IMPORTS NO MODULE. The variant, the product and the selling organization
 * are uuids — plus the org's internal id, which the tenancy filter needs
 * (ADR-040) — and there is deliberately no relation to reach through. Validating
 * those references is the Application layer's job against the Core contracts.
 *
 * AUDITABLE (ADR-027). "The system says 0 and I never sold that many" is a
 * dispute, and the movement ledger plus this trail is what answers it.
 *
 * @property int $id
 * @property string $uuid
 * @property string $variant_uuid
 * @property string $product_uuid
 * @property string|null $offer_uuid
 * @property int $selling_org_id
 * @property string $selling_org_uuid
 * @property int $on_hand
 * @property int $reserved
 * @property int|null $low_stock_threshold
 * @property bool $low_stock_notified
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @see docs/modules/Inventory.md §2.1
 */
final class StockItem extends Model
{
    use Auditable;

    /** @use HasFactory<StockItemFactory> */
    use HasFactory;
    use HasUuid;

    protected $table = 'stock_items';

    protected $fillable = [
        'variant_uuid',
        'product_uuid',
        'offer_uuid',
        'selling_org_id',
        'selling_org_uuid',
        'on_hand',
        'reserved',
        'low_stock_threshold',
        'low_stock_notified',
    ];

    /**
     * The ledger this item's numbers are a projection of (ADR-050).
     *
     * The one relation that exists, because it is the one thing Inventory owns
     * on both ends.
     *
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * @return HasMany<StockReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    /**
     * WHAT CAN BE SOLD THIS INSTANT — the number this whole module exists to
     * answer.
     *
     * Clamped at zero rather than trusted: the invariants keep `reserved` from
     * exceeding `on_hand`, but a negative "available" rendered on a storefront
     * would be a worse bug than a wrong zero, and the clamp costs nothing.
     */
    public function available(): int
    {
        return max(0, $this->on_hand - $this->reserved);
    }

    public function isAvailable(int $quantity = 1): bool
    {
        return $quantity > 0 && $this->available() >= $quantity;
    }

    /**
     * Whether availability has fallen to the seller's warning line (§3.3).
     *
     * No threshold means no warning — silence is the correct answer for a seller
     * who never asked to be told. Zero is a legitimate threshold ("tell me when
     * I run out"), which is why this tests for null rather than for falsiness.
     */
    public function isLowStock(): bool
    {
        return $this->low_stock_threshold !== null
            && $this->available() <= $this->low_stock_threshold;
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

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeForSellingOrg(Builder $query, string $sellingOrgUuid): Builder
    {
        return $query->where('selling_org_uuid', $sellingOrgUuid);
    }

    /**
     * Factories live under `database/Modules/Inventory/Factories`, not the
     * default `database/factories`, so the model names its own.
     */
    protected static function newFactory(): StockItemFactory
    {
        return StockItemFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selling_org_id' => 'integer',
            'on_hand' => 'integer',
            'reserved' => 'integer',
            'low_stock_threshold' => 'integer',
            'low_stock_notified' => 'boolean',
        ];
    }
}
