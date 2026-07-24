<?php

declare(strict_types=1);

namespace Database\Modules\Identity\Factories;

use App\Modules\Identity\Domain\Models\LoginAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LoginAttempt>
 */
final class LoginAttemptFactory extends Factory
{
    protected $model = LoginAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            // Null by default: an attempt against an address that does not
            // exist is the interesting case, and creating a user for every
            // fixture would hide it.
            'user_id' => null,
            'email' => fake()->safeEmail(),
            'guard' => 'customer',
            'successful' => false,
            'failure_reason' => 'invalid_credentials',
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'platform' => fake()->randomElement(['Windows', 'macOS', 'iOS', 'Android']),
            'browser' => fake()->randomElement(['Chrome', 'Firefox', 'Safari']),
            'location' => fake()->city(),
        ];
    }

    public function successful(): static
    {
        return $this->state(fn (): array => [
            'successful' => true,
            'failure_reason' => null,
        ]);
    }

    public function failedBecause(string $reason): static
    {
        return $this->state(fn (): array => [
            'successful' => false,
            'failure_reason' => $reason,
        ]);
    }

    public function forEmail(string $email): static
    {
        return $this->state(fn (): array => ['email' => mb_strtolower($email)]);
    }

    public function fromIp(string $ip): static
    {
        return $this->state(fn (): array => ['ip_address' => $ip]);
    }

    /**
     * Place the attempt in the past, for testing the detection windows in
     * LoginAttempt::recentFailuresFor().
     */
    public function minutesAgo(int $minutes): static
    {
        return $this->state(fn (): array => ['created_at' => now()->subMinutes($minutes)]);
    }
}
