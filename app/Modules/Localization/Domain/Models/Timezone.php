<?php

declare(strict_types=1);

namespace App\Modules\Localization\Domain\Models;

use App\Shared\Traits\HasUuid;
use Database\Modules\Localization\Factories\TimezoneFactory;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An IANA timezone the platform offers.
 *
 * A table rather than a free-text column so the admin UI can present a curated,
 * ordered list instead of all ~600 IANA zones, and so a zone can be retired
 * without breaking rows that reference it.
 *
 * `offset_minutes` is a CACHED CONVENIENCE for sorting and display only. It is
 * wrong for half the year in any DST zone. Never compute a time from it —
 * always convert through the IANA name, which is what `toDateTimeZone()` is
 * for.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name        IANA identifier, e.g. Europe/Istanbul
 * @property string $label
 * @property int $offset_minutes
 * @property bool $is_active
 * @property int $sort_order
 */
final class Timezone extends Model
{
    /** @use HasFactory<TimezoneFactory> */
    use HasFactory;

    use HasUuid;

    protected $table = 'timezones';

    protected $fillable = [
        'name',
        'label',
        'offset_minutes',
        'is_active',
        'sort_order',
    ];

    /*
    |--------------------------------------------------------------------------
    | Reads live in the repository, not here
    |--------------------------------------------------------------------------
    |
    | ADR-019 forbids cache() in the Domain layer. default() and findByName()
    | are now TimezoneRepositoryContract, implemented in Infrastructure.
    |
    |   app(TimezoneRepositoryContract::class)->default();
    |
    | toDateTimeZone() and the offset helpers below stay — they are pure
    | computations on this row (ADR-011).
    */

    /**
     * @return HasMany<Country, $this>
     */
    public function countries(): HasMany
    {
        return $this->hasMany(Country::class);
    }

    /**
     * The only correct way to use this row for date arithmetic.
     */
    public function toDateTimeZone(): DateTimeZone
    {
        return new DateTimeZone($this->name);
    }

    /**
     * Current UTC offset, recomputed live so it is correct across DST — unlike
     * the stored `offset_minutes`.
     */
    public function currentOffsetMinutes(): int
    {
        return (int) ($this->toDateTimeZone()->getOffset(new \DateTimeImmutable('now', new DateTimeZone('UTC'))) / 60);
    }

    /**
     * "UTC+03:00" — for display in a picker.
     */
    public function offsetLabel(): string
    {
        $minutes = $this->currentOffsetMinutes();
        $sign = $minutes < 0 ? '-' : '+';
        $minutes = abs($minutes);

        return sprintf('UTC%s%02d:%02d', $sign, intdiv($minutes, 60), $minutes % 60);
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
        return $query->orderBy('sort_order')->orderBy('offset_minutes')->orderBy('name');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'offset_minutes' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
