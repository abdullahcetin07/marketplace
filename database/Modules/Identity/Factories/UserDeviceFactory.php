<?php

declare(strict_types=1);

namespace Database\Modules\Identity\Factories;

use App\Models\Customer;
use App\Modules\Identity\Domain\Models\UserDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserDevice>
 */
final class UserDeviceFactory extends Factory
{
    protected $model = UserDevice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => Customer::factory(),
            // unique(): the table has a (user_id, fingerprint) unique index,
            // and a test creating several devices must not collide.
            'fingerprint' => hash('sha256', (string) Str::uuid()),
            'platform' => fake()->randomElement(['Windows', 'macOS', 'iOS', 'Android', 'Linux']),
            'browser' => fake()->randomElement(['Chrome', 'Firefox', 'Safari', 'Edge']),
            'device_type' => fake()->randomElement(['desktop', 'mobile', 'tablet']),
            'is_trusted' => false,
            'trusted_at' => null,
            'last_used_at' => now(),
            'last_ip' => fake()->ipv4(),
            'location' => fake()->city(),
        ];
    }

    public function trusted(): static
    {
        return $this->state(fn (): array => [
            'is_trusted' => true,
            'trusted_at' => now(),
        ]);
    }

    /**
     * Trusted, but longer ago than marketplace.security.two_factor.trust_days.
     * isTrusted() must return false for this — trust expires.
     */
    public function trustExpired(): static
    {
        $days = (int) config('marketplace.security.two_factor.trust_days', 30);

        return $this->state(fn (): array => [
            'is_trusted' => true,
            'trusted_at' => now()->subDays($days + 1),
        ]);
    }
}
