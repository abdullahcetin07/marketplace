<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Modules\Shipping\Domain\Enums\DeliveredVia;
use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Shared\Traits\HasUuid;
use Database\Modules\Shipping\Factories\ShipmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One parcel, from the seller's hands to the buyer's door (ADR-063).
 *
 * ONE PER ORDER, and the uniqueness lives in the database rather than in this
 * class: the row is created by a listener on a payment event that arrives many
 * times, so the index is what makes creation idempotent under a retry the
 * application never sees twice in one process.
 *
 * IT IMPORTS NO MODULE. The order and the seller are uuids; `order_number` is a
 * snapshot of a value that never changes (ADR-053), carried so a shipment list
 * does not have to ask Order for a label per row. The only relation is
 * `cargoCompany()`, which is this module's own.
 *
 * **THE SELLER CANNOT DELIVER IT** (ADR-064) — the one rule that keeps payout
 * honest, since the seller is paid on delivery. That is enforced in the action and
 * the policy rather than here: this model is where the FACT lives, and
 * `delivered_via` is the column that records which of the two honest paths set it.
 * S1 writes neither; it ships the vocabulary the S2 inference will use.
 *
 * AUDITABLE (ADR-027): every state transition, because "when did this become
 * delivered and who moved it" is precisely the question a payout dispute asks.
 *
 * NO MONEY. There is no amount on this class and the minor-units rule does not
 * apply to it — v1 charges no shipping fee (ADR-063).
 *
 * @property int $id
 * @property string $uuid
 * @property string $order_uuid
 * @property string $seller_org_uuid
 * @property string $order_number
 * @property ShipmentStatus $status
 * @property int|null $cargo_company_id
 * @property string|null $tracking_number
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property DeliveredVia|null $delivered_via
 * @property Carbon|null $returned_at
 * @property-read CargoCompany|null $cargoCompany
 *
 * @see docs/modules/Shipping.md §2
 */
final class Shipment extends Model
{
    use Auditable;

    /** @use HasFactory<ShipmentFactory> */
    use HasFactory;

    use HasUuid;

    protected $table = 'shipments';

    protected $fillable = [
        'order_uuid',
        'seller_org_uuid',
        'order_number',
        'status',
        'cargo_company_id',
        'tracking_number',
        'shipped_at',
        'delivered_at',
        'delivered_via',
        'returned_at',
    ];

    /**
     * The one relation: this module's own lookup table.
     *
     * @return BelongsTo<CargoCompany, $this>
     */
    public function cargoCompany(): BelongsTo
    {
        return $this->belongsTo(CargoCompany::class);
    }

    /**
     * Where the buyer goes to see where their parcel is — or null.
     *
     * Asked of the carrier rather than built here, so the substitution has one
     * home. Null when the carrier publishes no tracking page or nothing has been
     * shipped yet; both are ordinary answers.
     */
    public function trackingUrl(): ?string
    {
        return $this->cargoCompany?->trackingUrlFor($this->tracking_number);
    }

    /**
     * Whether the seller may still hand this parcel over.
     *
     * One question, one place — the panel, the action and the policy must agree
     * about it or a seller sees a button that then refuses them.
     */
    public function awaitsHandover(): bool
    {
        return $this->status->isAwaitingHandover();
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeForSeller(Builder $query, string $sellerOrgUuid): Builder
    {
        return $query->where('seller_org_uuid', $sellerOrgUuid);
    }

    /**
     * The seller-panel tenancy wall.
     *
     * AN EMPTY LIST YIELDS NOTHING, never everyone's parcels — the same shape the
     * repositories' `forOrganizations()` keeps, and for the same reason: a scope
     * that degrades to "all" when it resolves nothing is a cross-tenant leak
     * waiting for one bad membership lookup.
     *
     * @param Builder<self> $query
     * @param array<int, string> $sellerOrgUuids
     *
     * @return Builder<self>
     */
    public function scopeForSellers(Builder $query, array $sellerOrgUuids): Builder
    {
        return $query->whereIn('seller_org_uuid', $sellerOrgUuids);
    }

    protected static function newFactory(): ShipmentFactory
    {
        return ShipmentFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'cargo_company_id' => 'integer',
            'delivered_via' => DeliveredVia::class,
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }
}
