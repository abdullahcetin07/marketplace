<?php

declare(strict_types=1);

namespace Database\Modules\Payment\Factories;

use App\Models\Admin;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Payment\Domain\Enums\PayoutStatus;
use App\Modules\Payment\Domain\Models\Payout;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payout>
 */
final class PayoutFactory extends Factory
{
    protected $model = Payout::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_org_uuid' => (string) Str::uuid(),
            'amount_minor' => 10_000,
            'currency_id' => Currency::query()->value('id') ?? 1,
            'status' => PayoutStatus::Pending,
            'created_by' => Admin::factory(),
        ];
    }

    public function paid(string $reference = 'EFT-123'): self
    {
        return $this->state(fn (): array => [
            'status' => PayoutStatus::Paid,
            'external_reference' => $reference,
            'paid_at' => now(),
        ]);
    }
}
