<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\Models;

use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A record that this delivered line has already been dealt with (ADR-087).
 *
 * The nightly sweep is re-offered every delivered line by Order every night; this
 * is what stops it asking the same buyer about the same purchase twice. A row
 * exists whether the invitation went out or was suppressed — see the migration
 * for why the difference is a nullable `sent_at` rather than a missing row.
 *
 * @property int $id
 * @property string $uuid
 * @property string $order_line_uuid
 * @property string $customer_uuid
 * @property string $product_uuid
 * @property Carbon|null $sent_at
 * @property string|null $suppressed_reason
 */
final class ReviewRequest extends Model
{
    use HasUuid;

    protected $table = 'review_requests';

    protected $fillable = [
        'order_line_uuid',
        'customer_uuid',
        'product_uuid',
        'sent_at',
        'suppressed_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }
}
