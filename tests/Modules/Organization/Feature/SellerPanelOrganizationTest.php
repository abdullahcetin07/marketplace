<?php

declare(strict_types=1);

use App\Models\Seller;
use App\Modules\Localization\Domain\Models\Country;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Organization\Domain\Enums\OrganizationMemberStatus;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Enums\OrganizationStatus;
use App\Modules\Organization\Domain\Events\OrganizationCreated;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource\Pages\CreateOrganization;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource\Pages\EditOrganization;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource\Pages\ListOrganizations;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Seller panel — registering and editing a company
|--------------------------------------------------------------------------
|
| The Filament screens are presentation only: create delegates to
| RegisterOrganizationAction and edit to UpdateOrganizationAction, so the owner
| membership, the events and the audit trail come out identical to the API's.
| The list is the tenancy wall (ADR-030) — a seller sees their own companies and
| no one else's.
|
| The panel is set explicitly because a Livewire test has no panel middleware to
| do it.
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('seller'));
});

it('registers a company through the action, seating the seller as owner', function (): void {
    $seller = $this->actingAsSeller();
    Event::fake([OrganizationCreated::class]);

    Livewire::test(CreateOrganization::class)
        ->fillForm([
            'legal_name' => 'Raftabul Ticaret A.Ş.',
            'display_name' => 'Raftabul',
            'slug' => 'raftabul-ticaret',
            'country_code' => Country::query()->value('iso2'),
            'currency_code' => Currency::query()->value('code'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $organization = Organization::query()->where('slug', 'raftabul-ticaret')->sole();

    expect($organization->legal_name)->toBe('Raftabul Ticaret A.Ş.')
        ->and($organization->display_name)->toBe('Raftabul')
        ->and($organization->owner_id)->toBe($seller->getKey())
        // Pending until an admin approves it — the panel cannot shortcut that.
        ->and($organization->status)->toBe(OrganizationStatus::Pending)
        ->and($organization->country_id)->not->toBeNull()
        ->and($organization->currency_id)->not->toBeNull();

    // The owner membership row is what RegisterOrganizationAction adds and a
    // plain Filament create would not.
    $membership = OrganizationMember::query()
        ->where('organization_id', $organization->getKey())
        ->where('user_id', $seller->getKey())
        ->sole();

    expect($membership->role)->toBe(OrganizationRole::Owner)
        ->and($membership->status)->toBe(OrganizationMemberStatus::Active);

    Event::assertDispatched(OrganizationCreated::class);
});

it('refuses a slug already taken by another company', function (): void {
    Organization::factory()->create(['slug' => 'zaten-var']);
    $this->actingAsSeller();

    Livewire::test(CreateOrganization::class)
        ->fillForm([
            'legal_name' => 'İkinci Şirket',
            'slug' => 'zaten-var',
            'country_code' => Country::query()->value('iso2'),
            'currency_code' => Currency::query()->value('code'),
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);

    expect(Organization::query()->where('slug', 'zaten-var')->count())->toBe(1);
});

it('edits the profile through the update action', function (): void {
    $seller = $this->actingAsSeller();
    $organization = Organization::factory()->create(['owner_id' => $seller->getKey()]);
    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Owner)
        ->create(['user_id' => $seller->getKey()]);

    Livewire::test(EditOrganization::class, ['record' => $organization->getRouteKey()])
        ->fillForm([
            'legal_name' => 'Yeni Yasal Ad A.Ş.',
            'display_name' => 'Yeni Ad',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($organization->fresh()->legal_name)->toBe('Yeni Yasal Ad A.Ş.')
        ->and($organization->fresh()->display_name)->toBe('Yeni Ad');
});

it('lists only the companies the seller belongs to', function (): void {
    $seller = $this->actingAsSeller();
    $mine = Organization::factory()->create(['owner_id' => $seller->getKey(), 'legal_name' => 'Benim Şirketim']);
    OrganizationMember::factory()->for($mine)->role(OrganizationRole::Owner)
        ->create(['user_id' => $seller->getKey()]);

    $theirs = Organization::factory()->create(['legal_name' => 'Başkasının Şirketi']);

    Livewire::test(ListOrganizations::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('denies a seller from another organization access to the edit screen', function (): void {
    $outsider = $this->actingAsSeller();
    $theirs = Organization::factory()->create();

    // Not a member: the record is outside getEloquentQuery(), so the page
    // cannot even resolve it — the tenancy wall, not a hidden button. The
    // record binding fails before any policy is consulted.
    expect(fn () => Livewire::test(EditOrganization::class, ['record' => $theirs->getRouteKey()]))
        ->toThrow(ModelNotFoundException::class);

    expect($outsider->can('update', $theirs))->toBeFalse();
});

it('denies editing to a member who lacks the update capability', function (): void {
    $viewer = $this->actingAsSeller();
    $organization = Organization::factory()->create();
    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Viewer)
        ->create(['user_id' => $viewer->getKey()]);

    // A Viewer may read the company but not change it (§5.1 matrix).
    expect($viewer->can('update', $organization))->toBeFalse();

    Livewire::test(EditOrganization::class, ['record' => $organization->getRouteKey()])
        ->assertForbidden();
});
