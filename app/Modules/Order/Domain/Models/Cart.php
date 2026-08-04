<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Models;

use App\Shared\Traits\HasUuid;
use Database\Modules\Order\Factories\CartFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One customer's basket — and it is MULTI-SELLER (ADR-052, §2.1).
 *
 * THE DECISION THIS MODEL EMBODIES: a shopper does not think in sellers. They
 * add a phone case and a coffee grinder and expect one basket, so there is one
 * cart per customer holding items from any number of sellers. The split into
 * per-seller orders happens at CHECKOUT, not here.
 *
 * NO PRICES ARE STORED. Not one. A cart line holds an offer uuid and a quantity;
 * every amount shown is read LIVE from the Offer through the Core contract. That
 * is the difference between a cart and an order: a cart shows what things cost
 * *now* and must follow a seller's price change, while an order records what was
 * agreed and must never move again (ADR-053). Storing a price here would give a
 * customer a total that silently disagrees with the seller's listing — and the
 * disagreement would surface at the worst possible moment, on the payment screen.
 *
 * IT REFERENCES THE CUSTOMER BY THE ADR-040 PAIR. The id is the tenancy filter on
 * every request; the uuid is what leaves the application. There is deliberately no
 * `customer()` relation — Order imports no module, and Identity is frozen.
 *
 * NOT AUDITABLE, deliberately, unlike almost everything else in the platform. A
 * cart is scratch space a shopper churns dozens of times a session; auditing it
 * would bury the entries that matter (a price change, a suspension, a tax rate)
 * under "customer removed an item". What the customer actually agreed to is
 * recorded on the immutable order lines, which is where a dispute is settled.
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_id
 * @property string $customer_uuid
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Collection<int, CartItem> $items
 *
 * @see docs/modules/Order.md §2.1
 */
final class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    use HasUuid;

    protected $table = 'carts';

    protected $fillable = [
        'customer_id',
        'customer_uuid',
    ];

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }

    /**
     * The distinct selling organizations this cart will split into (ADR-052).
     *
     * THE SHAPE OF THE FUTURE CHECKOUT, answerable before any of it runs — which
     * is why it lives on the model rather than inside `CheckoutAction`: the cart
     * screen wants to tell a shopper "this will arrive as 2 separate orders", and
     * that sentence must come from the same rule that later produces them.
     *
     * @return array<int, string>
     */
    public function sellingOrganizationUuids(): array
    {
        return $this->items
            ->pluck('selling_org_uuid')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The cart's items grouped by seller — one group becomes one Order.
     *
     * @return array<string, Collection<int, CartItem>>
     */
    public function itemsBySellingOrganization(): array
    {
        return $this->items
            ->groupBy('selling_org_uuid')
            ->all();
    }

    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    protected static function newFactory(): CartFactory
    {
        return CartFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
        ];
    }
}
