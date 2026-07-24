<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A storefront's operational preferences (§2.2).
 *
 * One row per store (HasOne). Owned by the store, NOT the platform Settings
 * module — these are shop-level display choices, not platform configuration.
 * Auditable (ADR-027 §15): who changed a store's operating rules is dispute
 * evidence. `metadata` is a jsonb bag so forward-compatible flags land without a
 * migration.
 *
 * @property int $id
 * @property int $store_id
 * @property string|null $announcement
 * @property bool $order_note_enabled
 * @property string $weight_unit
 * @property string $dimension_unit
 * @property array<string, mixed>|null $metadata
 *
 * @see docs/modules/Store.md §2.2
 */
final class StoreSettings extends Model
{
    use Auditable;

    protected $table = 'store_settings';

    protected $fillable = [
        'store_id',
        'announcement',
        'order_note_enabled',
        'weight_unit',
        'dimension_unit',
        'metadata',
    ];

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_note_enabled' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
