<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Modules\Order\Domain\Enums\CancellationRequestStatus;
use App\Shared\Traits\HasUuid;
use Database\Modules\Order\Factories\CancellationRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * "İptal edebilir miyiz?" — the buyer asks, the seller answers (ADR-065, C2).
 *
 * **IT EXISTS BECAUSE THE BUYER CANNOT SIMPLY CANCEL A PAID ORDER.** ADR-065 is
 * explicit about why: the seller may already be picking and packing it, and
 * yanking a half-prepared order out from under them is not a decision the person
 * who is not doing the work gets to make alone. So the buyer's button creates a
 * REQUEST, and the seller's answer is what moves money.
 *
 * **IT HOLDS NO MONEY AND NO QUANTITIES**, which is what makes it Order's rather
 * than Payment's. It records that somebody asked and what came of it; the refund,
 * the commission reversal and the restock all happen behind the Core cancellation
 * port, in the module that owns them (§12.4b).
 *
 * **AN APPROVED REQUEST IS NOT WHERE THE CANCELLATION LIVES.** The ORDER is
 * cancelled — by `PaymentRefunded`'s cause, like every other cancellation on this
 * platform — and this row only records that the seller said yes. Two rows both
 * claiming to hold one fact is how they start disagreeing.
 *
 * ONE OPEN REQUEST PER ORDER, held by a partial UNIQUE index on PostgreSQL and by
 * the action everywhere. A rejected one does not block asking again: circumstances
 * change, and a seller who said no on Monday may say yes on Thursday while the
 * item still has not shipped.
 *
 * @property int $id
 * @property string $uuid
 * @property string $order_uuid
 * @property int $requested_by
 * @property string|null $reason
 * @property CancellationRequestStatus $status
 * @property string|null $decision_reason
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order|null $order
 *
 * @see docs/modules/Order.md §3.3
 */
final class CancellationRequest extends Model
{
    use Auditable;

    /** @use HasFactory<CancellationRequestFactory> */
    use HasFactory;

    use HasUuid;

    protected $table = 'cancellation_requests';

    protected $fillable = [
        'order_uuid',
        'requested_by',
        'reason',
        'status',
        'decision_reason',
        'decided_by',
        'decided_at',
    ];

    /**
     * The order this is about.
     *
     * **BY UUID, NOT BY FOREIGN KEY**, and the join column says so. The order is
     * this module's own — a real `foreignId` would have been legitimate — but
     * every identifier that crosses this row's public surface is already the
     * uuid, and carrying both would invite two answers to "which order is this".
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
     * The one request still awaiting an answer, if any.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', CancellationRequestStatus::Pending);
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

    protected static function newFactory(): CancellationRequestFactory
    {
        return CancellationRequestFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_by' => 'integer',
            'status' => CancellationRequestStatus::class,
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
        ];
    }
}
