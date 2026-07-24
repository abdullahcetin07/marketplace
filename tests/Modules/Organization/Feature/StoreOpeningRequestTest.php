<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Seller;
use App\Modules\Audit\Domain\Models\AuditEntry;
use App\Modules\Organization\Application\Actions\ApproveStoreOpeningRequestAction;
use App\Modules\Organization\Application\Actions\CancelStoreOpeningRequestAction;
use App\Modules\Organization\Application\Actions\CreateStoreOpeningRequestAction;
use App\Modules\Organization\Application\Actions\RejectStoreOpeningRequestAction;
use App\Modules\Organization\Application\Actions\SubmitStoreOpeningRequestAction;
use App\Modules\Organization\Domain\DTOs\CreateStoreOpeningRequestDTO;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Enums\StoreOpeningRequestStatus;
use App\Modules\Organization\Domain\Events\StoreOpeningApproved;
use App\Modules\Organization\Domain\Events\StoreOpeningRejected;
use App\Modules\Organization\Domain\Events\StoreOpeningRequested;
use App\Modules\Organization\Domain\Exceptions\StoreOpeningException;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Store Opening Requests (Phase 5, ADR-028)
|--------------------------------------------------------------------------
|
| A Store is NEVER created from a seller action. It exists only after an admin
| approves a request — and even then this module only fires the event; the Store
| module creates the store. The store limit (override → plan → config) binds
| authoritatively at approval.
*/

beforeEach(fn () => $this->seedAll());

function draftRequest(Organization $org, ?Seller $seller = null): StoreOpeningRequest
{
    $seller ??= Seller::factory()->create();

    return app(CreateStoreOpeningRequestAction::class)->run(new CreateStoreOpeningRequestDTO(
        organizationId: $org->getKey(),
        requestedBy: $seller->getKey(),
        storeName: 'My Store',
        slug: 'my-store-'.$seller->getKey(),
    ));
}

it('creates a request as a draft', function (): void {
    $org = Organization::factory()->approved()->create();

    expect(draftRequest($org)->status)->toBe(StoreOpeningRequestStatus::Draft);
});

it('submits a draft into the queue and announces it — but creates no store', function (): void {
    $org = Organization::factory()->approved()->withStoreLimitOverride(5)->create();
    $request = draftRequest($org);
    Event::fake([StoreOpeningRequested::class, StoreOpeningApproved::class]);

    app(SubmitStoreOpeningRequestAction::class)->run($request);

    expect($request->fresh()->status)->toBe(StoreOpeningRequestStatus::Pending);
    Event::assertDispatched(StoreOpeningRequested::class);
    // The store-creation event fires only on approval, never on submission.
    Event::assertNotDispatched(StoreOpeningApproved::class);
});

it('refuses to submit for a non-operational organization', function (): void {
    $org = Organization::factory()->create(); // Pending — not operational
    $request = draftRequest($org);

    expect(fn () => app(SubmitStoreOpeningRequestAction::class)->run($request))
        ->toThrow(StoreOpeningException::class);
});

it('fails fast at submission when the store limit is already used', function (): void {
    $org = Organization::factory()->approved()->withStoreLimitOverride(1)->create();
    // The one slot is consumed by an already-approved request.
    StoreOpeningRequest::factory()->for($org)->approved()->create();
    $request = draftRequest($org);

    expect(fn () => app(SubmitStoreOpeningRequestAction::class)->run($request))
        ->toThrow(StoreOpeningException::class);
});

it('approves a pending request, consumes a slot, and creates no store here', function (): void {
    $org = Organization::factory()->approved()->withStoreLimitOverride(5)->create();
    $request = StoreOpeningRequest::factory()->for($org)->pending()->create();
    $admin = Admin::factory()->admin()->create();
    Event::fake([StoreOpeningApproved::class]);

    app(ApproveStoreOpeningRequestAction::class)->run($request, 'Looks good', $admin);

    $fresh = $request->fresh();
    expect($fresh->status)->toBe(StoreOpeningRequestStatus::Approved)
        ->and($fresh->reviewed_by)->toBe($admin->getKey())
        // Organization does not create the store — the Store module fills this.
        ->and($fresh->created_store_uuid)->toBeNull();

    // The event the Store module consumes, carrying the store details.
    Event::assertDispatched(
        StoreOpeningApproved::class,
        fn (StoreOpeningApproved $e): bool => $e->slug === $fresh->slug && $e->storeName === $fresh->store_name,
    );

    $entry = AuditEntry::query()->forModel($request)->latest('id')->first();
    expect($entry->metadata)->toBe(['reason' => 'Looks good']);
});

it('enforces the limit authoritatively at approval', function (): void {
    // Override 1: the resolution chain (override → plan → config) is preserved.
    $org = Organization::factory()->approved()->withStoreLimitOverride(1)->create();
    $admin = Admin::factory()->admin()->create();

    $first = StoreOpeningRequest::factory()->for($org)->pending()->create();
    app(ApproveStoreOpeningRequestAction::class)->run($first, null, $admin);

    // The one slot is now used; a second approval is refused.
    $second = StoreOpeningRequest::factory()->for($org)->pending()->create();
    expect(fn () => app(ApproveStoreOpeningRequestAction::class)->run($second, null, $admin))
        ->toThrow(StoreOpeningException::class);
});

it('rejects a pending request', function (): void {
    $org = Organization::factory()->approved()->create();
    $request = StoreOpeningRequest::factory()->for($org)->pending()->create();
    $admin = Admin::factory()->admin()->create();
    Event::fake([StoreOpeningRejected::class]);

    app(RejectStoreOpeningRequestAction::class)->run($request, 'Name conflicts with an existing store', $admin);

    expect($request->fresh()->status)->toBe(StoreOpeningRequestStatus::Rejected);
    Event::assertDispatched(StoreOpeningRejected::class);
});

it('cancels a draft request', function (): void {
    $org = Organization::factory()->approved()->create();
    $request = draftRequest($org);

    app(CancelStoreOpeningRequestAction::class)->run($request);

    expect($request->fresh()->status)->toBe(StoreOpeningRequestStatus::Cancelled);
});

it('gates approval on the admin permission and submission on the capability', function (): void {
    $org = Organization::factory()->approved()->create();
    $request = StoreOpeningRequest::factory()->for($org)->pending()->create();

    $reviewer = Admin::factory()->admin()->create();
    $supportOnly = Admin::factory()->support()->create();

    expect($reviewer->can('approve', $request))->toBeTrue()
        ->and($supportOnly->can('approve', $request))->toBeFalse();

    $manager = Seller::factory()->create();
    OrganizationMember::factory()->for($org)->role(OrganizationRole::Manager)
        ->create(['user_id' => $manager->getKey()]);
    $viewer = Seller::factory()->create();
    OrganizationMember::factory()->for($org)->role(OrganizationRole::Viewer)
        ->create(['user_id' => $viewer->getKey()]);

    $draft = draftRequest($org);
    expect($manager->can('submit', $draft))->toBeTrue()
        ->and($viewer->can('submit', $draft))->toBeFalse();
});
