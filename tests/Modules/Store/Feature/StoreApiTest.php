<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Seller;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Routing\Middleware\ThrottleRequests;

/*
|--------------------------------------------------------------------------
| Store — seller & admin API (§12.2/12.3)
|--------------------------------------------------------------------------
|
| Seller endpoints are scoped + policy-gated through OrganizationAuthorizationContract;
| a member of another org is denied. Admin endpoints are platform-level only and
| gated by store.* permissions. All authorization lives in the policy/requests.
*/

beforeEach(function (): void {
    $this->seedAll();
    $this->withoutMiddleware(ThrottleRequests::class);
});

function sellerManaging(Organization $org): Seller
{
    $user = Seller::factory()->create();
    OrganizationMember::factory()->for($org)->role(OrganizationRole::Manager)->create(['user_id' => $user->getKey()]);

    return $user;
}

it('lists only stores of organizations the seller belongs to (ADR-030)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $manager = sellerManaging($orgA);
    Store::factory()->create(['organization_id' => $orgA->getKey(), 'name' => 'Mine']);
    Store::factory()->create(['organization_id' => $orgB->getKey(), 'name' => 'NotMine']);

    $this->actingAs($manager, 'seller');
    $names = collect($this->getJson('/api/v1/stores')->assertOk()->json('data'))->pluck('name');

    expect($names)->toContain('Mine')->not->toContain('NotMine');
});

it('lets a manager update and activate their own store', function (): void {
    $org = Organization::factory()->create();
    $manager = sellerManaging($org);
    $store = Store::factory()->create(['organization_id' => $org->getKey(), 'status' => StoreStatus::Draft]);

    $this->actingAs($manager, 'seller');

    $this->patchJson("/api/v1/stores/{$store->uuid}", ['name' => 'Renamed'])
        ->assertOk()->assertJsonPath('data.name', 'Renamed');
    $this->postJson("/api/v1/stores/{$store->uuid}/activate")
        ->assertOk()->assertJsonPath('data.status', 'active');
});

it('updates storefront settings for a manager', function (): void {
    $org = Organization::factory()->create();
    $manager = sellerManaging($org);
    $store = Store::factory()->create(['organization_id' => $org->getKey()]);

    $this->actingAs($manager, 'seller');
    $this->patchJson("/api/v1/stores/{$store->uuid}/settings", ['order_note_enabled' => true])->assertOk();

    expect($store->settings()->first()->order_note_enabled)->toBeTrue();
});

it('denies a seller acting on another organization\'s store', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $managerB = sellerManaging($orgB);
    $storeA = Store::factory()->create(['organization_id' => $orgA->getKey()]);

    $this->actingAs($managerB, 'seller');

    $this->getJson("/api/v1/stores/{$storeA->uuid}")->assertForbidden();
    $this->patchJson("/api/v1/stores/{$storeA->uuid}", ['name' => 'x'])->assertForbidden();
    $this->postJson("/api/v1/stores/{$storeA->uuid}/activate")->assertForbidden();
});

it('keeps the seller API off the public surface — no auth means no seller access', function (): void {
    $store = Store::factory()->active()->create();

    // Unauthenticated request to the seller endpoint is rejected, not served.
    $this->getJson("/api/v1/stores/{$store->uuid}")->assertUnauthorized();
});

it('lets an admin with permission suspend, reinstate and archive', function (): void {
    $store = Store::factory()->active()->create();
    $admin = $this->grant(
        Admin::factory()->create(),
        'store.view_any', 'store.view', 'store.suspend', 'store.reinstate', 'store.archive',
    );

    $this->actingAs($admin, 'admin');

    $this->postJson("/api/v1/admin/stores/{$store->uuid}/suspend", ['reason' => 'Policy breach'])
        ->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->postJson("/api/v1/admin/stores/{$store->uuid}/reinstate")
        ->assertOk()->assertJsonPath('data.status', 'active');

    // Archive requires a Closed/Suspended store; close via a fresh suspension.
    $this->postJson("/api/v1/admin/stores/{$store->uuid}/suspend", ['reason' => 'again'])->assertOk();
    $this->postJson("/api/v1/admin/stores/{$store->uuid}/archive")
        ->assertOk()->assertJsonPath('data.status', 'archived');
});

it('denies admin store enforcement without the permission', function (): void {
    $store = Store::factory()->active()->create();
    $admin = Admin::factory()->create(); // no store.* permissions granted

    $this->actingAs($admin, 'admin');
    $this->postJson("/api/v1/admin/stores/{$store->uuid}/suspend", ['reason' => 'x'])->assertForbidden();
});
