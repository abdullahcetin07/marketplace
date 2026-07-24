<?php

declare(strict_types=1);

namespace Database\Modules\Organization\Factories;

use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationInvitation;
use App\Shared\Enums\InvitationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrganizationInvitation>
 */
final class OrganizationInvitationFactory extends Factory
{
    protected $model = OrganizationInvitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'organization_id' => Organization::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => OrganizationRole::Manager,
            // A stand-in hash; tests that accept go through InviteMemberAction to
            // get the real raw token. The raw token is never stored here either.
            'token_hash' => hash('sha256', Str::random(64)),
            'status' => InvitationStatus::Pending,
            'invited_by' => null,
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'accepted_by' => null,
        ];
    }

    public function role(OrganizationRole $role): static
    {
        return $this->state(fn (): array => ['role' => $role]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'status' => InvitationStatus::Accepted,
            'accepted_at' => now(),
        ]);
    }
}
