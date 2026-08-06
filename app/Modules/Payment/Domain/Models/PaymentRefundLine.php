<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Models;

use App\Shared\Traits\HasUuid;
use Database\Modules\Payment\Factories\PaymentRefundLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of one refund: which item, how many, what it came to (S4).
 *
 * **IT IS WHERE "HOW MUCH OF THIS HAVE I ALREADY SENT BACK" IS ANSWERED.** P5's
 * unique index on `(payment, order)` guaranteed a refund happened once; a
 * line-level refund makes a second refund of the same order legitimate, so the
 * guarantee became a SUM over these rows against the line's original quantity.
 *
 * APPEND-ONLY, like everything else on the money side. There is no update a
 * refunded quantity could receive — sending two more back next week is another
 * row, not an edit to this one.
 *
 * THE AMOUNTS ARE STORED, NOT RECOMPUTED. The commission rate behind them was
 * frozen at payment (ADR-061) and the proportional arithmetic is rounded; a
 * rounding rule changed next year must not silently restate what was refunded
 * last year.
 *
 * @property int $id
 * @property string $uuid
 * @property int $payment_refund_id
 * @property string $order_line_uuid
 * @property string $variant_uuid
 * @property int $quantity
 * @property int $amount_minor
 * @property int $commission_minor
 * @property Carbon|null $created_at
 * @property-read PaymentRefund $refund
 *
 * @see docs/modules/Payment.md §8
 */
final class PaymentRefundLine extends Model
{
    /** @use HasFactory<PaymentRefundLineFactory> */
    use HasFactory;

    use HasUuid;

    /** Append-only: there is no `updated_at`, because there is no update. */
    public const UPDATED_AT = null;

    protected $table = 'payment_refund_lines';

    protected $fillable = [
        'payment_refund_id',
        'order_line_uuid',
        'variant_uuid',
        'quantity',
        'amount_minor',
        'commission_minor',
    ];

    /**
     * How many of one order line have already gone back.
     *
     * THE CHECK THAT REPLACED P5'S UNIQUE INDEX. A weaker mechanism, stated
     * plainly — a constraint cannot be forgotten and a sum can — which is why it
     * is asked here and nowhere else.
     */
    public static function refundedQuantityFor(string $orderLineUuid): int
    {
        return (int) self::query()->where('order_line_uuid', $orderLineUuid)->sum('quantity');
    }

    /**
     * @return BelongsTo<PaymentRefund, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(PaymentRefund::class, 'payment_refund_id');
    }

    protected static function newFactory(): PaymentRefundLineFactory
    {
        return PaymentRefundLineFactory::new();
    }

    protected static function booted(): void
    {
        // A refund line records money that left. Another return is another row.
        self::updating(static fn (): bool => false);
        self::deleting(static fn (): bool => false);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_refund_id' => 'integer',
            'quantity' => 'integer',
            'amount_minor' => 'integer',
            'commission_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
