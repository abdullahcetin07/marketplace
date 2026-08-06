<?php

declare(strict_types=1);

namespace Database\Modules\Payment\Factories;

use App\Modules\Payment\Domain\Models\PaymentRefund;
use App\Modules\Payment\Domain\Models\PaymentRefundLine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentRefundLine>
 */
final class PaymentRefundLineFactory extends Factory
{
    protected $model = PaymentRefundLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_refund_id' => PaymentRefund::factory(),
            'order_line_uuid' => (string) Str::uuid(),
            'variant_uuid' => (string) Str::uuid(),
            'quantity' => 1,
            'amount_minor' => 12_000,
            'commission_minor' => 2_160,
        ];
    }
}
