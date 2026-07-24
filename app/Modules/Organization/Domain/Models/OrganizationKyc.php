<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Shared\Traits\HasUuid;
use Database\Modules\Organization\Factories\OrganizationKycFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organization's know-your-customer / company-verification data (one per org).
 *
 * COUNTRY-AGNOSTIC BY DESIGN (§4.3). The universal identifiers a company almost
 * everywhere has are columns; anything jurisdiction-specific — a Turkish
 * MERSİS number, a tax office, a trade-registry office — lives in the
 * `metadata` jsonb as an optional extension, so a new country needs no
 * migration, only a validation ruleset.
 *
 * `authorized_person_national_id` is a personal identifier: **encrypted at rest**
 * (the `encrypted` cast) and **excluded from the audit trail** (`$auditExclude`),
 * exactly like the bank IBAN. The rest of the KYC record IS audited, because a
 * dispute about "what did the company claim, and when" turns on it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property string|null $tax_number
 * @property string|null $registration_number
 * @property string|null $authorized_person_name
 * @property string|null $authorized_person_national_id  encrypted; never audited
 * @property array<string, mixed>|null $metadata          country-specific extras
 * @property \Illuminate\Support\Carbon|null $submitted_at
 *
 * @see docs/modules/Organization.md §4
 */
final class OrganizationKyc extends Model
{
    /** @use HasFactory<OrganizationKycFactory> */
    use HasFactory;

    use Auditable;
    use HasUuid;

    protected $table = 'organization_kyc';

    protected $fillable = [
        'organization_id',
        'tax_number',
        'registration_number',
        'authorized_person_name',
        'authorized_person_national_id',
        'metadata',
        'submitted_at',
    ];

    /**
     * The personal identifier is never written to the audit trail.
     *
     * @var array<int, string>
     */
    protected array $auditExclude = ['authorized_person_national_id'];

    /**
     * @var array<int, string>
     */
    protected $hidden = ['authorized_person_national_id'];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * A country-specific field, read from the jsonb bag (e.g. `mersis`,
     * `tax_office`). Returns null when the jurisdiction does not use it.
     */
    public function meta(string $key): mixed
    {
        return $this->metadata[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'authorized_person_national_id' => 'encrypted',
            'metadata' => 'array',
            'submitted_at' => 'datetime',
        ];
    }
}
