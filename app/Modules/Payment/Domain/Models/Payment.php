<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Shared\Traits\HasUuid;
use Database\Modules\Payment\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The money for ONE checkout group (ADR-060, Payment.md §4).
 *
 * THE MIRROR OF ADR-052. Order split one basket into N orders because N sellers
 * fulfil independently; this rejoins them because a card is charged once. So the
 * grain here is the checkout GROUP, and success or refund fans out to every order
 * under it together.
 *
 * `uuid` IS ALSO THE PSP'S `merchant_oid`, which is the single decision the whole
 * idempotency story rests on. PayTR echoes it back on every callback — including
 * the retries it sends until it gets `"OK"` — so recognising a payment already
 * processed is a lookup on this column rather than a guess. It is a uuid, so it
 * leaks nothing about volume (non-negotiable #7), and it is ours, so the PSP
 * never needs to know an internal id exists.
 *
 * IT IMPORTS NO MODULE. The checkout group and the customer are bare uuids (plus
 * the customer's internal id for the owner-scoped read, the ADR-040 pair); the
 * orders under the group are resolved through the Core read port. The single
 * relation is `currency()`, Localization being the platform-wide exception.
 *
 * AUDITED, AND MORE THAN ANYTHING ELSE ON THE PLATFORM IS. Every transition here
 * is a claim about money — "we collected 1 299,90 TL from this person" — and the
 * audit row is what answers a chargeback six months later. `provider_payload`
 * keeps the verified callback verbatim for the same reason: it is evidence, and
 * evidence you have normalised is evidence you have edited.
 *
 * NO CARD DATA, EVER. There is no property here that could hold a PAN, and the
 * flow is built so none could arrive: the card lives inside the PSP's iframe.
 *
 * @property int $id
 * @property string $uuid
 * @property string $checkout_group_uuid
 * @property int $customer_id
 * @property string $customer_uuid
 * @property int $amount_minor
 * @property int $points_spent
 * @property int $discount_minor
 * @property int $currency_id
 * @property PaymentStatus $status
 * @property string $provider
 * @property string|null $provider_reference
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $provider_payload
 * @property Carbon|null $paid_at
 * @property Carbon|null $failed_at
 * @property-read Currency $currency
 *
 * @see docs/modules/Payment.md §4
 */
final class Payment extends Model
{
    use Auditable;

    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use HasUuid;

    protected $table = 'payments';

    protected $fillable = [
        'checkout_group_uuid',
        'customer_id',
        'customer_uuid',
        'amount_minor',
        'points_spent',
        'discount_minor',
        'currency_id',
        'status',
        'provider',
        'provider_reference',
        'failure_reason',
        'provider_payload',
        'paid_at',
        'failed_at',
    ];

    /**
     * The one permitted relation: Localization is platform-wide reference data
     * every module may read.
     *
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * What the PSP calls this payment.
     *
     * A METHOD RATHER THAN A SECOND COLUMN, because there is no second fact: the
     * merchant reference IS the uuid. Storing it twice would create the
     * possibility of them disagreeing, which is the only way this could ever go
     * wrong.
     */
    public function merchantReference(): string
    {
        return $this->uuid;
    }

    /**
     * Whether this payment may still be settled by a success callback.
     *
     * THE IDEMPOTENCY QUESTION, asked in one place. A callback for a payment that
     * is already `paid` is not an error and not a second charge — it is PayTR
     * retrying because it did not hear `"OK"` the first time, and the correct
     * response is to change nothing and say `"OK"` again.
     */
    public function awaitsSettlement(): bool
    {
        return $this->status->isOpen();
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

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'amount_minor' => 'integer',
            'points_spent' => 'integer',
            'discount_minor' => 'integer',
            'currency_id' => 'integer',
            'status' => PaymentStatus::class,
            'provider_payload' => 'array',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
