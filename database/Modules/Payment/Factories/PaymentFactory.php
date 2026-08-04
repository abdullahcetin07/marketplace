<?php

declare(strict_types=1);

namespace Database\Modules\Payment\Factories;

use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'checkout_group_uuid' => (string) Str::uuid(),
            'customer_id' => 1,
            'customer_uuid' => (string) Str::uuid(),
            // Kuruş, always an integer — a factory that produced a float would
            // make every test that used it lie about the money rule.
            'amount_minor' => 12_990,
            'currency_id' => Currency::query()->value('id') ?? 1,
            'status' => PaymentStatus::Pending,
            'provider' => 'paytr',
        ];
    }

    public function paid(): self
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Failed,
            'failed_at' => now(),
        ]);
    }
}
