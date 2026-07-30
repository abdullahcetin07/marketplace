<?php

declare(strict_types=1);

namespace Database\Modules\Order\Factories;

use App\Modules\Order\Domain\Models\OrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrderLine>
 */
final class OrderLineFactory extends Factory
{
    protected $model = OrderLine::class;

    /**
     * A CONSISTENT LINE BY DEFAULT: the tax is genuinely extracted from the total
     * at the stated rate, so a fixture never asserts against arithmetic that could
     * not have happened. `priced()` is how a test states the numbers it cares
     * about and gets the rest computed the same way checkout computes them.
     *
     * KDV-INCLUDED prices (ADR-042), integer minor units, decimal-string rate —
     * the same three rules the real line obeys.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unit = fake()->numberBetween(1_000, 100_000);
        $quantity = fake()->numberBetween(1, 3);
        $total = $unit * $quantity;

        return [
            'uuid' => (string) Str::uuid(),
            'order_id' => OrderFactory::new(),
            'offer_uuid' => (string) Str::uuid(),
            'variant_uuid' => (string) Str::uuid(),
            'product_uuid' => (string) Str::uuid(),
            // SNAPSHOTS, not lookups (ADR-053) — a line holds the title it was
            // bought under, whatever the catalog says today.
            'product_title' => Str::title(fake()->unique()->words(3, true)),
            'variant_label' => null,
            'unit_price_minor' => $unit,
            'tax_rate' => '0.2000',
            'quantity' => $quantity,
            'line_tax_minor' => self::includedTax($total, '0.2000'),
            'line_total_minor' => $total,
        ];
    }

    /**
     * Exact numbers, with the tax extracted the way §3.4 says.
     */
    public function priced(int $unitPriceMinor, int $quantity = 1, string $taxRate = '0.2000'): static
    {
        $total = $unitPriceMinor * $quantity;

        return $this->state(fn (): array => [
            'unit_price_minor' => $unitPriceMinor,
            'quantity' => $quantity,
            'tax_rate' => $taxRate,
            'line_total_minor' => $total,
            'line_tax_minor' => self::includedTax($total, $taxRate),
        ]);
    }

    /**
     * Snapshotted catalog labels — what a test asserts stayed put after the
     * catalog changed underneath (ADR-053).
     */
    public function labelled(string $productTitle, ?string $variantLabel = null): static
    {
        return $this->state(fn (): array => [
            'product_title' => $productTitle,
            'variant_label' => $variantLabel,
        ]);
    }

    public function forOffer(string $offerUuid, string $variantUuid, string $productUuid): static
    {
        return $this->state(fn (): array => [
            'offer_uuid' => $offerUuid,
            'variant_uuid' => $variantUuid,
            'product_uuid' => $productUuid,
        ]);
    }

    /**
     * The KDV INSIDE a tax-included total (§3.4), in integer arithmetic.
     *
     * Duplicated from the checkout action deliberately — a fixture that called the
     * production code could not disagree with it, and a test asserting the two
     * agree is the whole value of having it in two places.
     */
    private static function includedTax(int $totalMinor, string $rate): int
    {
        $scale = 10_000;
        $scaledRate = (int) round(((float) $rate) * $scale);

        $net = (int) round($totalMinor * $scale / ($scale + $scaledRate));

        return $totalMinor - $net;
    }
}
