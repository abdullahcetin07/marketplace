<?php

declare(strict_types=1);

namespace App\Modules\Localization\Domain\Models;

use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A **mahalle** — the level a Turkish parcel is actually routed on.
 *
 * THE REASON THE WHOLE DATASET EXISTS. Il and ilçe are ~1,000 rows and a client
 * can bundle them; mahalle is ~73,000, which is why it has to be served rather
 * than shipped, and why the address form could not add this level on its own.
 *
 * @property int $id
 * @property string $uuid
 * @property int $geo_district_id
 * @property string $name
 * @property bool $is_active
 * @property-read GeoDistrict $district
 */
final class GeoNeighborhood extends Model
{
    use HasUuid;

    protected $table = 'geo_neighborhoods';

    protected $fillable = [
        'geo_district_id',
        'name',
        'is_active',
    ];

    /**
     * @return BelongsTo<GeoDistrict, $this>
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(GeoDistrict::class, 'geo_district_id');
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
            'geo_district_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
