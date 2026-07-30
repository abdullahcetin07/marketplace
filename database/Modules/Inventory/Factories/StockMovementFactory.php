<?php

declare(strict_types=1);

namespace Database\Modules\Inventory\Factories;

use App\Modules\Inventory\Domain\Enums\StockMovementType;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Inventory\Domain\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StockMovement>
 */
final class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'stock_item_id' => StockItem::factory(),
            'type' => StockMovementType::SellerAdjustment,
            'on_hand_delta' => fake()->numberBetween(1, 20),
            'reserved_delta' => 0,
            'reference' => null,
            'note' => null,
        ];
    }

    public function ofType(StockMovementType $type): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    /**
     * Signed deltas set explicitly — a movement says what CHANGED, and a test
     * that wants to prove the projection sums the ledger has to say both.
     */
    public function moving(int $onHandDelta, int $reservedDelta = 0): static
    {
        return $this->state(fn (): array => [
            'on_hand_delta' => $onHandDelta,
            'reserved_delta' => $reservedDelta,
        ]);
    }
}
