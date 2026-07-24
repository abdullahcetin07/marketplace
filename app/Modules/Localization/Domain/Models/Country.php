<?php

declare(strict_types=1);

namespace App\Modules\Localization\Domain\Models;

use App\Shared\Traits\HasUuid;
use Database\Modules\Localization\Factories\CountryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A country the platform operates in or ships to.
 *
 * WAS AN ENUM IN SPRINT 0. Promoted to a table because every entry implies tax,
 * shipping and legal obligations that an operations team must be able to switch
 * on and off without a deploy. See docs/001_Architecture.md
 * §"Enums vs lookup tables".
 *
 * `iso2` is the canonical key everywhere the application refers to a country by
 * a short code — it is what ISO-3166 addresses, payment providers and shipping
 * carriers all speak.
 *
 * @property int $id
 * @property string $uuid
 * @property string $iso2
 * @property string $iso3
 * @property string|null $numeric_code
 * @property string $name
 * @property string|null $native_name
 * @property string|null $phone_code
 * @property int|null $currency_id
 * @property int|null $timezone_id
 * @property string|null $flag
 * @property string|null $capital
 * @property string|null $region
 * @property bool $is_active
 * @property bool $is_eu_member
 * @property int $sort_order
 * @property-read Currency|null $currency
 * @property-read Timezone|null $timezone
 */
final class Country extends Model
{
    /** @use HasFactory<CountryFactory> */
    use HasFactory;

    use HasUuid;

    protected $table = 'countries';

    protected $fillable = [
        'iso2',
        'iso3',
        'numeric_code',
        'name',
        'native_name',
        'phone_code',
        'currency_id',
        'timezone_id',
        'flag',
        'capital',
        'region',
        'is_active',
        'is_eu_member',
        'sort_order',
    ];

    /*
    |--------------------------------------------------------------------------
    | Reads live in the repository, not here
    |--------------------------------------------------------------------------
    |
    | ADR-019 forbids cache() in the Domain layer. default() and findByIso2()
    | are now CountryRepositoryContract, implemented in Infrastructure.
    |
    |   app(CountryRepositoryContract::class)->findByIso2('TR');
    */

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return BelongsTo<Timezone, $this>
     */
    public function timezone(): BelongsTo
    {
        return $this->belongsTo(Timezone::class);
    }

    /**
     * E.164 dialling prefix with the leading '+'.
     */
    public function dialPrefix(): ?string
    {
        return $this->phone_code === null ? null : '+'.ltrim($this->phone_code, '+');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEuMembers(Builder $query): Builder
    {
        return $query->where('is_eu_member', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_eu_member' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // ISO codes are canonical uppercase. Normalising on write means no
        // query ever has to guess the casing.
        static::saving(static function (self $country): void {
            $country->iso2 = mb_strtoupper($country->iso2);
            $country->iso3 = mb_strtoupper($country->iso3);
        });

        // Cache invalidation is LocalizationCacheObserver's job (ADR-019).
    }
}
