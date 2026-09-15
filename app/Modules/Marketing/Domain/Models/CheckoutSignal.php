<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Domain\Models;

use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A checkout's browser match keys, captured on the pay request (Marketing.md).
 *
 * **TRANSIENT.** Overwritten by a retried pay, pruned after a few days. Nothing
 * reads it except the Purchase it enriches.
 *
 * @property int $id
 * @property string $uuid
 * @property string $checkout_group_uuid
 * @property string|null $fbp
 * @property string|null $fbc
 * @property string|null $client_ip
 * @property string|null $client_user_agent
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class CheckoutSignal extends Model
{
    use HasUuid;

    protected $table = 'marketing_checkout_signals';

    protected $fillable = [
        'checkout_group_uuid',
        'fbp',
        'fbc',
        'client_ip',
        'client_user_agent',
    ];
}
