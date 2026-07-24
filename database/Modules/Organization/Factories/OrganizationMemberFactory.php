<?php

declare(strict_types=1);

namespace Database\Modules\Organization\Factories;

use App\Models\Seller;
use App\Modules\Organization\Domain\Enums\OrganizationMemberStatus;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrganizationMember>
 */
final class OrganizationMemberFactory extends Factory
{
    protected $model = OrganizationMember::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'organization_id' => Organization::factory(),
            'user_id' => Seller::factory(),
            'role' => OrganizationRole::Manager,
            'status' => OrganizationMemberStatus::Active,
            'invited_by' => null,
            'joined_at' => now(),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (): array => ['role' => OrganizationRole::Owner]);
    }

    public function role(OrganizationRole $role): static
    {
        return $this->state(fn (): array => ['role' => $role]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => OrganizationMemberStatus::Suspended]);
    }
}
