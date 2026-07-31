<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Shared\Traits\HasUuid;
use Database\Modules\Order\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * ONE SELLER'S order, from ONE checkout (ADR-052, §2.3).
 *
 * THE MOST IMPORTANT THING ABOUT THIS AGGREGATE IS ITS GRAIN. A customer's single
 * purchase produces N of these — one per seller — tied together by
 * `checkout_group_uuid`. Each has its own number, its own status, its own totals
 * and its own seller, because each seller fulfils, ships and is paid
 * independently. The customer sees one purchase; the platform holds N orders.
 *
 * THE COST, STATED PLAINLY (ADR-052): there is no row anywhere holding "what the
 * customer paid". A receipt is a sum across the group, and a future Payment must
 * reconcile one charge against many orders. We accept that because the
 * alternative — one cross-seller order — entangles fulfilment and payout between
 * parties who share nothing, and every later module (shipping, returns,
 * commission) would have to un-entangle it per line.
 *
 * IT IS A FINANCIAL RECORD, SO IT IS WRITTEN ONCE. Totals are computed from the
 * lines at placement and never recomputed; the lines themselves are immutable
 * (ADR-053), and both addresses are SNAPSHOTS held as JSON on this row rather
 * than foreign keys into the address book — a customer who moves house must not
 * rewrite where last year's parcel went.
 *
 * MONEY IS INTEGER MINOR UNITS (non-negotiable #6), and prices are KDV-INCLUDED
 * (ADR-042/055). So `items_total_minor` already contains the tax,
 * `tax_total_minor` says how much of it is tax, and `grand_total_minor` equals
 * items total this sprint because there is no shipping and no discount to add.
 * They are three columns rather than one derived pair deliberately: an invoice
 * needs the breakdown to be the number that was agreed, not one recomputed later
 * from a rate that may since have changed.
 *
 * IT IMPORTS NO MODULE. The customer, the seller org and the store are all the
 * ADR-040 id/uuid pair or a bare uuid; there is no `customer()`, `organization()`
 * or `store()` relation to reach through. The one relation is `currency()`, the
 * platform-wide Localization exception.
 *
 * WHAT STOCK THIS ORDER IS HOLDING IS NOT A COLUMN. It is a reservation in
 * Inventory keyed per line off this row's uuid (ADR-054/057) — and since ADR-057
 * BOTH live states hold it: placement no longer commits, so `Pending` and
 * `AwaitingPayment` alike are holding units that a cancellation gives straight
 * back. The authority for the count is the module that owns counting.
 *
 * @property int $id
 * @property string $uuid
 * @property string $order_number
 * @property string $checkout_group_uuid
 * @property int $customer_id
 * @property string $customer_uuid
 * @property string $selling_org_uuid
 * @property string $store_uuid
 * @property OrderStatus $status
 * @property int $currency_id
 * @property int $items_total_minor
 * @property int $tax_total_minor
 * @property int $grand_total_minor
 * @property array<string, string|null> $shipping_address
 * @property array<string, string|null> $billing_address
 * @property Carbon|null $placed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancelled_by
 * @property string|null $cancellation_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Currency $currency
 * @property-read Collection<int, OrderLine> $lines
 *
 * @see docs/modules/Order.md §2.3
 */
final class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use Auditable;
    use HasUuid;

    protected $table = 'orders';

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }

    protected $fillable = [
        'order_number',
        'checkout_group_uuid',
        'customer_id',
        'customer_uuid',
        'selling_org_uuid',
        'store_uuid',
        'status',
        'currency_id',
        'items_total_minor',
        'tax_total_minor',
        'grand_total_minor',
        'shipping_address',
        'billing_address',
        'placed_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('id');
    }

    /**
     * The one permitted relation: money renders against a real currency, and
     * Localization is the platform-wide exception every module reads.
     *
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * THE RESERVATION REFERENCE (ADR-054) — one PER LINE, derived from this
     * order's uuid and the line's variant.
     *
     * WHY NOT SIMPLY THE ORDER UUID, which is how Order.md §3.1 writes it: an
     * Inventory reservation is one row keyed on a UNIQUE reference, and reserving
     * is idempotent on it. An order with two lines reserving under one reference
     * would silently no-op on the second — the first line held, the second not,
     * and nothing anywhere saying so. That is the worst failure this integration
     * can have, so the reference has to be per line.
     *
     * `{order_uuid}:{variant_uuid}` rather than the line's own uuid, deliberately:
     * it is DERIVABLE. Release and commit rebuild it from the order and its lines
     * without storing an Inventory identifier anywhere, which is the property the
     * contract's "the caller passes its own key" design is asking for. It is
     * unique because a seller may hold at most one active offer per variant
     * (ADR-042 §3.2), so one order cannot contain two lines for one variant.
     *
     * A NAMED METHOD rather than string concatenation at four call sites, because
     * it is a CONTRACT with another module: Inventory stores this string and every
     * later commit or release must produce the identical one. Reserve with one
     * value and release with another and the hold survives forever.
     */
    public function reservationReferenceFor(string $variantUuid): string
    {
        return $this->uuid.':'.$variantUuid;
    }

    /**
     * Whether this order is holding stock a cancellation must give back
     * (ADR-057 — both live states do).
     */
    public function holdsReservation(): bool
    {
        return $this->status->holdsReservation();
    }

    /**
     * Whether the CHECKOUT window has run out (§3.3, ADR-057).
     *
     * ONLY MEANINGFUL WHILE `Pending` — and since ADR-057 that is no longer the
     * same question as "is it holding stock". A placed order holds its reservation
     * too, and holds it until it is paid or cancelled, however long that takes:
     * expiring one would cancel a purchase the customer believes they have made.
     * So this asks `isAwaitingPlacement()`, not `holdsReservation()`.
     *
     * Asked of the order rather than computed in the sweep job, so the rule and
     * the surfaces that display "expires in…" agree.
     */
    public function reservationHasExpired(): bool
    {
        if (! $this->status->isAwaitingPlacement()) {
            return false;
        }

        return $this->created_at->addMinutes(
            (int) config('order.reservation.expires_after_minutes', 30),
        )->isPast();
    }

    /**
     * The sum of the lines — the invariant totals are written from (§3.5).
     *
     * Exposed so a test and the placement action ask the same question, and so
     * "totals equal the sum of the lines" is checkable rather than assumed.
     *
     * @return array{items: int, tax: int, grand: int}
     */
    public function computedTotals(): array
    {
        $items = (int) $this->lines->sum('line_total_minor');
        $tax = (int) $this->lines->sum('line_tax_minor');

        return [
            'items' => $items,
            'tax' => $tax,
            // No shipping and no discount this sprint (§3.4), so the grand total
            // IS the items total. Kept as a separate column because that stops
            // being true the moment Shipping ships, and a stored total is what an
            // invoice reproduces.
            'grand' => $items,
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForSellingOrg(Builder $query, string $organizationUuid): Builder
    {
        return $query->where('selling_org_uuid', $organizationUuid);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInCheckoutGroup(Builder $query, string $checkoutGroupUuid): Builder
    {
        return $query->where('checkout_group_uuid', $checkoutGroupUuid);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithStatus(Builder $query, OrderStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'customer_id' => 'integer',
            'currency_id' => 'integer',
            'items_total_minor' => 'integer',
            'tax_total_minor' => 'integer',
            'grand_total_minor' => 'integer',
            // Snapshots, not relations (ADR-053). JSON because the shape belongs
            // to the order and must survive the address book changing under it.
            'shipping_address' => 'array',
            'billing_address' => 'array',
            'placed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
