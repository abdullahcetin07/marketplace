<?php

declare(strict_types=1);

namespace Database\Modules\Inventory\Factories;

use App\Modules\Inventory\Domain\Models\StockItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StockItem>
 */
final class StockItemFactory extends Factory
{
    protected $model = StockItem::class;

    /**
     * EVERY FOREIGN REFERENCE IS AN INVENTED UUID, and the factory never reaches
     * for a Catalog, Offer or Organization factory — Inventory imports none of
     * them (ADR-048), and a factory that did would smuggle the dependency in
     * through the test suite's back door. A test that needs a real variant
     * creates one itself and passes its uuid in.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'variant_uuid' => (string) Str::uuid(),
            'product_uuid' => (string) Str::uuid(),
            'offer_uuid' => (string) Str::uuid(),
            'selling_org_id' => fake()->numberBetween(1, 1000),
            'selling_org_uuid' => (string) Str::uuid(),
            'on_hand' => fake()->numberBetween(1, 50),
            'reserved' => 0,
            'low_stock_threshold' => null,
            'low_stock_notified' => false,
        ];
    }

    /**
     * Exact numbers — the readable way for a reservation test to say "there is
     * one left and two people want it".
     */
    public function stocked(int $onHand, int $reserved = 0): static
    {
        return $this->state(fn (): array => [
            'on_hand' => $onHand,
            'reserved' => $reserved,
        ]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (): array => ['on_hand' => 0, 'reserved' => 0]);
    }

    public function withLowStockThreshold(int $threshold): static
    {
        return $this->state(fn (): array => ['low_stock_threshold' => $threshold]);
    }

    /**
     * Belonging to one selling organization — the ADR-040 id/uuid pair set
     * together, because a test that sets only one of them proves nothing about
     * the tenancy wall.
     */
    public function forOrganization(int $organizationId, string $organizationUuid): static
    {
        return $this->state(fn (): array => [
            'selling_org_id' => $organizationId,
            'selling_org_uuid' => $organizationUuid,
        ]);
    }

    public function forVariant(string $variantUuid, ?string $productUuid = null): static
    {
        return $this->state(fn (): array => [
            'variant_uuid' => $variantUuid,
            'product_uuid' => $productUuid ?? (string) Str::uuid(),
        ]);
    }
}
