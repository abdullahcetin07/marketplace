<?php

declare(strict_types=1);

namespace Database\Modules\Order\Factories;

use App\Modules\Order\Domain\Models\Cart;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cart>
 */
final class CartFactory extends Factory
{
    protected $model = Cart::class;

    /**
     * THE CUSTOMER IS AN INVENTED PAIR, not a `Customer::factory()`. Order imports
     * no module and references the customer by the ADR-040 id/uuid pair; a factory
     * that reached for the model would smuggle the dependency in through the test
     * suite's back door. A test that needs a real, authenticatable customer creates
     * one and passes both halves in via `forCustomer()`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'customer_id' => fake()->numberBetween(1, 1000),
            'customer_uuid' => (string) Str::uuid(),
        ];
    }

    /**
     * Belonging to one customer — the pair set TOGETHER, because a row carrying
     * one without the other is a state no production path can produce.
     */
    public function forCustomer(int $customerId, string $customerUuid): static
    {
        return $this->state(fn (): array => [
            'customer_id' => $customerId,
            'customer_uuid' => $customerUuid,
        ]);
    }
}
