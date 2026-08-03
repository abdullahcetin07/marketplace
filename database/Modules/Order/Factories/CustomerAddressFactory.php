<?php

declare(strict_types=1);

namespace Database\Modules\Order\Factories;

use App\Modules\Localization\Domain\Models\Country;
use App\Modules\Order\Domain\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomerAddress>
 */
final class CustomerAddressFactory extends Factory
{
    protected $model = CustomerAddress::class;

    /**
     * A TURKISH address, because that is what this platform ships to and a
     * fixture that reads like a real one is easier to judge in a failure message.
     *
     * The COUNTRY IS A REAL Localization ROW — the one relation this model has, and
     * the platform-wide reference-data exception (§5.1). Tests that touch it seed
     * the platform first (`$this->seedPlatform()`); the fallback creates one so a
     * unit-ish test does not have to.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'customer_id' => fake()->numberBetween(1, 1000),
            'customer_uuid' => (string) Str::uuid(),
            'label' => fake()->randomElement(['Ev', 'İş', 'Yazlık']),
            'recipient_name' => fake()->name(),
            'phone' => '+90555'.fake()->numerify('#######'),
            'line1' => fake()->streetAddress(),
            'line2' => null,
            'district' => fake()->randomElement(['Kadıköy', 'Çankaya', 'Konak', 'Nilüfer']),
            'neighborhood' => fake()->randomElement(['Caferağa', 'Kızılay', 'Alsancak', 'Görükle']),
            'city' => fake()->randomElement(['İstanbul', 'Ankara', 'İzmir', 'Bursa']),
            'postal_code' => fake()->numerify('#####'),
            'country_id' => Country::query()->where('iso2', 'TR')->value('id')
                ?? Country::query()->value('id')
                ?? Country::factory(),
            'is_default_shipping' => false,
            'is_default_billing' => false,
        ];
    }

    public function forCustomer(int $customerId, string $customerUuid): static
    {
        return $this->state(fn (): array => [
            'customer_id' => $customerId,
            'customer_uuid' => $customerUuid,
        ]);
    }

    /**
     * The default for both purposes — the common case, where a customer has one
     * address and it does everything.
     */
    public function default(): static
    {
        return $this->state(fn (): array => [
            'is_default_shipping' => true,
            'is_default_billing' => true,
        ]);
    }

    public function defaultShipping(): static
    {
        return $this->state(fn (): array => ['is_default_shipping' => true]);
    }

    public function defaultBilling(): static
    {
        return $this->state(fn (): array => ['is_default_billing' => true]);
    }
}
