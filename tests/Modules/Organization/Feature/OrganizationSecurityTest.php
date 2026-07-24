<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Seller;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Organization\Application\Actions\SubmitStoreOpeningRequestAction;
use App\Modules\Organization\Application\Actions\UpsertBankAccountAction;
use App\Modules\Organization\Domain\DTOs\UpsertBankAccountDTO;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Events\StoreOpeningApproved;
use App\Modules\Organization\Domain\Events\StoreOpeningRequested;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationInvitation;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Organization — security guarantees (Phase 8)
|--------------------------------------------------------------------------
|
| A focused checklist of the invariants ADR-028/030/031 promise. Overlaps with
| the feature suites on purpose — this file is the security contract in one place.
*/

beforeEach(function (): void {
    $this->seedAll();
    $this->withoutMiddleware(ThrottleRequests::class);
});

it('never exposes the full IBAN — only the masked form (ADR bank secrecy)', function (): void {
    $org = Organization::factory()->create();
    $finance = Seller::factory()->create();
    OrganizationMember::factory()->for($org)->role(OrganizationRole::Finance)->create(['user_id' => $finance->getKey()]);

    app(UpsertBankAccountAction::class)->run(new UpsertBankAccountDTO(
        organizationId: $org->getKey(),
        accountHolder: 'Acme',
        iban: 'TR330006100519786457841326',
        bankName: 'Bank',
        currencyCode: (string) Currency::query()->value('code'),
    ));

    $this->actingAs($finance, 'seller');
    $response = $this->getJson("/api/v1/organizations/{$org->uuid}/bank-account");

    $response->assertOk();
    expect((string) json_encode($response->json()))->not->toContain('TR330006100519786457841326');
});

it('never exposes an invitation token or hash (ADR-031)', function (): void {
    $org = Organization::factory()->create();
    $manager = Seller::factory()->create();
    OrganizationMember::factory()->for($org)->role(OrganizationRole::Manager)->create(['user_id' => $manager->getKey()]);
    $invitation = OrganizationInvitation::factory()->for($org)->create();

    $this->actingAs($manager, 'seller');
    // The invitation is returned by the invite endpoint; the resource must never
    // carry the hash.
    $payload = new App\Modules\Organization\Presentation\Resources\OrganizationInvitationResource($invitation);
    $array = $payload->toArray(request());

    expect($array)->not->toHaveKey('token_hash')
        ->and($array)->not->toHaveKey('token');
});

it('isolates one organization from another (ADR-030)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    // A manager of A, with full capabilities in A.
    $managerA = Seller::factory()->create();
    OrganizationMember::factory()->for($orgA)->role(OrganizationRole::Manager)->create(['user_id' => $managerA->getKey()]);

    $this->actingAs($managerA, 'seller');

    // …cannot list B's members, view B, or touch B's bank account.
    $this->getJson("/api/v1/organizations/{$orgB->uuid}/members")->assertForbidden();
    $this->getJson("/api/v1/organizations/{$orgB->uuid}")->assertForbidden();
    $this->getJson("/api/v1/organizations/{$orgB->uuid}/bank-account")->assertForbidden();
});

it('creates no store from any seller action — only approval fires the store event (ADR-028)', function (): void {
    $org = Organization::factory()->approved()->withStoreLimitOverride(5)->create();
    $request = StoreOpeningRequest::factory()->for($org)->create(); // Draft

    Event::fake([StoreOpeningRequested::class, StoreOpeningApproved::class]);

    // The seller's most advanced action — submitting — announces the request but
    // never the store-creation event.
    app(SubmitStoreOpeningRequestAction::class)->run($request);

    Event::assertDispatched(StoreOpeningRequested::class);
    Event::assertNotDispatched(StoreOpeningApproved::class);
});

it('refuses admin store-request approval over the limit (ADR-028 authoritative gate)', function (): void {
    $org = Organization::factory()->approved()->withStoreLimitOverride(1)->create();
    StoreOpeningRequest::factory()->for($org)->approved()->create(); // slot used
    $request = StoreOpeningRequest::factory()->for($org)->pending()->create();
    $admin = Admin::factory()->admin()->create();

    $this->actingAsAdmin($admin);
    $this->postJson("/api/v1/admin/store-requests/{$request->uuid}/approve", ['notes' => 'x'])
        ->assertStatus(422);
});
