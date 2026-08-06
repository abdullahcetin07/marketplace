<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Modules\Localization\Domain\Models\Currency;
use App\Shared\Traits\HasUuid;
use Database\Modules\Organization\Factories\OrganizationBankAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An organization's payout destination — one per organization.
 *
 * THE IBAN IS A PAYOUT CREDENTIAL. It is:
 *   - **encrypted at rest** (the `encrypted` cast — declarative ORM metadata,
 *     ADR-023; the model names the cast, it does not call the encrypter), and
 *   - **excluded from the audit trail** (`$auditExclude`), so a diff row never
 *     becomes a place a full bank number leaks (ADR-027 / docs/audit.md).
 *
 * Only the last four digits are ever surfaced to a non-owner. This model holds
 * the account; it never moves money — payouts are Finance's concern.
 *
 * REVISION-FRIENDLY: the schema allows several accounts per org (`is_primary`,
 * `label`), but the app enables one — the primary — for now.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property string|null $label
 * @property bool $is_primary
 * @property string $account_holder
 * @property string $iban decrypted on access; never audited
 * @property string|null $bank_name
 * @property int $currency_id
 * @property \Illuminate\Support\Carbon|null $verified_at
 *
 * @see docs/modules/Organization.md §2.5
 */
final class OrganizationBankAccount extends Model
{
    use Auditable;

    /** @use HasFactory<OrganizationBankAccountFactory> */
    use HasFactory;

    use HasUuid;
    use SoftDeletes;

    protected $table = 'organization_bank_accounts';

    protected $fillable = [
        'organization_id',
        'label',
        'is_primary',
        'account_holder',
        'iban',
        'bank_name',
        'currency_id',
        'verified_at',
    ];

    /**
     * Never written to the audit trail — a credential must not live in a diff.
     * On top of the global exclusion list.
     *
     * @var array<int, string>
     */
    protected array $auditExclude = ['iban'];

    /**
     * Never serialised by default; resources expose only the masked form.
     *
     * @var array<int, string>
     */
    protected $hidden = ['iban'];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * The only form of the IBAN shown to a non-owner: last four digits.
     */
    public function maskedIban(): string
    {
        $iban = (string) $this->iban;
        $last = mb_substr($iban, -4);

        return $last === '' ? '' : '•••• '.$last;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'iban' => 'encrypted',
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }
}
