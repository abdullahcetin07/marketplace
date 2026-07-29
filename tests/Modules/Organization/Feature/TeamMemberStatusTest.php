<?php

declare(strict_types=1);

use App\Models\Seller;
use App\Modules\Organization\Application\Actions\ChangeMemberStatusAction;
use App\Modules\Organization\Domain\Enums\OrganizationMemberStatus;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Events\OrganizationMemberRoleChanged;
use App\Modules\Organization\Domain\Events\OrganizationMemberStatusChanged;
use App\Modules\Organization\Domain\Exceptions\OwnershipViolation;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\TeamMemberResource\Pages\ListTeamMembers;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Deactivating a member — the middle ground between a role change and removal
|--------------------------------------------------------------------------
|
| Until now a seller's only lever was removal: a soft delete that costs the
| person their role and needs a fresh invitation to undo. Somebody on leave, a
| contractor between engagements or an account under review all want "cannot act
| right now", reversibly — which the `status` column already carried and nothing
| exposed.
|
| Two things carry the weight here. The OWNER cannot be deactivated (ADR-029):
| freezing them is removing them through a different column, and an org whose
| only Owner cannot act, with nobody able to transfer ownership away, is the
| deadlock that rule exists to prevent. And the tenancy wall holds for this
| action exactly as it does for every other (ADR-030).
|
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('seller'));
});

/**
 * An owner, their company, and one colleague to act on.
 *
 * @return array{owner: Seller, org: Organization, ownerMember: OrganizationMember, colleague: OrganizationMember}
 */
function teamWithColleague(OrganizationRole $role = OrganizationRole::Manager): array
{
    /** @var Seller $owner */
    $owner = Seller::factory()->create();
    $organization = Organization::factory()->create(['owner_id' => $owner->getKey()]);

    $ownerMember = OrganizationMember::factory()->for($organization)->role(OrganizationRole::Owner)
        ->create(['user_id' => $owner->getKey()]);

    $colleague = OrganizationMember::factory()->for($organization)->role($role)
        ->create(['user_id' => Seller::factory()->create()->getKey()]);

    return [
        'owner' => $owner,
        'org' => $organization,
        'ownerMember' => $ownerMember,
        'colleague' => $colleague,
    ];
}

it('deactivates a member without ending the membership', function (): void {
    Event::fake([OrganizationMemberStatusChanged::class]);
    $team = teamWithColleague();

    ChangeMemberStatusAction::make()->run($team['colleague'], OrganizationMemberStatus::Suspended, 'İzinde');

    $frozen = $team['colleague']->fresh();

    // The row and the ROLE survive — that is the entire difference from
    // removal, and what makes reactivating one click rather than a
    // re-invitation.
    expect($frozen->status)->toBe(OrganizationMemberStatus::Suspended)
        ->and($frozen->role)->toBe(OrganizationRole::Manager)
        ->and($frozen->exists)->toBeTrue();

    Event::assertDispatched(
        OrganizationMemberStatusChanged::class,
        fn (OrganizationMemberStatusChanged $event): bool => $event->previousStatus === 'active'
            && $event->status === 'suspended',
    );
});

it('reactivates a member back to exactly the access they had', function (): void {
    $team = teamWithColleague(OrganizationRole::Finance);

    ChangeMemberStatusAction::make()->run($team['colleague'], OrganizationMemberStatus::Suspended);
    ChangeMemberStatusAction::make()->run($team['colleague']->fresh(), OrganizationMemberStatus::Active);

    expect($team['colleague']->fresh()->status)->toBe(OrganizationMemberStatus::Active)
        ->and($team['colleague']->fresh()->role)->toBe(OrganizationRole::Finance);
});

it('never deactivates the Owner', function (): void {
    $team = teamWithColleague();

    // Freezing the Owner is removing them by another name: an org whose only
    // Owner cannot act, and nobody able to transfer ownership away.
    expect(fn () => ChangeMemberStatusAction::make()->run(
        $team['ownerMember'],
        OrganizationMemberStatus::Suspended,
    ))->toThrow(OwnershipViolation::class);

    expect($team['ownerMember']->fresh()->status)->toBe(OrganizationMemberStatus::Active);
});

it('takes a deactivated member out of the tenancy scope, so they can no longer act', function (): void {
    $team = teamWithColleague();
    $colleagueId = $team['colleague']->user_id;

    ChangeMemberStatusAction::make()->run($team['colleague'], OrganizationMemberStatus::Suspended);

    // `organizationIdsForUser()` is the ADR-030 scope every seller resource
    // reads. A frozen membership dropping out of it is what "cannot act"
    // actually means at the query level.
    $ids = app(\App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract::class)
        ->organizationIdsForUser($colleagueId);

    expect($ids)->not->toContain($team['org']->getKey());
});

/*
|--------------------------------------------------------------------------
| The seller's surface
|--------------------------------------------------------------------------
*/

it('changes a colleague’s role from the team list', function (): void {
    Event::fake([OrganizationMemberRoleChanged::class]);
    $team = teamWithColleague(OrganizationRole::Viewer);
    $this->actingAsSeller($team['owner']);

    Livewire::test(ListTeamMembers::class)
        ->callTableAction('changeRole', $team['colleague'], [
            'role' => OrganizationRole::Manager->value,
            'reason' => 'Terfi',
        ]);

    expect($team['colleague']->fresh()->role)->toBe(OrganizationRole::Manager);

    Event::assertDispatched(OrganizationMemberRoleChanged::class);
});

it('deactivates and reactivates from the team list', function (): void {
    $team = teamWithColleague();
    $this->actingAsSeller($team['owner']);

    Livewire::test(ListTeamMembers::class)
        ->callTableAction('deactivate', $team['colleague'], ['reason' => 'İzinde']);

    expect($team['colleague']->fresh()->status)->toBe(OrganizationMemberStatus::Suspended);

    Livewire::test(ListTeamMembers::class)
        ->callTableAction('reactivate', $team['colleague']->fresh());

    expect($team['colleague']->fresh()->status)->toBe(OrganizationMemberStatus::Active);
});

it('offers the Owner’s row neither a role change, a deactivation nor a removal', function (): void {
    $team = teamWithColleague();
    $this->actingAsSeller($team['owner']);

    Livewire::test(ListTeamMembers::class)
        ->assertTableActionHidden('changeRole', $team['ownerMember'])
        ->assertTableActionHidden('deactivate', $team['ownerMember'])
        ->assertTableActionHidden('remove', $team['ownerMember']);
});

it('keeps Remove alongside deactivation — they are different endings', function (): void {
    $team = teamWithColleague();
    $this->actingAsSeller($team['owner']);

    Livewire::test(ListTeamMembers::class)
        ->assertTableActionVisible('deactivate', $team['colleague'])
        ->assertTableActionVisible('remove', $team['colleague']);
});

it('denies a second seller any status change on the first’s member', function (): void {
    $theirs = teamWithColleague();
    $mine = teamWithColleague();

    $outsider = $this->actingAsSeller($mine['owner']);

    // ADR-030 — denied by the policy, whatever surface the request arrives
    // through.
    expect($outsider->can('changeStatus', $theirs['colleague']))->toBeFalse();

    Livewire::test(ListTeamMembers::class)
        ->assertCanNotSeeTableRecords([$theirs['colleague']]);
});

it('denies a member whose role does not grant removal', function (): void {
    $team = teamWithColleague();

    // A Viewer belongs to the company and holds member.view only.
    $viewerUser = Seller::factory()->create();
    OrganizationMember::factory()->for($team['org'])->role(OrganizationRole::Viewer)
        ->create(['user_id' => $viewerUser->getKey()]);

    $viewer = $this->actingAsSeller($viewerUser);

    // Deactivation is gated on member.remove, not member.update_role: freezing
    // somebody out is the same power as removing them.
    expect($viewer->can('changeStatus', $team['colleague']))->toBeFalse();
});
