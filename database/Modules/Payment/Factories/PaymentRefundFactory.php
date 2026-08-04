<?php

declare(strict_types=1);

namespace Database\Modules\Payment\Factories;

use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Domain\Models\PaymentRefund;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentRefund>
 */
final class PaymentRefundFactory extends Factory
{
    protected $model = PaymentRefund::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'payment_uuid' => (string) Str::uuid(),
            'order_uuid' => (string) Str::uuid(),
            'seller_org_uuid' => (string) Str::uuid(),
            'amount_minor' => 12_990,
            'currency_id' => Currency::query()->value('id') ?? 1,
        ];
    }

    public function forPayment(Payment $payment): self
    {
        return $this->state(fn (): array => [
            'payment_id' => $payment->getKey(),
            'payment_uuid' => $payment->uuid,
            'currency_id' => $payment->currency_id,
        ]);
    }
}
