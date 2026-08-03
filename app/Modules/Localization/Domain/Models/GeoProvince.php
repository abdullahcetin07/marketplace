<?php

declare(strict_types=1);

namespace App\Modules\Localization\Domain\Models;

use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A first-level administrative division — an **il** in Turkey (ADR-056
 * amendment).
 *
 * REFERENCE DATA FOR A DROPDOWN, not a component of an address. A customer
 * address stores `city` as a free string and holds no foreign key here; see the
 * migration for why an address must survive the registry being edited.
 *
 * NO `default()` OR CACHED READS ON THE MODEL. Like `Country` beside it, reads
 * belong to `GeoRepositoryContract` in Infrastructure — ADR-019 forbids `cache()`
 * in a Domain layer, and these are the most cacheable rows on the platform.
 *
 * @property int $id
 * @property string $uuid
 * @property int $country_id
 * @property string $name
 * @property string|null $code
 * @property bool $is_active
 * @property-read Country $country
 * @property-read \Illuminate\Database\Eloquent\Collection<int, GeoDistrict> $districts
 */
final class GeoProvince extends Model
{
    use HasUuid;

    protected $table = 'geo_provinces';

    protected $fillable = [
        'country_id',
        'name',
        'code',
        'is_active',
    ];

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return HasMany<GeoDistrict, $this>
     */
    public function districts(): HasMany
    {
        return $this->hasMany(GeoDistrict::class);
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'country_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
