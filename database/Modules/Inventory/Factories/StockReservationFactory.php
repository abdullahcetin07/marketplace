<?php

declare(strict_types=1);

namespace Database\Modules\Inventory\Factories;

use App\Modules\Inventory\Domain\Enums\ReservationStatus;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Inventory\Domain\Models\StockReservation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StockReservation>
 */
final class StockReservationFactory extends Factory
{
    protected $model = StockReservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            // The CALLER's key — Order's uuid in production. Invented here,
            // because Inventory never generates it.
            'reference' => (string) Str::uuid(),
            'stock_item_id' => StockItem::factory(),
            'quantity' => fake()->numberBetween(1, 5),
            'status' => ReservationStatus::Active,
        ];
    }

    public function forReference(string $reference): static
    {
        return $this->state(fn (): array => ['reference' => $reference]);
    }

    public function released(): static
    {
        return $this->state(fn (): array => [
            'status' => ReservationStatus::Released,
            'released_at' => now(),
        ]);
    }

    public function committed(): static
    {
        return $this->state(fn (): array => [
            'status' => ReservationStatus::Committed,
            'committed_at' => now(),
        ]);
    }
}
