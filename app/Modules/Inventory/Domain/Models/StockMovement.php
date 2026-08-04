<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Inventory\Domain\Enums\StockMovementType;
use App\Shared\Traits\HasUuid;
use Database\Modules\Inventory\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a stock pool — the source of truth (ADR-050).
 *
 * APPEND-ONLY, ENFORCED RATHER THAN DOCUMENTED (non-negotiable #9). Both hooks
 * below return false, which cancels the operation. `StockItem.on_hand` and
 * `.reserved` are projections of these rows and can be rebuilt by summing them;
 * if a row could be edited, the projection and the ledger could disagree and
 * neither would be authoritative.
 *
 * SIGNED DELTAS, NOT ABSOLUTE VALUES. A movement says what CHANGED, so the
 * ledger sums to the projection and two movements can be applied in either order
 * with the same result. Recording "on_hand is now 7" instead would make every
 * row depend on the one before it and turn a missed row into permanent drift.
 *
 * TWO DELTAS, BECAUSE THE TWO NUMBERS MOVE INDEPENDENTLY. A reservation moves
 * `reserved` and leaves `on_hand` alone; a commit moves both. One combined
 * "quantity" column could not express that, and the ambiguity it created — did
 * three sell or are three merely held? — is exactly what makes a bare counter
 * useless in a dispute.
 *
 * `reference` is the CALLER'S key (a reservation uuid, later an order uuid), and
 * it is what makes release and commit idempotent: the reference identifies what
 * has already been recorded.
 *
 * NO `updated_at`. Nothing updates a row once written, so the column would be
 * dead weight on the busiest table this module has — the same reasoning
 * `LoginAttempt` uses.
 *
 * @property int $id
 * @property string $uuid
 * @property int $stock_item_id
 * @property StockMovementType $type
 * @property int $on_hand_delta
 * @property int $reserved_delta
 * @property string|null $reference
 * @property string|null $note
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read StockItem $stockItem
 *
 * @see docs/modules/Inventory.md §2.2
 */
final class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    use HasUuid;

    /**
     * The ledger is append-only, so nothing ever updates a row.
     */
    public const UPDATED_AT = null;

    protected $table = 'stock_movements';

    protected $fillable = [
        'stock_item_id',
        'type',
        'on_hand_delta',
        'reserved_delta',
        'reference',
        'note',
    ];

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /**
     * Movements recorded under one caller's key — what makes release and commit
     * idempotent rather than merely careful.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeForReference(Builder $query, string $reference): Builder
    {
        return $query->where('reference', $reference);
    }

    protected static function newFactory(): StockMovementFactory
    {
        return StockMovementFactory::new();
    }

    protected static function booted(): void
    {
        /*
        | Immutability, enforced rather than documented (non-negotiable #9,
        | ADR-050). Both hooks return false, which cancels the operation. There
        | is no escape hatch and there must not be one: the projection's whole
        | claim to be correct is that it can be recomputed from these rows.
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
            'type' => StockMovementType::class,
            'on_hand_delta' => 'integer',
            'reserved_delta' => 'integer',
        ];
    }
}
