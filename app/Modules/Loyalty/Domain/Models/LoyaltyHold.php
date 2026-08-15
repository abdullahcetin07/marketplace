<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Models;

use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Points earmarked for a checkout in flight (ADR-084).
 *
 * **MUTABLE AND DELETABLE, UNLIKE THE LEDGER BESIDE IT** — and the difference is
 * the design rather than an inconsistency. A hold is transient state: it changes
 * when a shopper moves the slider and disappears when the payment fails.
 *
 * @property int $id
 * @property string $uuid
 * @property string $checkout_group_uuid
 * @property string $customer_uuid
 * @property int $points
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class LoyaltyHold extends Model
{
    use HasUuid;

    protected $table = 'loyalty_holds';

    protected $fillable = [
        'checkout_group_uuid',
        'customer_uuid',
        'points',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['points' => 'integer'];
    }
}
