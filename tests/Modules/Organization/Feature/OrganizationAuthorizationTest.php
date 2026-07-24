<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Seller;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use Illuminate\Routing\Middleware\ThrottleRequests;

/*
|--------------------------------------------------------------------------
| Organization API authorization + isolation (Phase 6)
|--------------------------------------------------------------------------
|
| These are security properties: membership scopes every seller endpoint
| (ADR-030), and the admin surface is permission-gated. A failure here is a
| cross-tenant leak or a privilege hole, not a feature bug.
*/

beforeEach(function (): void {
    $this->seedAll();
    $this->withoutMiddleware(ThrottleRequests::class);
});

it('lets a seller register an organization and view their own', function (): void {
    $seller = Seller::factory()->create();
    $this->actingAs($seller, 'seller');

    $response = $this->postJson('/api/v1/organizations', [
        'legal_name' => 'Acme Trading Ltd',
        'slug' => 'acme-trading',
        'country_code' => (string) \App\Modules\Localization\Domain\Models\Country::query()->value('iso2'),
        'currency_code' => (string) \App\Modules\Localization\Domain\Models\Currency::query()->value('code'),
    ]);

    $response->assertCreated()->assertJsonPath('data.slug', 'acme-trading');

    $uuid = $response->json('data.id');
    $this->getJson("/api/v1/organizations/{$uuid}")->assertOk();
});

it('denies a seller viewing an organization they do not belong to (ADR-030)', function (): void {
    $other = Organization::factory()->create();
    $outsider = Seller::factory()->create();
    $this->actingAs($outsider, 'seller');

    $this->getJson("/api/v1/organizations/{$other->uuid}")->assertForbidden();
});

it('gates member invitations on the capability', function (): void {
    $org = Organization::factory()->approved()->create();

    $manager = Seller::factory()->create();
    OrganizationMember::factory()->for($org)->role(OrganizationRole::Manager)->create(['user_id' => $manager->getKey()]);
    $viewer = Seller::factory()->create();
    OrganizationMember::factory()->for($org)->role(OrganizationRole::Viewer)->create(['user_id' => $viewer->getKey()]);

    $payload = ['email' => 'invitee@example.test', 'role' => OrganizationRole::Support->value];

    $this->actingAs($manager, 'seller');
    $this->postJson("/api/v1/organizations/{$org->uuid}/invitations", $payload)->assertCreated();

    $this->actingAs($viewer, 'seller');
    $this->postJson("/api/v1/organizations/{$org->uuid}/invitations", [
        'email' => 'another@example.test', 'role' => OrganizationRole::Support->value,
    ])->assertForbidden();
});

it('lets an admin list and approve organizations but denies a seller', function (): void {
    $org = Organization::factory()->create(); // Pending

    $this->actingAsAdmin(Admin::factory()->admin()->create());
    $this->getJson('/api/v1/admin/organizations')->assertOk();
    $this->postJson("/api/v1/admin/organizations/{$org->uuid}/approve", ['reason' => 'KYC verified'])->assertOk();

    expect($org->fresh()->status->value)->toBe('approved');

    // A seller cannot reach the admin surface.
    $this->actingAs(Seller::factory()->create(), 'seller');
    $this->getJson('/api/v1/admin/organizations')->assertForbidden();
    $this->postJson("/api/v1/admin/organizations/{$org->uuid}/reject")->assertForbidden();
});

it('gates store-request approval on the admin permission', function (): void {
    $org = Organization::factory()->approved()->create();
    $request = \App\Modules\Organization\Domain\Models\StoreOpeningRequest::factory()->for($org)->pending()->create();

    // Support admin lacks store_request.approve.
    $this->actingAsAdmin(Admin::factory()->support()->create());
    $this->postJson("/api/v1/admin/store-requests/{$request->uuid}/approve")->assertForbidden();

    // A full admin holds it.
    $this->actingAsAdmin(Admin::factory()->admin()->create());
    $this->postJson("/api/v1/admin/store-requests/{$request->uuid}/approve", ['notes' => 'ok'])->assertOk();
});
