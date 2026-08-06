<?php

declare(strict_types=1);

namespace Database\Modules\Order\Factories;

use App\Modules\Order\Domain\Enums\CancellationRequestStatus;
use App\Modules\Order\Domain\Models\CancellationRequest;
use App\Modules\Order\Domain\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CancellationRequest>
 */
final class CancellationRequestFactory extends Factory
{
    protected $model = CancellationRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_uuid' => fn (): string => Order::factory()->create()->uuid,
            'requested_by' => 1,
            'reason' => 'Yanlışlıkla sipariş verdim',
            'status' => CancellationRequestStatus::Pending,
        ];
    }

    public function forOrder(Order $order): self
    {
        return $this->state(fn (): array => [
            'order_uuid' => $order->uuid,
            'requested_by' => $order->customer_id,
        ]);
    }

    public function rejected(): self
    {
        return $this->state(fn (): array => [
            'status' => CancellationRequestStatus::Rejected,
            'decision_reason' => 'Ürün kargoya hazırlandı',
            'decided_at' => now(),
        ]);
    }
}
