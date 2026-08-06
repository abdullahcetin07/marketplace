<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Models;

use App\Models\User;
use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Modules\Localization\Domain\Models\Country;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Organization\Domain\Enums\OrganizationStatus;
use App\Shared\Traits\HasUuid;
use Database\Modules\Organization\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The legal seller company — the registered business a seller operates as.
 *
 * NOT a Store. One Organization owns many Stores, but it may never create one
 * directly: it submits Store Opening Requests that an admin approves (ADR-028).
 * A Store is owned by the Store module; this model only counts them and holds
 * the allowance.
 *
 * AN AUDITABLE CORE AGGREGATE (ADR-027): every state change — approval,
 * rejection, suspension, ownership — leaves a forensic before/after record with
 * the admin's reason. It cannot exist without an owner (`owner_id` NOT NULL,
 * §3.9).
 *
 * @property int $id
 * @property string $uuid
 * @property int $owner_id
 * @property string $legal_name
 * @property string|null $display_name
 * @property string $slug
 * @property OrganizationStatus $status
 * @property int|null $plan_id
 * @property int|null $store_limit_override
 * @property int $country_id
 * @property int $currency_id
 * @property \Illuminate\Support\Carbon|null $verified_at
 * @property \Illuminate\Support\Carbon|null $suspended_at
 *
 * @see docs/modules/Organization.md §2.1
 */
final class Organization extends Model
{
    use Auditable;

    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUuid;
    use SoftDeletes;

    protected $table = 'organizations';

    protected $fillable = [
        'owner_id',
        'legal_name',
        'display_name',
        'slug',
        'status',
        'plan_id',
        'store_limit_override',
        'country_id',
        'currency_id',
        'verified_at',
        'suspended_at',
        'approved_by',
        'rejected_by',
        'suspended_by',
        'rejection_reason',
    ];

    /**
     * The sole Owner (a Seller). @see docs/modules/Organization.md §3.9.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<OrganizationPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(OrganizationPlan::class, 'plan_id');
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /**
     * @return HasOne<OrganizationKyc, $this>
     */
    public function kyc(): HasOne
    {
        return $this->hasOne(OrganizationKyc::class);
    }

    /**
     * The active payout account. Scoped to the primary — the schema allows
     * several, the app enables one for now (§2.5, Phase 5 note).
     *
     * @return HasOne<OrganizationBankAccount, $this>
     */
    public function bankAccount(): HasOne
    {
        return $this->hasOne(OrganizationBankAccount::class)->where('is_primary', true);
    }

    /**
     * Every bank account — the seam for enabling multiple accounts later.
     *
     * @return HasMany<OrganizationBankAccount, $this>
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(OrganizationBankAccount::class);
    }

    /**
     * @return HasMany<OrganizationDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(OrganizationDocument::class);
    }

    /**
     * @return HasMany<StoreOpeningRequest, $this>
     */
    public function storeOpeningRequests(): HasMany
    {
        return $this->hasMany(StoreOpeningRequest::class);
    }

    /**
     * The maximum number of Stores this organization may operate (ADR-028).
     *
     * Resolved by SOURCE precedence, first present wins — NOT by null-coalescing
     * values, because a plan's `null` limit is a real answer (unlimited), not an
     * absence:
     *
     *   1. per-organization override  (an admin's bespoke grant)
     *   2. the plan's limit           (may be null = unlimited)
     *   3. the system default         (config)
     *
     * Returns null for **unlimited**.
     */
    public function effectiveStoreLimit(): ?int
    {
        if ($this->store_limit_override !== null) {
            return $this->store_limit_override;
        }

        if ($this->plan_id !== null) {
            // The plan is the source; its limit — including null (unlimited) —
            // is the answer. Eager-loaded via the repository's $with.
            return $this->plan?->store_limit;
        }

        $default = config('marketplace.organization.default_store_limit');

        return $default === null ? null : (int) $default;
    }

    /**
     * How many more Stores may be opened, or null when unlimited.
     *
     * Phase 1 has no Store module, so the current count is zero; Phase 5 wires
     * `currentStoreCount()` to the real tally once stores can exist.
     */
    public function remainingStoreSlots(): ?int
    {
        $limit = $this->effectiveStoreLimit();

        if ($limit === null) {
            return null; // unlimited
        }

        return max(0, $limit - $this->currentStoreCount());
    }

    /**
     * Whether a new Store Opening Request may be submitted right now — the org
     * must be operational and under its limit.
     */
    public function canRequestStore(): bool
    {
        if (! $this->status->isOperational()) {
            return false;
        }

        $remaining = $this->remainingStoreSlots();

        return $remaining === null || $remaining > 0;
    }

    /**
     * Stores currently owned, for the limit check.
     *
     * Until the Store module owns the real tally, an APPROVED Store Opening
     * Request IS a store — each one consumed a slot the moment it was approved
     * (ADR-028). Counting them here is what makes the override → plan → config
     * limit actually bind: the Nth approval is refused once the allowance is
     * used. When Store exists, this switches to the real store count (or the
     * approved requests it created).
     *
     * An unsaved organization owns nothing, so it short-circuits before the
     * query — otherwise the relation is scoped by a null key and still hits the
     * database, which is both a wasted round trip and a hard failure anywhere
     * the model exists without one.
     */
    public function currentStoreCount(): int
    {
        if (! $this->exists) {
            return 0;
        }

        return $this->storeOpeningRequests()->approved()->count();
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeWhereStatus(Builder $query, OrganizationStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'store_limit_override' => 'integer',
            'verified_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }
}
