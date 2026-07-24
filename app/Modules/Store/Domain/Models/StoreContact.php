<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A storefront's public contact details (§2.6).
 *
 * One row per store (HasOne). PUBLIC-facing — shown on the storefront — and
 * therefore distinct from the organization's private/legal contact. `address`
 * and `support_hours` are jsonb so structure can evolve without a migration.
 *
 * @property int $id
 * @property int $store_id
 * @property string|null $public_email
 * @property string|null $public_phone
 * @property array<string, mixed>|null $address
 * @property array<string, mixed>|null $support_hours
 *
 * @see docs/modules/Store.md §2.6
 */
final class StoreContact extends Model
{
    protected $table = 'store_contacts';

    protected $fillable = [
        'store_id',
        'public_email',
        'public_phone',
        'address',
        'support_hours',
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
            'address' => 'array',
            'support_hours' => 'array',
        ];
    }
}
