<?php

declare(strict_types=1);

use App\Models\Seller;
use App\Modules\Audit\Domain\Models\AuditEntry;
use App\Modules\Localization\Domain\Models\Country;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Organization\Application\Actions\ChangeMemberRoleAction;
use App\Modules\Organization\Application\Actions\RegisterOrganizationAction;
use App\Modules\Organization\Application\Actions\RemoveMemberAction;
use App\Modules\Organization\Domain\DTOs\RegisterOrganizationDTO;
use App\Modules\Organization\Domain\Enums\OrganizationMemberStatus;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Events\OrganizationMemberRemoved;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Membership + the capability matrix / isolation (Phase 2, ADR-030)
|--------------------------------------------------------------------------
*/

beforeEach(fn () => $this->seedPlatform());

function registerOrg(Seller $owner): Organization
{
    return app(RegisterOrganizationAction::class)->run(new RegisterOrganizationDTO(
        ownerId: $owner->getKey(),
        legalName: 'Acme Ltd',
        displayName: null,
        slug: 'acme-'.$owner->getKey(),
        countryCode: (string) Country::query()->value('iso2'),
        currencyCode: (string) Currency::query()->value('code'),
    ));
}

it('makes the registering owner an active Owner member', function (): void {
    $owner = Seller::factory()->create();
    $org = registerOrg($owner);

    $membership = OrganizationMember::query()
        ->where('organization_id', $org->getKey())
        ->where('user_id', $owner->getKey())
        ->first();

    expect($membership)->not->toBeNull()
        ->and($membership->role)->toBe(OrganizationRole::Owner)
        ->and($membership->status)->toBe(OrganizationMemberStatus::Active);
});

it('changes a non-owner member role and records the reason', function (): void {
    $org = Organization::factory()->create();
    $member = OrganizationMember::factory()->for($org)->role(OrganizationRole::Viewer)->create();
    AuditEntry::query()->delete();

    app(ChangeMemberRoleAction::class)->run($member, OrganizationRole::Manager, 'Promoted to manage the team');

    expect($member->fresh()->role)->toBe(OrganizationRole::Manager);

    $entry = AuditEntry::query()->forModel($member)->latest('id')->first();
    expect($entry->metadata)->toBe(['reason' => 'Promoted to manage the team']);
});

it('removes a non-owner member and announces it', function (): void {
    $org = Organization::factory()->create();
    $member = OrganizationMember::factory()->for($org)->role(OrganizationRole::Support)->create();
    Event::fake([OrganizationMemberRemoved::class]);

    app(RemoveMemberAction::class)->run($member, 'Left the company');

    expect(OrganizationMember::query()->whereKey($member->getKey())->exists())->toBeFalse()
        ->and(OrganizationMember::withTrashed()->whereKey($member->getKey())->exists())->toBeTrue();

    Event::assertDispatched(OrganizationMemberRemoved::class);
});

it('enforces the capability matrix and isolation through the policy', function (): void {
    $org = Organization::factory()->create();

    $manager = Seller::factory()->create();
    OrganizationMember::factory()->for($org)->role(OrganizationRole::Manager)
        ->create(['user_id' => $manager->getKey()]);

    $viewerUser = Seller::factory()->create();
    OrganizationMember::factory()->for($org)->role(OrganizationRole::Viewer)
        ->create(['user_id' => $viewerUser->getKey()]);

    $target = OrganizationMember::factory()->for($org)->role(OrganizationRole::Support)->create();

    // Manager holds member.update_role; Viewer does not.
    expect($manager->can('updateRole', $target))->toBeTrue()
        ->and($viewerUser->can('updateRole', $target))->toBeFalse();

    // ADR-030 isolation: a member of ANOTHER organization cannot touch this one.
    $otherOrg = Organization::factory()->create();
    $outsider = Seller::factory()->create();
    OrganizationMember::factory()->for($otherOrg)->role(OrganizationRole::Manager)
        ->create(['user_id' => $outsider->getKey()]);

    expect($outsider->can('updateRole', $target))->toBeFalse()
        ->and($outsider->can('view', $target))->toBeFalse();
});
