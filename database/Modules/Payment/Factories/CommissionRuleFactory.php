<?php

declare(strict_types=1);

namespace Database\Modules\Payment\Factories;

use App\Modules\Payment\Domain\Models\CommissionRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionRule>
 */
final class CommissionRuleFactory extends Factory
{
    protected $model = CommissionRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => 'Test kuralı',
            'rate' => '0.1500',
            'priority' => 0,
            'is_active' => true,
        ];
    }

    /**
     * The catch-all — no scopes at all.
     */
    public function platformDefault(string $rate = '0.1800'): self
    {
        return $this->state(fn (): array => [
            'label' => 'Platform varsayılanı',
            'seller_org_uuid' => null,
            'product_uuid' => null,
            'brand_uuid' => null,
            'category_uuid' => null,
            'rate' => $rate,
        ]);
    }

    /**
     * @param array<string, string|null> $scopes
     */
    public function scoped(array $scopes, string $rate): self
    {
        return $this->state(fn (): array => [...$scopes, 'rate' => $rate]);
    }
}
