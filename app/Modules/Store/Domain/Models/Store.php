<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Localization\Domain\Models\Language;
use App\Modules\Localization\Domain\Models\Timezone;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Shared\Traits\HasUuid;
use Database\Modules\Store\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The storefront — the branded, addressable selling surface a customer visits.
 *
 * AN INDEPENDENT BOUNDED CONTEXT, not a child of Organization (ADR-033). It
 * references its owning company by `organization_id` + `organization_uuid` and
 * DELIBERATELY EXPOSES NO `organization()` RELATION: everything Store needs from
 * the company arrives on the creating event or through a Core query contract,
 * never a live cross-module code call. The FK exists for integrity only.
 *
 * CREATED ONLY BY EVENT (ADR-028/032). A Store is never created by a seller
 * action and never creates itself — the sole path is the listener consuming
 * `StoreOpeningApproved`, which is idempotent on `opening_request_uuid` (UNIQUE).
 * That column is the authoritative link back to the request that spawned it.
 *
 * AN AUDITABLE CORE AGGREGATE (ADR-027): every operational transition —
 * activation, suspension, closure — leaves a forensic before/after record.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property string $organization_uuid
 * @property string $opening_request_uuid
 * @property string $name
 * @property string $slug
 * @property string $store_number
 * @property StoreStatus $status
 * @property StoreStatus|null $status_before_suspension
 * @property int $default_language_id
 * @property int $default_currency_id
 * @property int|null $timezone_id
 * @property \Illuminate\Support\Carbon|null $activated_at
 * @property \Illuminate\Support\Carbon|null $paused_at
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property \Illuminate\Support\Carbon|null $suspended_at
 * @property int|null $suspended_by
 * @property string|null $suspension_reason
 *
 * @see docs/modules/Store.md §2.1
 */
final class Store extends Model
{
    use Auditable;

    /** @use HasFactory<StoreFactory> */
    use HasFactory;

    use HasUuid;
    use SoftDeletes;

    protected $table = 'stores';

    protected $fillable = [
        'organization_id',
        'organization_uuid',
        'opening_request_uuid',
        'name',
        'slug',
        'store_number',
        'status',
        'status_before_suspension',
        'default_language_id',
        'default_currency_id',
        'timezone_id',
        'activated_at',
        'paused_at',
        'closed_at',
        'suspended_at',
        'suspended_by',
        'suspension_reason',
    ];

    /**
     * The store's default language (Localization is the one permitted
     * cross-module dependency).
     *
     * @return BelongsTo<Language, $this>
     */
    public function defaultLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'default_language_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function defaultCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency_id');
    }

    /**
     * @return BelongsTo<Timezone, $this>
     */
    public function timezone(): BelongsTo
    {
        return $this->belongsTo(Timezone::class, 'timezone_id');
    }

    /**
     * @return HasOne<StoreSettings, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(StoreSettings::class);
    }

    /**
     * @return HasOne<StoreBranding, $this>
     */
    public function branding(): HasOne
    {
        return $this->hasOne(StoreBranding::class);
    }

    /**
     * @return HasOne<StoreSeo, $this>
     */
    public function seo(): HasOne
    {
        return $this->hasOne(StoreSeo::class);
    }

    /**
     * @return HasOne<StoreContact, $this>
     */
    public function contact(): HasOne
    {
        return $this->hasOne(StoreContact::class);
    }

    /**
     * Whether the storefront serves the public right now.
     *
     * A store is reached by its platform path `/store/{slug}` (ADR-035) — there
     * is no per-store domain in v1 — so "live" is exactly "Active". If custom
     * domains are introduced later (a dedicated ADR), this tightens to "Active
     * AND has a verified serving domain".
     */
    public function isLive(): bool
    {
        return $this->status->isServing();
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeWhereStatus(Builder $query, StoreStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * Only stores the public may see (ADR-034) — the visibility rule in one
     * place so the public surface never hand-rolls it.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('status', StoreStatus::Active->value);
    }

    /**
     * Point Eloquent at the module's factory. Factories live under
     * `database/Modules/Store/Factories`, not the default `database/factories`,
     * so the model names it explicitly — the discovery path documented in
     * `database/Modules/README.md`.
     */
    protected static function newFactory(): StoreFactory
    {
        return StoreFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StoreStatus::class,
            'status_before_suspension' => StoreStatus::class,
            'activated_at' => 'datetime',
            'paused_at' => 'datetime',
            'closed_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }
}
