<?php

declare(strict_types=1);

use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Enums\OrganizationStatus;
use App\Modules\Organization\Domain\Enums\StoreOpeningRequestStatus;
use App\Modules\Organization\Domain\Events\StoreOpeningRequested;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\StoreOpeningRequestResource\Pages\CreateStoreOpeningRequest;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\StoreOpeningRequestResource\Pages\ListStoreOpeningRequests;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Seller panel — store opening requests (ADR-028)
|--------------------------------------------------------------------------
|
| The core of onboarding, and the place where a shortcut would do the most
| damage: NO STORE IS CREATED HERE. A seller drafts, submits into the admin
| queue, and only an admin's approval fires StoreOpeningApproved for the Store
| module to act on. These tests pin that boundary as much as they pin the happy
| path.
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('seller'));
});

/**
 * An APPROVED company the seller owns. Approval matters: SubmitStoreOpeningRequest
 * refuses a company that is not operational, so a pending one cannot open stores.
 */
function requestingOrganization(App\Models\Seller $seller, OrganizationStatus $status = OrganizationStatus::Approved): Organization
{
    $organization = Organization::factory()->create([
        'owner_id' => $seller->getKey(),
        'status' => $status,
    ]);

    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Owner)
        ->create(['user_id' => $seller->getKey()]);

    return $organization;
}

it('drafts a request without creating a store', function (): void {
    $seller = $this->actingAsSeller();
    $organization = requestingOrganization($seller);
    Event::fake([StoreOpeningRequested::class]);

    Livewire::test(CreateStoreOpeningRequest::class)
        ->fillForm([
            'organization_id' => $organization->getKey(),
            'store_name' => 'Raftabul Mağazası',
            'slug' => 'raftabul-magazasi',
            'description' => 'El yapımı ev tekstili.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $request = StoreOpeningRequest::query()->where('organization_id', $organization->getKey())->sole();

    expect($request->store_name)->toBe('Raftabul Mağazası')
        ->and($request->slug)->toBe('raftabul-magazasi')
        ->and($request->requested_by)->toBe($seller->getKey())
        // A draft: composing it is not submitting it.
        ->and($request->status)->toBe(StoreOpeningRequestStatus::Draft)
        ->and($request->submitted_at)->toBeNull();

    // Drafting announces nothing — the admin queue has not been touched.
    Event::assertNotDispatched(StoreOpeningRequested::class);
});

it('submits a draft into the admin review queue', function (): void {
    $seller = $this->actingAsSeller();
    $organization = requestingOrganization($seller);
    $request = StoreOpeningRequest::factory()->for($organization)->create([
        'status' => StoreOpeningRequestStatus::Draft,
        'requested_by' => $seller->getKey(),
    ]);

    Event::fake([StoreOpeningRequested::class]);

    Livewire::test(ListStoreOpeningRequests::class)
        ->callTableAction('submit', $request)
        ->assertHasNoTableActionErrors();

    expect($request->fresh()->status)->toBe(StoreOpeningRequestStatus::Pending)
        ->and($request->fresh()->submitted_at)->not->toBeNull();

    Event::assertDispatched(StoreOpeningRequested::class);
});

it('creates no store when a request is submitted — approval does that', function (): void {
    $seller = $this->actingAsSeller();
    $organization = requestingOrganization($seller);
    $request = StoreOpeningRequest::factory()->for($organization)->create([
        'status' => StoreOpeningRequestStatus::Draft,
        'requested_by' => $seller->getKey(),
    ]);

    Livewire::test(ListStoreOpeningRequests::class)
        ->callTableAction('submit', $request)
        ->assertHasNoTableActionErrors();

    // The whole point of ADR-028: the seller panel never creates a Store.
    expect(App\Modules\Store\Domain\Models\Store::query()->count())->toBe(0);
});

it('refuses to submit for a company that is not yet approved', function (): void {
    $seller = $this->actingAsSeller();
    $organization = requestingOrganization($seller, OrganizationStatus::Pending);
    $request = StoreOpeningRequest::factory()->for($organization)->create([
        'status' => StoreOpeningRequestStatus::Draft,
        'requested_by' => $seller->getKey(),
    ]);

    Event::fake([StoreOpeningRequested::class]);

    // An expected domain refusal, surfaced as a notification rather than a 500.
    Livewire::test(ListStoreOpeningRequests::class)
        ->callTableAction('submit', $request)
        ->assertNotified();

    expect($request->fresh()->status)->toBe(StoreOpeningRequestStatus::Draft);
    Event::assertNotDispatched(StoreOpeningRequested::class);
});

it('refuses to submit when the store allowance is used up', function (): void {
    $seller = $this->actingAsSeller();
    $organization = requestingOrganization($seller);
    $organization->forceFill(['store_limit_override' => 0])->save();

    $request = StoreOpeningRequest::factory()->for($organization)->create([
        'status' => StoreOpeningRequestStatus::Draft,
        'requested_by' => $seller->getKey(),
    ]);

    Livewire::test(ListStoreOpeningRequests::class)
        ->callTableAction('submit', $request)
        ->assertNotified();

    expect($request->fresh()->status)->toBe(StoreOpeningRequestStatus::Draft);
});

it('withdraws a pending request', function (): void {
    $seller = $this->actingAsSeller();
    $organization = requestingOrganization($seller);
    $request = StoreOpeningRequest::factory()->for($organization)->create([
        'status' => StoreOpeningRequestStatus::Pending,
        'requested_by' => $seller->getKey(),
    ]);

    Livewire::test(ListStoreOpeningRequests::class)
        ->callTableAction('cancel', $request)
        ->assertHasNoTableActionErrors();

    expect($request->fresh()->status)->toBe(StoreOpeningRequestStatus::Cancelled);
});

it('offers neither submit nor withdraw on a decided request', function (): void {
    $seller = $this->actingAsSeller();
    $organization = requestingOrganization($seller);
    $approved = StoreOpeningRequest::factory()->for($organization)->create([
        'status' => StoreOpeningRequestStatus::Approved,
        'requested_by' => $seller->getKey(),
    ]);

    // Approved is terminal on this surface — the Store now exists elsewhere.
    Livewire::test(ListStoreOpeningRequests::class)
        ->assertTableActionHidden('submit', $approved)
        ->assertTableActionHidden('cancel', $approved);
});

it('lists only requests from the seller\'s own companies', function (): void {
    $seller = $this->actingAsSeller();
    $mine = requestingOrganization($seller);
    $myRequest = StoreOpeningRequest::factory()->for($mine)->create(['requested_by' => $seller->getKey()]);

    $theirs = Organization::factory()->create();
    $theirRequest = StoreOpeningRequest::factory()->for($theirs)->create();

    Livewire::test(ListStoreOpeningRequests::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$myRequest])
        ->assertCanNotSeeTableRecords([$theirRequest]);
});

it('cannot act on another company\'s request', function (): void {
    $outsider = $this->actingAsSeller();
    requestingOrganization($outsider);

    $theirs = Organization::factory()->create();
    $theirRequest = StoreOpeningRequest::factory()->for($theirs)->create([
        'status' => StoreOpeningRequestStatus::Draft,
    ]);

    expect($outsider->can('submit', $theirRequest))->toBeFalse()
        ->and($outsider->can('cancel', $theirRequest))->toBeFalse();

    // Outside getEloquentQuery(), so the row is not even addressable.
    Livewire::test(ListStoreOpeningRequests::class)
        ->assertCanNotSeeTableRecords([$theirRequest]);

    expect($theirRequest->fresh()->status)->toBe(StoreOpeningRequestStatus::Draft);
});

it('rejects a request drafted against a company the seller may not act for', function (): void {
    $seller = $this->actingAsSeller();
    requestingOrganization($seller);

    // A company the seller is a Viewer of: visible, but no createStoreRequest.
    $viewerOrg = Organization::factory()->create(['status' => OrganizationStatus::Approved]);
    OrganizationMember::factory()->for($viewerOrg)->role(OrganizationRole::Viewer)
        ->create(['user_id' => $seller->getKey()]);

    expect($seller->can('createStoreRequest', $viewerOrg))->toBeFalse();

    // The select never offers it; posting the id anyway must still be refused.
    Livewire::test(CreateStoreOpeningRequest::class)
        ->fillForm([
            'organization_id' => $viewerOrg->getKey(),
            'store_name' => 'İzinsiz Mağaza',
            'slug' => 'izinsiz-magaza',
        ])
        ->call('create')
        ->assertHasFormErrors(['organization_id']);

    expect(StoreOpeningRequest::query()->where('organization_id', $viewerOrg->getKey())->exists())
        ->toBeFalse();
});
