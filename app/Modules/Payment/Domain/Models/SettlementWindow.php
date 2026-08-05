<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Shared\Traits\HasUuid;
use Database\Modules\Payment\Factories\SettlementWindowFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The two clocks a delivery starts (ADR-064, Shipping.md §4).
 *
 * SHIPPING SAYS THE PARCEL ARRIVED; THIS SAYS WHAT THAT ALLOWS. When the seller
 * may be paid, and how long the buyer may still send it back — the money side of
 * a fact that belongs to another module, which is why it is a Payment table keyed
 * on an order uuid rather than a column on a shipment.
 *
 * **BOTH DATES ARE FROZEN AT DELIVERY.** They are computed once from
 * `settings('shipping.*')` and stored, because deriving them on read would let an
 * operator shortening the hold make last week's deliveries suddenly payable, and
 * one lengthening it withdraw a payout a seller was already promised. Same
 * discipline as an order line's price: the rule that applied when the event
 * happened governs it.
 *
 * IT IMPORTS NO MODULE. The order, the seller and the delivery provenance all
 * arrive as scalars on a class-string event.
 *
 * @property int $id
 * @property string $uuid
 * @property string $order_uuid
 * @property string $seller_org_uuid
 * @property Carbon $delivered_at
 * @property string $delivered_via
 * @property Carbon $payout_eligible_at
 * @property Carbon $return_window_ends_at
 *
 * @see docs/modules/Payment.md §8
 */
final class SettlementWindow extends Model
{
    use Auditable;

    /** @use HasFactory<SettlementWindowFactory> */
    use HasFactory;

    use HasUuid;

    protected $table = 'settlement_windows';

    protected $fillable = [
        'order_uuid',
        'seller_org_uuid',
        'delivered_at',
        'delivered_via',
        'payout_eligible_at',
        'return_window_ends_at',
    ];

    /**
     * Whether this order's money may be paid out yet.
     *
     * ASKED IN ONE PLACE, because the payout ceiling, the admin screen and the
     * eligibility report must all agree — a seller told "12.000 available" who is
     * then refused 12.000 has been lied to by whichever of the three was wrong.
     */
    public function isPayoutEligible(): bool
    {
        return $this->payout_eligible_at->isPast();
    }

    /**
     * Whether the buyer may still send it back (S4's guard).
     */
    public function isReturnOpen(): bool
    {
        return $this->return_window_ends_at->isFuture();
    }

    /**
     * Orders whose hold has expired — what a payout may actually draw on.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopePayable(Builder $query): Builder
    {
        return $query->where('payout_eligible_at', '<=', now());
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

    protected static function newFactory(): SettlementWindowFactory
    {
        return SettlementWindowFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'payout_eligible_at' => 'datetime',
            'return_window_ends_at' => 'datetime',
        ];
    }
}
