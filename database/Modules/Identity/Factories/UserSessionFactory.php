<?php

declare(strict_types=1);

namespace Database\Modules\Identity\Factories;

use App\Models\Customer;
use App\Modules\Identity\Domain\Models\UserSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserSession>
 */
final class UserSessionFactory extends Factory
{
    protected $model = UserSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => Customer::factory(),
            'device_id' => null,
            'session_id' => Str::random(40),
            'token_id' => null,
            'guard' => 'customer',
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'location' => fake()->city(),
            'last_activity_at' => now(),
            'expires_at' => now()->addHours(2),
            'revoked_at' => null,
        ];
    }

    /**
     * An API session rather than a cookie session — token_id set, session_id
     * null. Exactly one of the two is always populated.
     */
    public function apiToken(int $tokenId): static
    {
        return $this->state(fn (): array => [
            'session_id' => null,
            'token_id' => $tokenId,
        ]);
    }

    public function revoked(string $reason = 'manual'): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subHour()]);
    }

    /**
     * Idle long enough to be caught by the pruning scheduler.
     */
    public function stale(int $days = 45): static
    {
        return $this->state(fn (): array => [
            'last_activity_at' => now()->subDays($days),
        ]);
    }

    public function forGuard(string $guard): static
    {
        return $this->state(fn (): array => ['guard' => $guard]);
    }
}
