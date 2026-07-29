<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Seller;
use App\Modules\Localization\Domain\Models\Country;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Organization\Application\Actions\ApproveStoreOpeningRequestAction;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Enums\OrganizationStatus;
use App\Modules\Organization\Domain\Enums\StoreOpeningRequestStatus;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource\Pages\CreateOrganization;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\StoreOpeningRequestResource;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\StoreOpeningRequestResource\Pages\CreateStoreOpeningRequest;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\StoreOpeningRequestResource\Pages\ListStoreOpeningRequests;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use App\Modules\Store\Presentation\Filament\Seller\Resources\StoreResource\Pages\ListStores;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Seller onboarding, reflowed (owner-approved)
|--------------------------------------------------------------------------
|
| A seller who registered a company and then had to go and find a second form
| had not finished onboarding — they had finished the paperwork. Registering IS
| asking to sell, so the store request comes with the company, and an ADDITIONAL
| store is asked for from "Mağazalarım" where a seller is already looking at
| their stores.
|
| ADR-028 IS THE THING NOT TO BREAK. A store is created only by an admin
| approving a request. Nothing here shortcuts that, and the last test walks the
| whole chain to prove it.
|
| Store names are unique platform-wide: a buyer told to "shop at Beko" must land
| at one shop.
|
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('seller'));
});

/**
 * @return array<string, mixed>
 */
function onboardingPayload(string $slug = 'raftabul', string $storeName = 'Raftabul Mağaza'): array
{
    return [
        'legal_name' => 'Raftabul Ticaret A.Ş.',
        'slug' => $slug,
        'country_code' => Country::query()->value('iso2'),
        'currency_code' => Currency::query()->value('code'),
        'store_name' => $storeName,
        'store_slug' => $slug.'-magaza',
    ];
}

it('creates the company and its store request in one step', function (): void {
    $seller = $this->actingAsSeller();

    Livewire::test(CreateOrganization::class)
        ->fillForm(onboardingPayload())
        ->call('create')
        ->assertHasNoFormErrors();

    $organization = Organization::query()->where('slug', 'raftabul')->sole();
    $request = StoreOpeningRequest::query()->where('organization_id', $organization->getKey())->sole();

    expect($request->store_name)->toBe('Raftabul Mağaza')
        ->and($request->requested_by)->toBe($seller->getKey())
        /*
         * A DRAFT, not pending — and this is the rule the reflow had to bend
         * around rather than break. §3.1: a company still pending its own KYC
         * cannot queue storefronts, and a freshly registered one is exactly
         * that. The seller sends it on from the status list once approved.
         */
        ->and($request->status)->toBe(StoreOpeningRequestStatus::Draft);

    // And no store has appeared. ADR-028 is untouched.
    expect(Store::query()->count())->toBe(0);
});

it('refuses to register a company with no store details', function (): void {
    $this->actingAsSeller();

    $payload = onboardingPayload();
    unset($payload['store_name'], $payload['store_slug']);

    // Required, because an organization with no store request is a company that
    // cannot do anything, and every seller who reached this form wants one.
    Livewire::test(CreateOrganization::class)
        ->fillForm($payload)
        ->call('create')
        ->assertHasFormErrors(['store_name', 'store_slug']);

    expect(Organization::query()->count())->toBe(0);
});

it('refuses a store name an existing store already trades under', function (): void {
    $this->actingAsSeller();
    Store::factory()->create(['name' => 'Beko Mağaza', 'status' => StoreStatus::Active]);

    Livewire::test(CreateOrganization::class)
        ->fillForm(onboardingPayload(storeName: 'Beko Mağaza'))
        ->call('create')
        ->assertHasFormErrors(['store_name']);
});

it('refuses a store name only differing in case', function (): void {
    $this->actingAsSeller();
    Store::factory()->create(['name' => 'Beko Magaza', 'status' => StoreStatus::Active]);

    // "Beko Magaza" and "beko magaza" are the same shop to a shopper, which is
    // why the index is on LOWER(name).
    Livewire::test(CreateOrganization::class)
        ->fillForm(onboardingPayload(storeName: 'beko magaza'))
        ->call('create')
        ->assertHasFormErrors(['store_name']);
});

it('refuses a store name another seller is already waiting on', function (): void {
    $this->actingAsSeller();

    $other = Organization::factory()->create();
    StoreOpeningRequest::factory()->for($other)->create([
        'store_name' => 'Beklemede Mağaza',
        'status' => StoreOpeningRequestStatus::Pending,
    ]);

    /*
     * Without this half, two sellers could each hold a pending request for one
     * name and the loser would only find out after the review — the worst
     * moment, because they have already waited.
     */
    Livewire::test(CreateOrganization::class)
        ->fillForm(onboardingPayload(storeName: 'Beklemede Mağaza'))
        ->call('create')
        ->assertHasFormErrors(['store_name']);
});

it('lets a withdrawn request’s name be claimed by somebody else', function (): void {
    $this->actingAsSeller();

    $other = Organization::factory()->create();
    StoreOpeningRequest::factory()->for($other)->create([
        'store_name' => 'Vazgeçildi',
        'status' => StoreOpeningRequestStatus::Cancelled,
    ]);

    // A cancelled request holds nothing — only draft and pending are in play.
    Livewire::test(CreateOrganization::class)
        ->fillForm(onboardingPayload(storeName: 'Vazgeçildi'))
        ->call('create')
        ->assertHasNoFormErrors();
});

/*
|--------------------------------------------------------------------------
| The relocated entry point
|--------------------------------------------------------------------------
*/

it('no longer advertises a new request from the request list', function (): void {
    $this->actingAsSeller();

    // The list became the STATUS view. A third entry point would mean three
    // forms to keep in step and a seller wondering which is the real one.
    Livewire::test(ListStoreOpeningRequests::class)
        ->assertActionDoesNotExist('create');
});

it('offers "Yeni Mağaza Talep Et" from Mağazalarım, pointing at the form', function (): void {
    $this->actingAsSeller();
    Filament::setCurrentPanel(Filament::getPanel('seller'));

    // The link is a ROUTE NAME because Store may not import Organization
    // (ADR-033) — so this test is the only thing that would notice a rename.
    expect(Route::has('filament.seller.resources.store-opening-requests.create'))->toBeTrue();

    Livewire::test(ListStores::class)->assertActionExists('request_store');
});

it('raises an additional request from the relocated form', function (): void {
    /** @var Seller $seller */
    $seller = Seller::factory()->create();
    $organization = Organization::factory()->create([
        'owner_id' => $seller->getKey(),
        'status' => OrganizationStatus::Approved,
    ]);
    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Owner)
        ->create(['user_id' => $seller->getKey()]);

    $this->actingAsSeller($seller);

    Livewire::test(CreateStoreOpeningRequest::class)
        ->fillForm([
            'organization_id' => $organization->getKey(),
            'store_name' => 'İkinci Mağaza',
            'slug' => 'ikinci-magaza',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    // A DRAFT, exactly as before: the reflow moved where a request is raised,
    // not what raising one means. Sending it on is still the deliberate second
    // step, from the status list.
    expect(StoreOpeningRequest::query()->where('store_name', 'İkinci Mağaza')->sole()->status)
        ->toBe(StoreOpeningRequestStatus::Draft);
});

/*
|--------------------------------------------------------------------------
| The chain ADR-028 protects, end to end
|--------------------------------------------------------------------------
*/

it('turns an approved request into a store the seller can see', function (): void {
    /** @var Seller $seller */
    $seller = Seller::factory()->create();
    $organization = Organization::factory()->create([
        'owner_id' => $seller->getKey(),
        'status' => OrganizationStatus::Approved,
    ]);
    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Owner)
        ->create(['user_id' => $seller->getKey()]);

    $request = StoreOpeningRequest::factory()->for($organization)->create([
        'store_name' => 'Onaylanacak Mağaza',
        'slug' => 'onaylanacak-magaza',
        'status' => StoreOpeningRequestStatus::Pending,
        'requested_by' => $seller->getKey(),
    ]);

    app(ApproveStoreOpeningRequestAction::class)->run($request, null, Admin::factory()->create());

    // The admin's approval is what creates the store — the listener consuming
    // StoreOpeningApproved (ADR-032). Nothing in the reflow touched that.
    $store = Store::query()->where('opening_request_uuid', $request->uuid)->sole();

    expect($store->name)->toBe('Onaylanacak Mağaza')
        ->and($store->organization_id)->toBe($organization->getKey());

    $this->actingAsSeller($seller);

    Livewire::test(ListStores::class)->assertCanSeeTableRecords([$store]);
});
