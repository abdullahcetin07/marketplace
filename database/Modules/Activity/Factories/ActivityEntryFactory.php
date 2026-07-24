<?php

declare(strict_types=1);

namespace Database\Modules\Activity\Factories;

use App\Models\Customer;
use App\Models\User;
use App\Modules\Activity\Domain\Enums\ActivityType;
use App\Modules\Activity\Domain\Models\ActivityEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @extends Factory<ActivityEntry>
 */
final class ActivityEntryFactory extends Factory
{
    protected $model = ActivityEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => Customer::factory(),
            'type' => ActivityType::Login,
            'description' => null,
            'subject_type' => null,
            'subject_id' => null,
            'properties' => null,
            'ip_address' => fake()->ipv4(),
            'browser' => 'Chrome',
            'platform' => 'Windows',
            'correlation_id' => (string) Str::uuid(),
        ];
    }

    public function ofType(ActivityType $type): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    public function forUser(User|int $user): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user instanceof User ? $user->getKey() : $user,
        ]);
    }

    /**
     * An entry with no user — a failed login against an address that has no
     * account. The case that proves enumeration attempts are recorded.
     */
    public function anonymous(string $email): static
    {
        return $this->state(fn (): array => [
            'user_id' => null,
            'type' => ActivityType::LoginFailed,
            'properties' => ['email' => mb_strtolower($email)],
        ]);
    }

    /**
     * A type NOT in ActivityType::userVisible() — used to prove the
     * userVisible scope actually excludes internal entries rather than
     * relying on the view to hide them.
     */
    public function internal(): static
    {
        return $this->state(fn (): array => ['type' => ActivityType::PermissionChanged]);
    }

    public function securitySensitive(): static
    {
        return $this->state(fn (): array => ['type' => ActivityType::PasswordChanged]);
    }

    public function about(Model $subject): static
    {
        return $this->state(fn (): array => [
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
        ]);
    }
}
