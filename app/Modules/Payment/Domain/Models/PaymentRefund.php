<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Modules\Localization\Domain\Models\Currency;
use App\Shared\Traits\HasUuid;
use Database\Modules\Payment\Factories\PaymentRefundFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One order's money, given back (Payment.md §8, P5).
 *
 * APPEND-ONLY, WITHOUT THE NARROW HOLE `Payout` HAS. A payout learns its outcome
 * later — a bank may reject the transfer — so its state machine had to stay
 * writable. A refund does not: it is written only after the PSP has already said
 * yes, so there is no fact left to learn. Both hooks refuse, and there is no
 * escape hatch.
 *
 * WHAT IS REFUNDED IS A SUM OF THESE ROWS. `refundedMinorFor()` is a `SUM`, the
 * same shape as `SellerLedgerEntry::balanceFor()` and for the same reason
 * (ADR-062): a stored total can disagree with the rows it summarises, and nothing
 * can then say which one is right.
 *
 * **IT WAS ONE ROW PER (PAYMENT, ORDER) UNTIL S4, AND THAT IS NO LONGER TRUE.**
 * P5 held it with a unique index, because a refund was whole-order and a human
 * clicking twice had to meet the database rather than a race in application code.
 * S4 made a refund LINE-LEVEL — one shoe today, the other next week — so a second
 * refund of one order became legitimate and the index had to go.
 *
 * THE GUARANTEE MOVED RATHER THAN DISAPPEARED, and moved to something weaker,
 * which is worth saying plainly: it is now a SUM of `payment_refund_lines`
 * against the line's original quantity, checked in `RefundableLines` and nowhere
 * else. A constraint cannot be forgotten and a sum can. What did NOT change is
 * that nothing may be refunded twice — only what enforces it.
 *
 * @see App\Modules\Payment\Domain\Models\PaymentRefundLine
 *
 * IT IMPORTS NO MODULE. The order and the seller are uuids; the actor is a
 * `users` id, permitted because `app/Models` sits above the modules (001 §6).
 *
 * @property int $id
 * @property string $uuid
 * @property int $payment_id
 * @property string $payment_uuid
 * @property string $order_uuid
 * @property string $seller_org_uuid
 * @property int $amount_minor
 * @property int $currency_id
 * @property string|null $provider_reference
 * @property string|null $reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read Currency $currency
 * @property-read Payment $payment
 *
 * @see docs/modules/Payment.md §8
 */
final class PaymentRefund extends Model
{
    use Auditable;

    /** @use HasFactory<PaymentRefundFactory> */
    use HasFactory;

    use HasUuid;

    /**
     * Append-only: there is no `updated_at`, because there is no update.
     */
    public const UPDATED_AT = null;

    protected $table = 'payment_refunds';

    protected $fillable = [
        'payment_id',
        'payment_uuid',
        'order_uuid',
        'seller_org_uuid',
        'amount_minor',
        'currency_id',
        'provider_reference',
        'reason',
        'created_by',
    ];

    /**
     * How much of one payment has been given back — a SUM, never a column.
     *
     * WHAT DECIDES `refunded` VS `partially_refunded`. Comparing this against the
     * payment's amount is the whole of that rule, and it lives here so the answer
     * cannot be computed two different ways in two places.
     */
    public static function refundedMinorFor(int $paymentId): int
    {
        return (int) self::query()->where('payment_id', $paymentId)->sum('amount_minor');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * The one permitted relation outside this module: Localization is
     * platform-wide reference data.
     *
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeForPayment(Builder $query, int $paymentId): Builder
    {
        return $query->where('payment_id', $paymentId);
    }

    protected static function newFactory(): PaymentRefundFactory
    {
        return PaymentRefundFactory::new();
    }

    protected static function booted(): void
    {
        /*
        | NO HOLE AT ALL, unlike `Payout`. A refund row is written after the PSP
        | has already agreed, so nothing about it is learned afterwards. A
        | reversal of a reversal would be another row, not an edit to this one.
        */
        self::updating(static fn (): bool => false);
        self::deleting(static fn (): bool => false);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_id' => 'integer',
            'amount_minor' => 'integer',
            'currency_id' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
