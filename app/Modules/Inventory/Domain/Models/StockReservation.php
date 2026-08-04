<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Inventory\Domain\Enums\ReservationStatus;
use App\Shared\Traits\HasUuid;
use Database\Modules\Inventory\Factories\StockReservationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hold on stock, findable by the caller's own key (§2.3).
 *
 * WHY THIS EXISTS SEPARATELY FROM THE LEDGER. The movements record that units
 * were held; this records that a PARTICULAR hold is still standing, and how
 * many units it covers. `release(reference)` and `commit(reference)` need to
 * find that, and summing deltas to work out what is still outstanding would be
 * both slower and ambiguous.
 *
 * `reference` IS UNIQUE, and that is the idempotency guarantee rather than
 * a convenience. A checkout that retries must not reserve twice; a webhook that
 * fires twice must not commit twice. The unique index makes the second attempt
 * findable instead of duplicable, and the terminal statuses make acting on it a
 * no-op.
 *
 * THE CALLER OWNS THE KEY. Order will pass its own uuid, so it never has to
 * store an Inventory identifier to release what it reserved — the same reason
 * every other cross-context reference on this platform is the caller's uuid.
 *
 * @property int $id
 * @property string $uuid
 * @property string $reference
 * @property int $stock_item_id
 * @property int $quantity
 * @property ReservationStatus $status
 * @property \Illuminate\Support\Carbon|null $released_at
 * @property \Illuminate\Support\Carbon|null $committed_at
 * @property-read StockItem $stockItem
 *
 * @see docs/modules/Inventory.md §2.3, §3.2
 */
final class StockReservation extends Model
{
    /** @use HasFactory<StockReservationFactory> */
    use HasFactory;

    use HasUuid;

    protected $table = 'stock_reservations';

    protected $fillable = [
        'reference',
        'stock_item_id',
        'quantity',
        'status',
        'released_at',
        'committed_at',
    ];

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ReservationStatus::Active->value);
    }

    protected static function newFactory(): StockReservationFactory
    {
        return StockReservationFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'status' => ReservationStatus::class,
            'released_at' => 'datetime',
            'committed_at' => 'datetime',
        ];
    }
}
