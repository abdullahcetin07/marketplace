<?php

declare(strict_types=1);

use App\Models\Seller;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Events\OrganizationMemberInvited;
use App\Modules\Organization\Domain\Events\OrganizationMemberRemoved;
use App\Modules\Organization\Domain\Events\OrganizationMemberRoleChanged;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationInvitation;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\TeamInvitationResource\Pages\ListTeamInvitations;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\TeamMemberResource\Pages\ListTeamMembers;
use App\Shared\Enums\InvitationStatus;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Seller panel — Ekip (the seller's own team)
|--------------------------------------------------------------------------
|
| A merchant's team is the merchant's to manage: the admin panel's seller area
| deliberately offers no team controls at all, and this is the surface that
| does. Three things are worth pinning and nothing else is:
|
|  1. THE TENANCY WALL (ADR-030). A second seller can neither see nor touch the
|     first's members or invitations. This is the assertion that matters — a
|     failure here is a cross-tenant data leak, not a UI bug.
|  2. ORG ROLES ONLY. The roles offered are the Organization module's own,
|     Owner excluded (ADR-029). No platform staff role is reachable from this
|     panel.
|  3. Every write is the module Action the API uses, so the events and the audit
|     trail come out identical.
|
| The panel is set explicitly because a Livewire test has no panel middleware to
| do it.
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('seller'));
});

/**
 * A company with the given seller seated as its Owner — the membership row is
 * what the tenancy wall reads, so it is never skipped.
 */
function companyOwnedBy(Seller $seller, string $legalName = 'Raftabul Ticaret A.Ş.'): Organization
{
    $organization = Organization::factory()->create([
        'owner_id' => $seller->getKey(),
        'legal_name' => $legalName,
    ]);

    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Owner)
        ->create(['user_id' => $seller->getKey()]);

    return $organization;
}

/*
|--------------------------------------------------------------------------
| 1. The tenancy wall
|--------------------------------------------------------------------------
*/

it('shows a seller only their own company’s members', function (): void {
    $seller = $this->actingAsSeller();
    $mine = companyOwnedBy($seller);

    $colleague = OrganizationMember::factory()->for($mine)->role(OrganizationRole::Manager)
        ->create(['user_id' => Seller::factory()->create()->getKey()]);

    // Another merchant entirely, with their own team.
    $other = Organization::factory()->create(['legal_name' => 'Başka Şirket']);
    $stranger = OrganizationMember::factory()->for($other)->role(OrganizationRole::Manager)
        ->create(['user_id' => Seller::factory()->create()->getKey()]);

    Livewire::test(ListTeamMembers::class)
        ->assertCanSeeTableRecords([$colleague])
        ->assertCanNotSeeTableRecords([$stranger]);
});

it('denies a second seller any action on the first’s member', function (): void {
    $first = $this->actingAsSeller();
    $organization = companyOwnedBy($first);

    $member = OrganizationMember::factory()->for($organization)->role(OrganizationRole::Manager)
        ->create(['user_id' => Seller::factory()->create()->getKey()]);

    // A different merchant, with a company of their own, signs in.
    $second = $this->actingAsSeller();
    companyOwnedBy($second, 'İkinci Şirket');

    expect($second->can('view', $member))->toBeFalse()
        ->and($second->can('updateRole', $member))->toBeFalse()
        ->and($second->can('remove', $member))->toBeFalse();

    // And the record is not even in their listing's query.
    Livewire::test(ListTeamMembers::class)->assertCanNotSeeTableRecords([$member]);
});

it('shows a seller only their own company’s invitations', function (): void {
    $seller = $this->actingAsSeller();
    $mine = companyOwnedBy($seller);

    $ours = OrganizationInvitation::factory()->for($mine)->create(['email' => 'bizim@example.test']);
    $theirs = OrganizationInvitation::factory()->create(['email' => 'onlarin@example.test']);

    Livewire::test(ListTeamInvitations::class)
        ->assertCanSeeTableRecords([$ours])
        ->assertCanNotSeeTableRecords([$theirs]);
});

/*
|--------------------------------------------------------------------------
| 2. Inviting, re-roling, removing — through the module actions
|--------------------------------------------------------------------------
*/

it('invites a colleague through InviteMemberAction', function (): void {
    Notification::fake();
    Event::fake([OrganizationMemberInvited::class]);

    $seller = $this->actingAsSeller();
    $organization = companyOwnedBy($seller);

    Livewire::test(ListTeamMembers::class)
        ->callTableAction('invite', data: [
            'organization' => $organization->getKey(),
            'email' => 'Yeni.Calisan@Example.Test',
            'role' => OrganizationRole::Manager->value,
        ]);

    $invitation = OrganizationInvitation::query()
        ->where('organization_id', $organization->getKey())
        ->sole();

    expect($invitation->email)->toBe('yeni.calisan@example.test')
        ->and($invitation->role)->toBe(OrganizationRole::Manager)
        ->and($invitation->status)->toBe(InvitationStatus::Pending)
        ->and($invitation->invited_by)->toBe($seller->getKey())
        // Only the hash is ever stored (ADR-025/031).
        ->and($invitation->token_hash)->not->toBeEmpty();

    Event::assertDispatched(OrganizationMemberInvited::class);
});

it('changes a member’s org role through ChangeMemberRoleAction', function (): void {
    Event::fake([OrganizationMemberRoleChanged::class]);

    $seller = $this->actingAsSeller();
    $organization = companyOwnedBy($seller);

    $member = OrganizationMember::factory()->for($organization)->role(OrganizationRole::Viewer)
        ->create(['user_id' => Seller::factory()->create()->getKey()]);

    Livewire::test(ListTeamMembers::class)
        ->callTableAction('changeRole', $member, [
            'role' => OrganizationRole::Manager->value,
            'reason' => 'Terfi',
        ]);

    expect($member->fresh()->role)->toBe(OrganizationRole::Manager);

    Event::assertDispatched(OrganizationMemberRoleChanged::class);
});

it('removes a member through RemoveMemberAction', function (): void {
    Event::fake([OrganizationMemberRemoved::class]);

    $seller = $this->actingAsSeller();
    $organization = companyOwnedBy($seller);

    $member = OrganizationMember::factory()->for($organization)->role(OrganizationRole::Manager)
        ->create(['user_id' => Seller::factory()->create()->getKey()]);

    Livewire::test(ListTeamMembers::class)
        ->callTableAction('remove', $member, ['reason' => 'Ayrıldı']);

    // A soft delete: who removed whom survives as evidence.
    expect(OrganizationMember::query()->whereKey($member->getKey())->exists())->toBeFalse()
        ->and(OrganizationMember::withTrashed()->whereKey($member->getKey())->exists())->toBeTrue();

    Event::assertDispatched(OrganizationMemberRemoved::class);
});

it('withdraws a pending invitation', function (): void {
    $seller = $this->actingAsSeller();
    $organization = companyOwnedBy($seller);

    $invitation = OrganizationInvitation::factory()->for($organization)->create();

    Livewire::test(ListTeamInvitations::class)
        ->callTableAction('cancel', $invitation);

    expect($invitation->fresh()->status)->toBe(InvitationStatus::Cancelled);
});

it('re-sends an invitation with a fresh token, killing the old one', function (): void {
    Notification::fake();

    $seller = $this->actingAsSeller();
    $organization = companyOwnedBy($seller);

    $invitation = OrganizationInvitation::factory()->for($organization)->create();
    $originalHash = $invitation->token_hash;

    Livewire::test(ListTeamInvitations::class)
        ->callTableAction('resend', $invitation);

    expect($invitation->fresh()->token_hash)->not->toBe($originalHash);
});

/*
|--------------------------------------------------------------------------
| 3. Org roles only — never a platform staff role, never Owner
|--------------------------------------------------------------------------
*/

it('offers org roles only, with Owner excluded', function (): void {
    $offered = array_map(
        static fn (OrganizationRole $role): string => $role->value,
        OrganizationRole::assignable(),
    );

    expect($offered)->not->toContain(OrganizationRole::Owner->value);

    // Not one platform staff role name is reachable as an org role.
    $staffRoles = ['super_admin', 'admin', 'editor', 'category_manager', 'support', 'finance'];

    foreach ($staffRoles as $key) {
        expect($offered)->not->toContain(config("marketplace.roles.{$key}"));
    }
});

it('never offers the Owner’s row a role change or a removal', function (): void {
    $seller = $this->actingAsSeller();
    $organization = companyOwnedBy($seller);

    $owner = OrganizationMember::query()
        ->where('organization_id', $organization->getKey())
        ->where('user_id', $seller->getKey())
        ->sole();

    Livewire::test(ListTeamMembers::class)
        ->assertTableActionHidden('changeRole', $owner)
        ->assertTableActionHidden('remove', $owner);
});

it('hides team actions from a member whose role does not grant them', function (): void {
    $owner = $this->actingAsSeller();
    $organization = companyOwnedBy($owner);

    $colleague = OrganizationMember::factory()->for($organization)->role(OrganizationRole::Manager)
        ->create(['user_id' => Seller::factory()->create()->getKey()]);

    // A Viewer holds member.view and nothing else in the §5.1 matrix.
    $viewerUser = Seller::factory()->create();
    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Viewer)
        ->create(['user_id' => $viewerUser->getKey()]);

    $this->actingAsSeller($viewerUser);

    Livewire::test(ListTeamMembers::class)
        // They belong to the company, so they see the roster …
        ->assertCanSeeTableRecords([$colleague])
        // … and can change nothing on it.
        ->assertTableActionHidden('changeRole', $colleague)
        ->assertTableActionHidden('remove', $colleague);
});
