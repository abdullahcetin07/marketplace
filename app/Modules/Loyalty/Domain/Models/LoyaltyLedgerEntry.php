<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Models;

use App\Modules\Loyalty\Domain\Enums\LoyaltyPointSource;
use App\Shared\Traits\HasUuid;
use Database\Modules\Loyalty\Factories\LoyaltyLedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One movement of points (ADR-081).
 *
 * **IMMUTABLE, ENFORCED RATHER THAN DOCUMENTED** (non-negotiable #9). Both hooks
 * return false, which cancels the operation. A correction is a NEW row — that is
 * what makes the sum trustworthy and what makes "why do I have 340 points" an
 * answerable question six months later.
 *
 * @property int $id
 * @property string $uuid
 * @property string $customer_uuid
 * @property int $points
 * @property LoyaltyPointSource $source_type
 * @property string $source_uuid
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 */
final class LoyaltyLedgerEntry extends Model
{
    /** @use HasFactory<LoyaltyLedgerEntryFactory> */
    use HasFactory;

    use HasUuid;

    // ONE TIMESTAMP, NOT TWO. A row that can never be updated has no honest
    // `updated_at` to keep.
    public $timestamps = false;

    protected $table = 'loyalty_ledger';

    /*
    | NAMED RATHER THAN `$guarded = []`, and `uuid` is deliberately absent: the
    | `HasUuid` trait generates it on `creating` and guards it from mass
    | assignment, so a caller that passed one would be silently overruled.
    */
    protected $fillable = [
        'customer_uuid',
        'points',
        'source_type',
        'source_uuid',
        'meta',
        'created_at',
    ];

    protected static function newFactory(): LoyaltyLedgerEntryFactory
    {
        return LoyaltyLedgerEntryFactory::new();
    }

    protected static function booted(): void
    {
        self::updating(static fn (): bool => false);
        self::deleting(static fn (): bool => false);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => LoyaltyPointSource::class,
            'points' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
