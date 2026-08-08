<?php

declare(strict_types=1);

namespace Database\Modules\Order\Factories;

use App\Modules\Order\Domain\Enums\ReturnRequestStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\ReturnRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReturnRequest>
 */
final class ReturnRequestFactory extends Factory
{
    protected $model = ReturnRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_uuid' => fn (): string => Order::factory()->create()->uuid,
            'requested_by' => 1,
            'customer_id' => 1,
            'reason' => 'Ürün beklediğim gibi değil',
            'status' => ReturnRequestStatus::Requested,
            'line_quantities' => [],
        ];
    }

    public function forOrder(Order $order): self
    {
        return $this->state(fn (): array => [
            'order_uuid' => $order->uuid,
            'requested_by' => $order->customer_id,
            'customer_id' => $order->customer_id,
        ]);
    }

    public function forCustomer(int $customerId): self
    {
        return $this->state(fn (): array => [
            'requested_by' => $customerId,
            'customer_id' => $customerId,
        ]);
    }

    /**
     * @param array<string, int> $quantities
     */
    public function lines(array $quantities): self
    {
        return $this->state(fn (): array => ['line_quantities' => $quantities]);
    }

    public function requested(): self
    {
        return $this->state(fn (): array => ['status' => ReturnRequestStatus::Requested]);
    }

    public function approved(): self
    {
        return $this->state(fn (): array => [
            'status' => ReturnRequestStatus::Approved,
            'return_code' => 'IADE-'.fake()->numerify('######'),
            'decided_by' => 1,
            'decided_at' => now(),
        ]);
    }

    public function rejected(): self
    {
        return $this->state(fn (): array => [
            'status' => ReturnRequestStatus::Rejected,
            'decision_reason' => 'İade süresi doldu',
            'decided_by' => 1,
            'decided_at' => now(),
        ]);
    }

    public function completed(): self
    {
        return $this->state(fn (): array => [
            'status' => ReturnRequestStatus::Completed,
            'return_code' => 'IADE-'.fake()->numerify('######'),
            'decided_by' => 1,
            'decided_at' => now(),
            'completed_by' => 1,
            'completed_at' => now(),
        ]);
    }
}
