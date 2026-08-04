<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Shared\Traits\HasUuid;
use Database\Modules\Catalog\Factories\TaxRateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A KDV bracket (ADR-056, Catalog.md §2.4).
 *
 * A LOOKUP TABLE, not an enum, and the "enum or table?" rule is unusually clear
 * here: brackets change by government decision, not by release — Turkey moved
 * %8 → %10 and %18 → %20 in July 2023 with days of notice. An operator adds and
 * deactivates brackets without a deploy, so `is_active` (ADR-015) rather than a
 * status enum.
 *
 * THE RATE IS A RATIO. `0.2000` means %20. That is the form the arithmetic wants
 * — Order extracts the included KDV as `line_total − round(line_total / (1 +
 * rate))` (ADR-055) — and a column needing a ÷100 at every call site eventually
 * does not get one.
 *
 * IT IS EXPOSED AS A STRING, never a float. `rate` is DECIMAL because a rate
 * multiplied against a large total loses real money to binary rounding (ADR-005),
 * and reading it into a PHP float would reintroduce exactly what the column type
 * avoided. `scaledRate()` is the honest way to compute with it: an integer at a
 * known scale, so the whole KDV extraction runs in integer arithmetic.
 *
 * NOT LOCALIZED. "KDV %20" is a legal label, not marketing copy — one column,
 * like `Brand.name` (§13.5).
 *
 * AUDITABLE (ADR-027), and this is the model where it earns its keep most
 * cheaply: changing a rate silently re-prices the tax on everything sold under
 * the bracket from that moment, and "who set %10 and when" is a question a tax
 * inspection actually asks. It is also why this table needs no write Action —
 * there is no derived state, no event and nothing to coordinate, so a form field
 * maps to a column and the trail comes from the model rather than from a
 * transaction wrapper with one statement in it.
 *
 * A BRACKET IS NEVER DELETED once products use it: the FK restricts, and
 * withdrawing a repealed bracket is `is_active = false`. Order lines snapshot the
 * rate at placement anyway (ADR-053), so a historical total stays reproducible
 * even if the row were somehow lost.
 *
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property string $name
 * @property string $rate
 * @property bool $is_active
 *
 * @see docs/modules/Catalog.md §2.4
 */
final class TaxRate extends Model
{
    use Auditable;

    /** @use HasFactory<TaxRateFactory> */
    use HasFactory;
    use HasUuid;

    /**
     * The scale `rate` is stored at — `decimal(6,4)`, so 0.2000 is 2000 at scale
     * 10^4. Named rather than repeated so the integer arithmetic downstream and
     * the column definition cannot drift apart.
     */
    public const int SCALE = 10_000;

    protected $table = 'tax_rates';

    protected $fillable = [
        'code',
        'name',
        'rate',
        'is_active',
    ];

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * The rate as an INTEGER at `self::SCALE` — 0.2000 → 2000.
     *
     * The float-free way to compute with a rate. A caller extracting included KDV
     * does `round($total * SCALE / (SCALE + scaledRate()))` entirely in integers,
     * so no money-adjacent value ever passes through a binary float.
     *
     * Cast from the string via `(int) round((float) …)` deliberately: the float
     * exists for exactly one multiplication of a four-decimal literal, which is
     * exact in IEEE 754 at this magnitude, and it never touches an amount.
     */
    public function scaledRate(): int
    {
        return (int) round(((float) $this->rate) * self::SCALE);
    }

    /**
     * "%20" — what a seller reads on a picker. Derived, never stored: a second
     * column holding the same number in a different unit is a column that
     * disagrees with the first one eventually.
     */
    public function percentLabel(): string
    {
        $percent = ((float) $this->rate) * 100;

        // Whole percentages are the norm and "%20" beats "%20,00"; a fractional
        // bracket still renders honestly if a government ever invents one.
        return '%'.rtrim(rtrim(number_format($percent, 2, ',', ''), '0'), ',');
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

    protected static function newFactory(): TaxRateFactory
    {
        return TaxRateFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // A STRING, not a float (see the class docblock). Eloquent's decimal
            // cast keeps it one on the way out and normalises the scale on the
            // way in, so `0.2` saved by hand reads back as `0.2000`.
            'rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
