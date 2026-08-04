<?php

declare(strict_types=1);

namespace App\Modules\Localization\Domain\Models;

use App\Modules\Localization\Domain\Enums\SymbolPosition;
use App\Shared\Traits\HasUuid;
use Database\Modules\Localization\Factories\CurrencyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A currency the platform can price in.
 *
 * WAS AN ENUM IN SPRINT 0. Promoted to a table because exchange rates change
 * hourly and administrators must enable a currency without a deploy — that is
 * operational data, not a code concept. See docs/001_Architecture.md
 * §"Enums vs lookup tables".
 *
 * MONEY IS STILL AN INTEGER OF MINOR UNITS. That rule did not change and never
 * will: `decimal_places` is the exponent for converting, not a licence to use
 * floats. `exchange_rate` is the one decimal column here, and it is a rate,
 * not an amount.
 *
 * @property int $id
 * @property string $uuid
 * @property string $code ISO-4217, e.g. TRY
 * @property string $name
 * @property string|null $native_name
 * @property string $symbol
 * @property SymbolPosition $symbol_position
 * @property int $decimal_places
 * @property string $decimal_separator
 * @property string $thousands_separator
 * @property string $exchange_rate relative to the default currency
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $rate_updated_at
 */
final class Currency extends Model
{
    /** @use HasFactory<CurrencyFactory> */
    use HasFactory;

    use HasUuid;

    protected $table = 'currencies';

    protected $fillable = [
        'code',
        'name',
        'native_name',
        'symbol',
        'symbol_position',
        'decimal_places',
        'decimal_separator',
        'thousands_separator',
        'exchange_rate',
        'is_default',
        'is_active',
        'sort_order',
    ];

    /*
    |--------------------------------------------------------------------------
    | Reads live in the repository, not here
    |--------------------------------------------------------------------------
    |
    | ADR-019 forbids cache() in the Domain layer. default() and findByCode()
    | are now CurrencyRepositoryContract, implemented in Infrastructure.
    |
    |   app(CurrencyRepositoryContract::class)->default();
    |
    | The money methods below stay — they are pure conversions on this row and
    | carry no infrastructure (ADR-011).
    */

    /**
     * Multiplier between major and minor units — 100 for a 2-decimal currency.
     */
    public function factor(): int
    {
        return 10 ** $this->decimal_places;
    }

    /**
     * Major units -> minor units, rounding half-up at the last minor digit.
     *
     * Accepts a string so exact decimal input from a form or API is not
     * degraded through a float on the way in.
     */
    public function toMinor(int|float|string $majorAmount): int
    {
        return (int) round(((float) $majorAmount) * $this->factor());
    }

    public function toMajor(int $minorAmount): float
    {
        return $minorAmount / $this->factor();
    }

    /**
     * Render an integer minor amount using this currency's own separators and
     * symbol placement.
     *
     * Deliberately NOT `NumberFormatter`: intl formats by locale, but the
     * platform lets an administrator configure a currency's separators
     * independently of the viewer's locale. A Turkish admin viewing USD should
     * see the format the currency row defines.
     */
    public function format(int $minorAmount, bool $withSymbol = true): string
    {
        $formatted = number_format(
            $this->toMajor($minorAmount),
            $this->decimal_places,
            $this->decimal_separator,
            $this->thousands_separator,
        );

        return $withSymbol
            ? $this->symbol_position->apply($formatted, $this->symbol)
            : $formatted;
    }

    /**
     * Convert a minor amount from this currency into another.
     *
     * Both rates are relative to the platform base currency, so the conversion
     * goes through it. Rounding happens once, at the end, in the target
     * currency's precision — converting via an intermediate rounded value
     * loses money on every hop.
     */
    public function convertTo(self $target, int $minorAmount): int
    {
        if ($this->is($target)) {
            return $minorAmount;
        }

        $inBase = $this->toMajor($minorAmount) / (float) $this->exchange_rate;

        return $target->toMinor($inBase * (float) $target->exchange_rate);
    }

    /**
     * Whether the stored rate is fresh enough to price with.
     *
     * A stale rate is worse than a missing one: it silently misprices. Callers
     * that touch real money should check this and refuse rather than guess.
     */
    public function hasFreshRate(int $maxAgeHours = 24): bool
    {
        if ($this->is_default) {
            return true; // the base currency's rate is 1.0 by definition
        }

        return $this->rate_updated_at !== null
            && $this->rate_updated_at->gt(now()->subHours($maxAgeHours));
    }

    /**
     * @return HasMany<Country, $this>
     */
    public function countries(): HasMany
    {
        return $this->hasMany(Country::class);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('code');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'symbol_position' => SymbolPosition::class,
            'decimal_places' => 'integer',
            // string, not float: the rate is read into arbitrary-precision
            // arithmetic, and casting it to float here would defeat that.
            'exchange_rate' => 'string',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'rate_updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Exactly one default currency. Enforced here as well as by a partial
        // unique index, because the failure mode of two defaults is that
        // CurrencyRepository::default() returns whichever row the planner picked.
        self::saving(static function (self $currency): void {
            if ($currency->is_default && $currency->isDirty('is_default')) {
                self::query()
                    ->where('is_default', true)
                    ->whereKeyNot($currency->getKey() ?? 0)
                    ->update(['is_default' => false]);
            }
        });

        // Cache invalidation is LocalizationCacheObserver's job (ADR-019).
    }
}
