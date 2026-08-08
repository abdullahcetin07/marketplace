<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Modules\Order\Domain\Enums\ReturnRequestStatus;
use App\Shared\Traits\HasUuid;
use Database\Modules\Order\Factories\ReturnRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * "İade etmek istiyorum" — the buyer asks, the seller approves, and the money
 * moves when the goods are back (ADR-073).
 *
 * **IT REPLACED AN INSTANT REFUND, AND THAT IS THE POINT.** ADR-064 treated the
 * return window as the approval: a buyer inside it got their money the moment
 * they asked. For physical goods that is refunding on trust — the seller is made
 * whole never, and the parcel may or may not come back. So the request is now a
 * conversation with three steps, and `RefundLinesAction` fires at the LAST one.
 *
 * **IT IS THE POST-DELIVERY MIRROR OF `CancellationRequest`** (ADR-065) and
 * deliberately shaped like it — same module, same partial-unique rule, same
 * refuse-a-second-open-request behaviour. The three differences are the three
 * facts a return has and a cancellation does not:
 *
 *   - **`line_quantities`.** A cancellation names a whole order; a return names
 *     one shoe of the two. The quantities are the buyer's ASK, re-checked against
 *     what is still returnable when the seller completes — a request can sit for
 *     days, and an admin may have refunded a line meanwhile.
 *   - **`return_code` + `cargo_company_uuid`.** The seller's instructions for
 *     getting the parcel back. Set on approval, meaningless before it.
 *
 *   - **`Approved` is not the end.** @see `ReturnRequestStatus`.
 *
 * **IT HOLDS NO MONEY**, which is what keeps it in Order. The refund, the
 * proportional commission reversal and the restock happen behind the Core return
 * port, in Payment. An amount on this row would be a second version of a number
 * the ledger already holds.
 *
 * **A COMPLETED REQUEST IS NOT WHERE THE REFUND LIVES.** The ORDER becomes
 * `Refunded` by `PaymentRefunded`'s cause, like every other refund on this
 * platform; this row records only that the seller received the goods.
 *
 * @property int $id
 * @property string $uuid
 * @property string $order_uuid
 * @property int $requested_by
 * @property int $customer_id
 * @property string|null $reason
 * @property ReturnRequestStatus $status
 * @property array<string, int> $line_quantities
 * @property string|null $return_code
 * @property string|null $cargo_company_uuid
 * @property string|null $decision_reason
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property int|null $completed_by
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order|null $order
 *
 * @see docs/modules/Order.md §3.6
 */
final class ReturnRequest extends Model
{
    use Auditable;

    /** @use HasFactory<ReturnRequestFactory> */
    use HasFactory;

    use HasUuid;

    protected $table = 'return_requests';

    protected $fillable = [
        'order_uuid',
        'requested_by',
        'customer_id',
        'reason',
        'status',
        'line_quantities',
        'return_code',
        'cargo_company_uuid',
        'decision_reason',
        'decided_by',
        'decided_at',
        'completed_by',
        'completed_at',
    ];

    /**
     * The order this is about — **by uuid**, the same shape and the same reason
     * as `CancellationRequest::order()`.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_uuid', 'uuid');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * How many units this request asks back, in total.
     *
     * A CONVENIENCE FOR DISPLAY, NOT A GUARANTEE. What is actually returnable is
     * Payment's arithmetic (`RefundableLines`), re-asked at completion — this is
     * the buyer's ask as it was written down.
     */
    public function totalQuantity(): int
    {
        return array_sum($this->line_quantities);
    }

    /**
     * Every return still running — `Requested` OR `Approved`. @see the enum.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ReturnRequestStatus::Requested,
            ReturnRequestStatus::Approved,
        ]);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeForOrder(Builder $query, string $orderUuid): Builder
    {
        return $query->where('order_uuid', $orderUuid);
    }

    protected static function newFactory(): ReturnRequestFactory
    {
        return ReturnRequestFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_by' => 'integer',
            'customer_id' => 'integer',
            'status' => ReturnRequestStatus::class,
            /*
            | AN ARRAY, KEYED BY ORDER-LINE UUID. A child table would be the
            | textbook shape and is the wrong one here: these rows are never
            | queried, aggregated or joined — they are one payload handed
            | straight to Payment's port, which does its own arithmetic against
            | `payment_refund_lines`. A `return_request_lines` table would be a
            | second place quantities live, and the authoritative one is already
            | Payment's.
            */
            'line_quantities' => 'array',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
            'completed_by' => 'integer',
            'completed_at' => 'datetime',
        ];
    }
}
