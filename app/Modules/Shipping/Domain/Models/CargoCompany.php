<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Shared\Traits\HasUuid;
use Database\Modules\Shipping\Factories\CargoCompanyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A carrier a seller can hand a parcel to (ADR-063, Shipping.md §5).
 *
 * A LOOKUP TABLE by the platform's own test of who owns the value: a carrier
 * appears when the business signs a contract, not when somebody writes code. So
 * `is_active` (ADR-015) and an operator-managed row, not an enum case.
 *
 * `trackingUrlFor()` IS WHY THE TABLE EXISTS. Without it every surface that shows
 * a tracking number would need a per-carrier `match` — which means changing
 * frontend code each time operations adds one, the exact coupling this table
 * removes. The template lives beside the carrier and the caller substitutes.
 *
 * AUDITABLE (ADR-027), for the same reason `TaxRate` is: editing a template
 * silently changes every tracking link on the platform, and "who broke the Aras
 * links and when" is a question somebody eventually asks. It is also why there is
 * no write Action here — no derived state, no event, nothing to coordinate, so a
 * form field maps to a column and the trail comes from the model.
 *
 * NEVER DELETED once a shipment names it: the FK restricts and withdrawal is
 * `is_active = false`. A parcel's history must keep saying who carried it.
 *
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property string $name
 * @property string|null $tracking_url_template
 * @property bool $is_active
 * @property int $sort_order
 *
 * @see docs/modules/Shipping.md §5
 */
final class CargoCompany extends Model
{
    use Auditable;

    /** @use HasFactory<CargoCompanyFactory> */
    use HasFactory;

    use HasUuid;

    /**
     * The token a template substitutes. A constant rather than a literal in two
     * places, so the seeder's templates and the substitution cannot drift.
     */
    public const string TRACKING_TOKEN = '{tracking_number}';

    protected $table = 'cargo_companies';

    protected $fillable = [
        'code',
        'name',
        'tracking_url_template',
        'is_active',
        'sort_order',
    ];

    /**
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /**
     * Where a buyer goes to see where their parcel is — or null.
     *
     * NULL IS AN ORDINARY ANSWER, twice over: a carrier may have no public
     * tracking page, and a shipment may have no number yet. A link to a 404 is
     * worse than a number rendered as text, so the caller is handed the choice
     * rather than a broken URL.
     */
    public function trackingUrlFor(?string $trackingNumber): ?string
    {
        if ($trackingNumber === null || $trackingNumber === '' || $this->tracking_url_template === null) {
            return null;
        }

        return str_replace(self::TRACKING_TOKEN, rawurlencode($trackingNumber), $this->tracking_url_template);
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
     * The order a seller sees them in: what operations put first, then by name.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    protected static function newFactory(): CargoCompanyFactory
    {
        return CargoCompanyFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
