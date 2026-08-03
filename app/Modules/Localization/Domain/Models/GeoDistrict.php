<?php

declare(strict_types=1);

namespace App\Modules\Localization\Domain\Models;

use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A second-level administrative division — an **ilçe** in Turkey.
 *
 * @property int $id
 * @property string $uuid
 * @property int $geo_province_id
 * @property string $name
 * @property bool $is_active
 * @property-read GeoProvince $province
 * @property-read \Illuminate\Database\Eloquent\Collection<int, GeoNeighborhood> $neighborhoods
 */
final class GeoDistrict extends Model
{
    use HasUuid;

    protected $table = 'geo_districts';

    protected $fillable = [
        'geo_province_id',
        'name',
        'is_active',
    ];

    /**
     * @return BelongsTo<GeoProvince, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(GeoProvince::class, 'geo_province_id');
    }

    /**
     * @return HasMany<GeoNeighborhood, $this>
     */
    public function neighborhoods(): HasMany
    {
        return $this->hasMany(GeoNeighborhood::class, 'geo_district_id');
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
            'geo_province_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
