<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Models;

use App\Shared\Traits\HasUuid;
use Database\Modules\Order\Factories\OrderLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bought thing, FROZEN (ADR-053, §2.4).
 *
 * THIS CLASS IS THE ADR. An order line snapshots the offer's unit price, the
 * product title, the variant label and the KDV rate as they were at placement.
 * The catalog can be re-titled, the offer re-priced, the bracket revised and the
 * whole listing withdrawn — and this row does not move, because an order is a
 * financial and legal record of what was agreed.
 *
 * THE COST (ADR-053): Order duplicates data that lives authoritatively in Catalog
 * and Offer, and a corrected typo in a product title will never reach past
 * orders. Accepted, and the alternative is worse: a line that read through to the
 * catalog would make every historical total and every issued invoice
 * unreproducible the moment a seller edited anything.
 *
 * SO IT IS IMMUTABLE IN CODE, NOT MERELY BY CONVENTION. `booted()` refuses
 * updates and deletes outright — the Audit/Activity treatment (non-negotiable #9)
 * applied to money. There is no "correct a line" path and there should not be: a
 * wrong order is cancelled and re-placed, which leaves both facts on the record.
 *
 * FOUR MONEY-ADJACENT COLUMNS, EACH STORED RATHER THAN DERIVED:
 *
 *   `unit_price_minor`  what one costs, KDV INCLUDED (ADR-042)
 *   `quantity`          how many
 *   `line_total_minor`  unit × quantity — stored, because it is what was agreed,
 *                       and recomputing it later would depend on today's
 *                       rounding rules rather than that day's
 *   `line_tax_minor`    the KDV INSIDE the line total, extracted with `tax_rate`
 *
 * The extraction is `line_total − round(line_total / (1 + tax_rate))` (§3.4) and
 * it happens ONCE, in the checkout action, in integer arithmetic. `tax_rate` is
 * DECIMAL (ADR-005) and is stored beside the result so an invoice can show the
 * rate that produced it — a rate looked up at print time could no longer match
 * the money.
 *
 * WHY `line_total` IS NOT `unit × quantity` COMPUTED ON READ: it would be, today.
 * But per-line discounts and per-line shipping both arrive in later sprints and
 * both change that identity, and the day they do, every historical line silently
 * starts answering a different question.
 *
 * @property int $id
 * @property string $uuid
 * @property int $order_id
 * @property string $offer_uuid
 * @property string $variant_uuid
 * @property string $product_uuid
 * @property string $product_title
 * @property string|null $variant_label
 * @property int $unit_price_minor
 * @property string $tax_rate
 * @property int $quantity
 * @property int $line_tax_minor
 * @property int $line_total_minor
 * @property-read Order $order
 *
 * @see docs/modules/Order.md §2.4, §3.4
 */
final class OrderLine extends Model
{
    /** @use HasFactory<OrderLineFactory> */
    use HasFactory;

    use HasUuid;

    /**
     * A line is written once and never touched again, so there is nothing for an
     * `updated_at` to record. Absent for the same reason it is absent on an audit
     * entry and a stock movement (ADR-050): a column that can only ever hold the
     * creation time is a column that invites somebody to update the row.
     */
    public const UPDATED_AT = null;

    protected $table = 'order_lines';

    protected static function newFactory(): OrderLineFactory
    {
        return OrderLineFactory::new();
    }

    protected $fillable = [
        'order_id',
        'offer_uuid',
        'variant_uuid',
        'product_uuid',
        'product_title',
        'variant_label',
        'unit_price_minor',
        'tax_rate',
        'quantity',
        'line_tax_minor',
        'line_total_minor',
    ];

    /**
     * IMMUTABILITY, ENFORCED (ADR-053).
     *
     * Returning `false` from these hooks aborts the operation silently rather
     * than throwing, which is the Audit/Activity precedent: a caller that tries to
     * mutate a financial record gets no write, and no partially-applied change.
     * A louder failure was considered and rejected — the point is that no code
     * path can succeed, not that a specific caller is punished.
     */
    protected static function booted(): void
    {
        static::updating(fn (): bool => false);
        static::deleting(fn (): bool => false);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The KDV-EXCLUSIVE portion of the line — what the seller keeps of it.
     *
     * Derived rather than stored, and that is deliberate: it is exactly
     * `line_total − line_tax`, both of which ARE stored, so a fourth column would
     * be a third way to say the same thing and the one that eventually disagrees.
     */
    public function netTotalMinor(): int
    {
        return $this->line_total_minor - $this->line_tax_minor;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_minor' => 'integer',
            'quantity' => 'integer',
            'line_tax_minor' => 'integer',
            'line_total_minor' => 'integer',
            // A STRING at the column's scale, never a float: this is the rate an
            // invoice reproduces, and a float would reintroduce exactly what
            // DECIMAL was chosen to avoid (ADR-005).
            'tax_rate' => 'decimal:4',
        ];
    }
}
