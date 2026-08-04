<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Models;

use App\Shared\Traits\HasUuid;
use Database\Modules\Order\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a basket: an offer, and how many of it (§2.1).
 *
 * WHAT IT STORES AND WHY THERE IS SO MUCH OF IT. The line's only real content is
 * `offer_uuid` + `quantity`. The other four uuids — variant, product, selling org,
 * store — are DENORMALIZED COPIES, and each earns its place by being needed to
 * GROUP or FILTER without asking another module:
 *
 *   - `selling_org_uuid` is what checkout partitions on (ADR-052). Reading it per
 *     line through the Offer contract would mean N queries to answer "how many
 *     orders will this become", on every cart render.
 *   - `store_uuid` follows it because the order carries both.
 *   - `variant_uuid` is the key Inventory reserves against (ADR-049), so checkout
 *     needs it per line.
 *   - `product_uuid` is what a title and a KDV bracket are looked up by.
 *
 * They are a CACHE OF IDENTITY, not of value: uuids do not change, so these
 * cannot go stale the way a stored price would. That is exactly why the price is
 * not here — it moves, and identity does not.
 *
 * NO PRICE, NO TITLE, NO TAX. All three are read live for display and snapshotted
 * only at placement (ADR-053). A cart that remembered a price would be a promise
 * the platform never made.
 *
 * ONE LINE PER OFFER, enforced by a unique index: adding the same offer twice
 * increases the quantity instead, because two lines for one thing is a basket a
 * customer cannot reason about and a checkout that would reserve against the same
 * (org, variant) twice.
 *
 * @property int $id
 * @property string $uuid
 * @property int $cart_id
 * @property string $offer_uuid
 * @property string $variant_uuid
 * @property string $product_uuid
 * @property string $selling_org_uuid
 * @property string $store_uuid
 * @property int $quantity
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Cart $cart
 *
 * @see docs/modules/Order.md §2.1
 */
final class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    use HasUuid;

    protected $table = 'cart_items';

    protected $fillable = [
        'cart_id',
        'offer_uuid',
        'variant_uuid',
        'product_uuid',
        'selling_org_uuid',
        'store_uuid',
        'quantity',
    ];

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    protected static function newFactory(): CartItemFactory
    {
        return CartItemFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }
}
