<?php

declare(strict_types=1);

namespace Database\Modules\Organization\Factories;

use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationBankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrganizationBankAccount>
 */
final class OrganizationBankAccountFactory extends Factory
{
    protected $model = OrganizationBankAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'organization_id' => Organization::factory(),
            'account_holder' => fake()->company(),
            'iban' => 'TR'.fake()->numerify('##0000000000000000000000'),
            'bank_name' => fake()->company().' Bank',
            'currency_id' => Currency::query()->value('id') ?? Currency::factory(),
            'verified_at' => null,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (): array => ['verified_at' => now()]);
    }
}
