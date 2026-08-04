<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Shared\Traits\HasUuid;
use Database\Modules\Payment\Factories\CommissionRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One "these sales take this much" rule (ADR-061, Payment.md §6).
 *
 * FOUR NULLABLE SCOPES, NULL MEANING "ANY". The rule with all four null is the
 * platform default — not a special kind of row, just the least specific one there
 * is, which is why nothing here has an `is_default` flag to disagree with.
 *
 * AUDITED, because a rate change is a change to what every seller earns, and "who
 * dropped Kozmetik to 8% and when" is a question somebody will ask.
 *
 * IT HOLDS NO MONEY. A rate is a ratio (ADR-005); the kuruş it produces are
 * computed at payment and frozen onto the order line, where they can never be
 * moved by editing this row.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $label
 * @property string|null $seller_org_uuid
 * @property string|null $product_uuid
 * @property string|null $brand_uuid
 * @property string|null $category_uuid
 * @property string $rate
 * @property int $priority
 * @property bool $is_active
 *
 * @see docs/modules/Payment.md §6
 */
final class CommissionRule extends Model
{
    use Auditable;

    /** @use HasFactory<CommissionRuleFactory> */
    use HasFactory;
    use HasUuid;

    protected $table = 'commission_rules';

    protected $fillable = [
        'label',
        'seller_org_uuid',
        'product_uuid',
        'brand_uuid',
        'category_uuid',
        'rate',
        'priority',
        'is_active',
    ];

    /**
     * How many scopes this rule sets — its SPECIFICITY (Payment.md §6).
     *
     * THE RANKING KEY, and the reason resolution is explainable. "Seller X in
     * Kozmetik" sets two and beats "Kozmetik" which sets one, which beats the
     * default which sets none. A seller asking "why 12%?" gets an answer that is
     * one sentence long, which is what most rule engines cannot manage.
     */
    public function specificity(): int
    {
        return count(array_filter([
            $this->seller_org_uuid,
            $this->product_uuid,
            $this->brand_uuid,
            $this->category_uuid,
        ]));
    }

    /**
     * Whether this is the catch-all every line falls back to.
     */
    public function isPlatformDefault(): bool
    {
        return $this->specificity() === 0;
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // A RATIO, kept as a decimal string rather than a float — the money
            // rule applied to the one place a decimal is legitimate (ADR-005).
            'rate' => 'decimal:4',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
