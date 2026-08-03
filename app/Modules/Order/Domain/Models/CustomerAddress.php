<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Modules\Localization\Domain\Models\Country;
use App\Shared\Traits\HasUuid;
use Database\Modules\Order\Factories\CustomerAddressFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The customer's address book (ADR-056, §2.2).
 *
 * MANY PER CUSTOMER, with separate defaults for SHIPPING and BILLING — because
 * "send it to the office, invoice my company" is the ordinary case, not an edge
 * one, and a single-address model forces a customer to retype one of them on
 * every order.
 *
 * IT IS NOT WHAT AN ORDER USES. An order SNAPSHOTS the two addresses it was
 * placed with (ADR-053/056) into its own columns, and never reads this book
 * again. That is the whole point of the separation: a customer who moves house
 * next year must not silently rewrite where last year's parcel was sent, which is
 * exactly what a foreign key from the order to this table would do.
 *
 * SO THIS TABLE IS EDITABLE AND SOFT-DELETABLE FREELY. Deleting an address
 * cannot orphan an order — the order already has its copy — which is what makes
 * the address book a comfortable place to keep things tidy rather than a
 * historical record nobody dares touch.
 *
 * AUDITABLE, unlike the cart: an address is where money and goods physically go,
 * and "who changed the delivery address, and when" is a fraud question. Cheap
 * here, because addresses change rarely.
 *
 * TURKISH SHAPE, deliberately: `district` (ilçe) and `neighborhood` (mahalle)
 * beside `city` (il), and a `country_id` into Localization's lookup because that
 * is the one exception every module reads (§5.1). The rest are plain strings —
 * validating world addresses structurally is a project of its own, and getting it
 * half right rejects real addresses.
 *
 * `neighborhood` IS A STRING, NOT A REFERENCE, even though Localization now holds
 * a full TR il/ilçe/mahalle dataset (ADR-056 amendment, 2026-08-03). The dataset
 * exists so a client can offer a dropdown; it does not get to decide whether an
 * address is valid. A mahalle is renamed, merged or created by administrative
 * act, and an address saved last year must not become invalid — or unreadable —
 * because the registry moved on. Every other country sends free text here or
 * nothing at all, which the same decision allows for free.
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_id
 * @property string $customer_uuid
 * @property string $label
 * @property string $recipient_name
 * @property string $phone
 * @property string $line1
 * @property string|null $line2
 * @property string|null $district
 * @property string|null $neighborhood
 * @property string $city
 * @property string|null $postal_code
 * @property int $country_id
 * @property bool $is_default_shipping
 * @property bool $is_default_billing
 * @property-read Country $country
 *
 * @see docs/modules/Order.md §2.2
 */
final class CustomerAddress extends Model
{
    /** @use HasFactory<CustomerAddressFactory> */
    use HasFactory;

    use Auditable;
    use HasUuid;
    use SoftDeletes;

    protected $table = 'customer_addresses';

    protected static function newFactory(): CustomerAddressFactory
    {
        return CustomerAddressFactory::new();
    }

    protected $fillable = [
        'customer_id',
        'customer_uuid',
        'label',
        'recipient_name',
        'phone',
        'line1',
        'line2',
        'district',
        'neighborhood',
        'city',
        'postal_code',
        'country_id',
        'is_default_shipping',
        'is_default_billing',
    ];

    /**
     * The one permitted relation: Localization is platform-wide reference data
     * every module may read (§5.1).
     *
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * The snapshot an order freezes (ADR-053/056).
     *
     * BUILT HERE, not in the checkout action, so the shipping copy and the
     * billing copy cannot end up different shapes — and so the shape is
     * documented in one place when a future Shipping module has to read it back.
     *
     * The COUNTRY IS DENORMALIZED TO ITS NAME AND CODE, not left as an id: this
     * array must still make sense years later if a country row is renamed or an
     * operator deactivates it. A snapshot containing a foreign key is not a
     * snapshot.
     *
     * @return array<string, string|null>
     */
    public function toSnapshot(): array
    {
        return [
            'uuid' => $this->uuid,
            'label' => $this->label,
            'recipient_name' => $this->recipient_name,
            'phone' => $this->phone,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'district' => $this->district,
            'neighborhood' => $this->neighborhood,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'country_code' => $this->country->iso2,
            'country_name' => $this->country->name,
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'country_id' => 'integer',
            'is_default_shipping' => 'boolean',
            'is_default_billing' => 'boolean',
        ];
    }
}
