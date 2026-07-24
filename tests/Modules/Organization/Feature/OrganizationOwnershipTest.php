<?php

declare(strict_types=1);

use App\Models\Seller;
use App\Modules\Organization\Application\Actions\ChangeMemberRoleAction;
use App\Modules\Organization\Application\Actions\RemoveMemberAction;
use App\Modules\Organization\Application\Actions\TransferOwnershipAction;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Events\OrganizationOwnerTransferred;
use App\Modules\Organization\Domain\Exceptions\OwnershipViolation;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Organization ownership invariant (Phase 2, ADR-029)
|--------------------------------------------------------------------------
|
| Exactly one Owner, always; the Owner cannot be removed or re-roled — only
| transferred, atomically, to another active Seller member. A failure here is a
| broken invariant, not a feature bug.
*/

beforeEach(fn () => $this->seedPlatform());

/**
 * An organization with its Owner membership in place.
 *
 * @return array{0: Organization, 1: OrganizationMember}
 */
function orgWithOwner(): array
{
    $org = Organization::factory()->create();
    $ownerMember = OrganizationMember::factory()->for($org)->owner()
        ->create(['user_id' => $org->owner_id]);

    return [$org, $ownerMember];
}

it('refuses to remove the owner', function (): void {
    [, $ownerMember] = orgWithOwner();

    expect(fn () => app(RemoveMemberAction::class)->run($ownerMember, 'trying to leave'))
        ->toThrow(OwnershipViolation::class);

    expect(OrganizationMember::query()->whereKey($ownerMember->getKey())->exists())->toBeTrue();
});

it('refuses to change the owner role directly', function (): void {
    [, $ownerMember] = orgWithOwner();

    expect(fn () => app(ChangeMemberRoleAction::class)->run($ownerMember, OrganizationRole::Manager, 'demote'))
        ->toThrow(OwnershipViolation::class);
});

it('refuses to promote a member to owner via a role change', function (): void {
    $org = Organization::factory()->create();
    $member = OrganizationMember::factory()->for($org)->role(OrganizationRole::Manager)->create();

    expect(fn () => app(ChangeMemberRoleAction::class)->run($member, OrganizationRole::Owner, 'sneaky'))
        ->toThrow(OwnershipViolation::class);
});

it('transfers ownership atomically, demoting the old owner and promoting the new', function (): void {
    [$org, $ownerMember] = orgWithOwner();
    $newOwner = Seller::factory()->create();
    $targetMember = OrganizationMember::factory()->for($org)->role(OrganizationRole::Manager)
        ->create(['user_id' => $newOwner->getKey()]);

    Event::fake([OrganizationOwnerTransferred::class]);
    app(TransferOwnershipAction::class)->run($org, $targetMember, 'Founder stepping down', OrganizationRole::Manager);

    expect($org->fresh()->owner_id)->toBe($newOwner->getKey())
        ->and($targetMember->fresh()->role)->toBe(OrganizationRole::Owner)
        ->and($ownerMember->fresh()->role)->toBe(OrganizationRole::Manager);

    // Still exactly one Owner.
    expect(OrganizationMember::query()->where('organization_id', $org->getKey())
        ->where('role', OrganizationRole::Owner->value)->count())->toBe(1);

    Event::assertDispatched(
        OrganizationOwnerTransferred::class,
        fn (OrganizationOwnerTransferred $e): bool => $e->newOwnerId === $newOwner->getKey(),
    );
});

it('refuses to transfer ownership to an inactive member', function (): void {
    [$org] = orgWithOwner();
    $targetMember = OrganizationMember::factory()->for($org)->role(OrganizationRole::Manager)
        ->suspended()->create();

    expect(fn () => app(TransferOwnershipAction::class)->run($org, $targetMember, 'inactive target'))
        ->toThrow(OwnershipViolation::class);
});

it('refuses to transfer ownership to a member of another organization', function (): void {
    [$org] = orgWithOwner();
    $otherOrg = Organization::factory()->create();
    $foreignMember = OrganizationMember::factory()->for($otherOrg)->role(OrganizationRole::Manager)->create();

    expect(fn () => app(TransferOwnershipAction::class)->run($org, $foreignMember, 'wrong org'))
        ->toThrow(OwnershipViolation::class);
});
