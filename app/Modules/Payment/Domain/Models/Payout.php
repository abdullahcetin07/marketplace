<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Payment\Domain\Enums\PayoutStatus;
use App\Shared\Traits\HasUuid;
use Database\Modules\Payment\Factories\PayoutFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One transfer to a seller — recorded, not performed (ADR-062, Payment.md §8).
 *
 * **THE SOFTWARE MOVES NO MONEY.** The platform is a single merchant at the PSP
 * and settles with its sellers by its own means (ADR-060 §2). A row here says an
 * admin decided to send an amount, and later that somebody did and here is the
 * bank's reference. Nothing on this class calls a bank.
 *
 * WHAT IS APPEND-ONLY HERE, AND WHAT IS NOT. The work order calls payout records
 * append-only, and the money fields are: `amount_minor`, `seller_org_uuid` and
 * `currency_id` can never change, because the ledger already debited them and a
 * row that could be edited afterwards would make the balance a fiction. But the
 * OUTCOME is genuinely learned later — that is what the state machine is for — so
 * the guard below permits exactly the outcome fields, and only out of `pending`.
 * It is the same narrow-hole shape `OrderLine` uses for its commission, and for
 * the same reason: one fact is decided after the row is written.
 *
 * THE BALANCE MOVED WHEN THIS ROW WAS CREATED. `payout_debit` is appended at
 * creation, not at `paid`: the money is committed the moment an admin commits to
 * sending it, which is what stops two admins each paying out one balance. So a
 * `failed` payout must give it back, which `PayoutReversalCredit` does.
 *
 * IT IMPORTS NO MODULE. The seller is a uuid; the admins are `users` ids, which is
 * permitted because `app/Models/User` sits above the modules (001 §6). The only
 * relation is `currency()`, Localization being the platform-wide exception.
 *
 * `created_by` IS NULL WHEN THE SCHEDULE DECIDED IT (owner decision, 2026-08-06).
 * The nightly job proposes one payout per seller for whatever has become payable;
 * `isAutomatic()` is how a screen tells that apart from a transfer a person chose.
 * Automating the DECISION does not automate the bank — a human still makes the
 * transfer and marks it paid.
 *
 * @property int $id
 * @property string $uuid
 * @property string $seller_org_uuid
 * @property int $amount_minor
 * @property int $currency_id
 * @property PayoutStatus $status
 * @property string|null $external_reference
 * @property string|null $failure_reason
 * @property string|null $note
 * @property int|null $created_by
 * @property int|null $settled_by
 * @property Carbon|null $paid_at
 * @property Carbon|null $failed_at
 * @property-read Currency $currency
 *
 * @see docs/modules/Payment.md §8
 */
final class Payout extends Model
{
    use Auditable;

    /** @use HasFactory<PayoutFactory> */
    use HasFactory;

    use HasUuid;

    /**
     * The only fields a settled payout may write — see the class docblock.
     *
     * A CONSTANT RATHER THAN A LITERAL IN THE GUARD, so the list and the test that
     * pins it name the same thing.
     *
     * @var array<int, string>
     */
    public const array SETTLEMENT_FIELDS = [
        'status',
        'external_reference',
        'failure_reason',
        'settled_by',
        'paid_at',
        'failed_at',
    ];

    protected $table = 'payouts';

    protected $fillable = [
        'seller_org_uuid',
        'amount_minor',
        'currency_id',
        'status',
        'external_reference',
        'failure_reason',
        'note',
        'created_by',
        'settled_by',
        'paid_at',
        'failed_at',
    ];

    /**
     * The one permitted relation: Localization is platform-wide reference data.
     *
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Whether the schedule proposed this payout rather than a person.
     *
     * An operator reconciling a batch needs to know which transfers somebody chose
     * and which the platform did — asked here so the panel, a report and any
     * future export cannot answer it three different ways.
     */
    public function isAutomatic(): bool
    {
        return $this->created_by === null;
    }

    /**
     * Whether this write is the one deferred update a payout permits.
     *
     * NARROW IN BOTH DIRECTIONS. It must touch ONLY the outcome fields — so
     * slipping `amount_minor` into the same `update()` call fails the whole write
     * rather than sneaking through — and it must start from `pending`, so a
     * settled payout is final and a rejected one is not quietly re-marked paid.
     *
     * A failed transfer is retried by creating a NEW payout, not by editing this
     * one. That keeps the failed attempt on the record, which is what somebody
     * reconciling a bank statement actually needs.
     */
    public function isSettling(): bool
    {
        $dirty = array_keys($this->getDirty());

        if ($dirty === [] || array_diff($dirty, self::SETTLEMENT_FIELDS) !== []) {
            return false;
        }

        /*
        | COMPARED AS AN ENUM, NOT AS A STRING. `status` is cast, so
        | `getOriginal()` hands back a `PayoutStatus` — comparing it to
        | `->value` is always false, which would silently refuse every legitimate
        | settlement and leave payouts stuck pending forever. The same shape of
        | mistake `OrderQuery::orderStatus()` hit: a cast attribute read back
        | through a raw-looking accessor.
        */
        return $this->getOriginal('status') === PayoutStatus::Pending;
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

    protected static function newFactory(): PayoutFactory
    {
        return PayoutFactory::new();
    }

    protected static function booted(): void
    {
        /*
        | The money fields are immutable; the outcome is not. @see `isSettling()`
        | and the class docblock for why this hole exists and why it is this
        | narrow.
        */
        self::updating(fn (self $payout): bool => $payout->isSettling());

        // A payout that happened is a record of money leaving. It is never
        // deleted — a mistaken one is marked failed, which reverses the debit and
        // leaves both facts on the trail.
        self::deleting(static fn (): bool => false);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'currency_id' => 'integer',
            'created_by' => 'integer',
            'settled_by' => 'integer',
            'status' => PayoutStatus::class,
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
