<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Models;

use App\Modules\Payment\Domain\Enums\LedgerEntryType;
use App\Shared\Traits\HasUuid;
use Database\Modules\Payment\Factories\SellerLedgerEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One movement in a seller's balance (ADR-062, Payment.md §7).
 *
 * APPEND-ONLY, ENFORCED RATHER THAN DOCUMENTED (non-negotiable #9). Both hooks
 * below return false, which cancels the operation. There is no escape hatch and
 * there must not be one — not even the narrow, once-only hole `OrderLine` has for
 * its commission. The difference is that the line's hole exists because one field
 * is genuinely decided later; here, every field of a row is known the moment it is
 * written, so an edit could only ever be a correction, and a correction to money
 * is a new entry, not a rewrite.
 *
 * THE BALANCE IS `Σ amount_minor`, COMPUTED ON READ. There is no balance column
 * anywhere on this platform, and that absence is the decision: a stored balance is
 * a number that can drift from the events that produced it, and the first time it
 * does, nobody can tell which is right.
 *
 * THE SIGN IS PART OF THE ROW. A `sale_credit` is stored positive and a
 * `commission_debit` negative, so the balance is a plain `SUM()` rather than a
 * `CASE` ladder that must know what every type means. Callers pass a magnitude and
 * `LedgerEntryType::signedAmount()` points it — once, for everybody.
 *
 * NO RELATIONS. Payment imports no module, so the seller, the order and the
 * payment are uuids and nothing here reaches through to them. That is also what
 * lets a ledger row outlive every one of them being reorganised, which for a
 * financial record is the right way round.
 *
 * @property int $id
 * @property string $uuid
 * @property string $seller_org_uuid
 * @property LedgerEntryType $type
 * @property int $amount_minor
 * @property string|null $order_uuid
 * @property string|null $payment_uuid
 * @property string|null $payout_uuid
 * @property string|null $note
 * @property Carbon $created_at
 *
 * @see docs/modules/Payment.md §7
 */
final class SellerLedgerEntry extends Model
{
    /** @use HasFactory<SellerLedgerEntryFactory> */
    use HasFactory;

    use HasUuid;

    /**
     * Nothing updates a row once written, so the column would be dead weight —
     * the same reasoning `StockMovement` uses.
     */
    public const UPDATED_AT = null;

    protected $table = 'seller_ledger_entries';

    protected $fillable = [
        'seller_org_uuid',
        'type',
        'amount_minor',
        'order_uuid',
        'payment_uuid',
        'payout_uuid',
        'note',
    ];

    /**
     * What a seller is owed, right now, in kuruş.
     *
     * A SUM AND NOT A COLUMN — see the class docblock. It can be negative: a
     * refund after a payout drives the balance below zero, which the ledger makes
     * safe by construction (Payment.md §8) and which P4's payout guard reads
     * before allowing another transfer.
     */
    public static function balanceFor(string $sellerOrgUuid): int
    {
        return (int) self::query()->where('seller_org_uuid', $sellerOrgUuid)->sum('amount_minor');
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeForSeller(Builder $query, string $sellerOrgUuid): Builder
    {
        return $query->where('seller_org_uuid', $sellerOrgUuid);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, LedgerEntryType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    protected static function newFactory(): SellerLedgerEntryFactory
    {
        return SellerLedgerEntryFactory::new();
    }

    protected static function booted(): void
    {
        /*
        | Immutability, enforced rather than documented (non-negotiable #9,
        | ADR-062). Both hooks return false, which cancels the operation. A
        | correction to money is a new entry, never a rewrite — that is what makes
        | the sum trustworthy and what makes a dispute answerable six months on.
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
            'type' => LedgerEntryType::class,
            'amount_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
