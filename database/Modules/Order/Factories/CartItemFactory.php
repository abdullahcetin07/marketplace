<?php

declare(strict_types=1);

namespace Database\Modules\Order\Factories;

use App\Modules\Order\Domain\Models\CartItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CartItem>
 */
final class CartItemFactory extends Factory
{
    protected $model = CartItem::class;

    /**
     * Every foreign reference is an invented uuid, for the reason in
     * `CartFactory`. NO PRICE — a cart line has never held one (§2.1).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'cart_id' => CartFactory::new(),
            'offer_uuid' => (string) Str::uuid(),
            'variant_uuid' => (string) Str::uuid(),
            'product_uuid' => (string) Str::uuid(),
            'selling_org_uuid' => (string) Str::uuid(),
            'store_uuid' => (string) Str::uuid(),
            'quantity' => fake()->numberBetween(1, 3),
        ];
    }

    /**
     * A line pointing at a REAL offer — the four uuids checkout groups and
     * reserves on, set together because a line carrying some of them is a row the
     * cart action cannot produce.
     */
    public function forOffer(
        string $offerUuid,
        string $variantUuid,
        string $productUuid,
        string $sellingOrgUuid,
        string $storeUuid,
    ): static {
        return $this->state(fn (): array => [
            'offer_uuid' => $offerUuid,
            'variant_uuid' => $variantUuid,
            'product_uuid' => $productUuid,
            'selling_org_uuid' => $sellingOrgUuid,
            'store_uuid' => $storeUuid,
        ]);
    }

    /**
     * Belonging to one seller — the readable way for a SPLIT test to say "these
     * two lines are one seller's and that one is another's" (ADR-052).
     */
    public function fromSeller(string $sellingOrgUuid, ?string $storeUuid = null): static
    {
        return $this->state(fn (): array => [
            'selling_org_uuid' => $sellingOrgUuid,
            'store_uuid' => $storeUuid ?? (string) Str::uuid(),
        ]);
    }

    public function quantity(int $quantity): static
    {
        return $this->state(fn (): array => ['quantity' => $quantity]);
    }
}
