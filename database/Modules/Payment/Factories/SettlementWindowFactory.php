<?php

declare(strict_types=1);

namespace Database\Modules\Payment\Factories;

use App\Modules\Payment\Domain\Models\SettlementWindow;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SettlementWindow>
 */
final class SettlementWindowFactory extends Factory
{
    protected $model = SettlementWindow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $deliveredAt = now();

        return [
            'order_uuid' => (string) Str::uuid(),
            'seller_org_uuid' => (string) Str::uuid(),
            'delivered_at' => $deliveredAt,
            'delivered_via' => 'buyer',
            // Both windows OPEN by default — a freshly delivered parcel is money
            // on hold and a return the buyer may still make.
            'payout_eligible_at' => $deliveredAt->copy()->addDays(14),
            'return_window_ends_at' => $deliveredAt->copy()->addDays(14),
        ];
    }

    /**
     * Delivered long enough ago that the money may be paid out.
     */
    public function payable(): self
    {
        return $this->state(fn (): array => [
            'delivered_at' => now()->subDays(20),
            'payout_eligible_at' => now()->subDays(6),
            'return_window_ends_at' => now()->subDays(6),
        ]);
    }

    public function forOrder(string $orderUuid, ?string $sellerOrgUuid = null): self
    {
        return $this->state(fn (): array => array_filter([
            'order_uuid' => $orderUuid,
            'seller_org_uuid' => $sellerOrgUuid,
        ]));
    }
}
