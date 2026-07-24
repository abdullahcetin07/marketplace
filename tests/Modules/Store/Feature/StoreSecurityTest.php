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
| Store — security guarantees (the contract in one place)
|--------------------------------------------------------------------------
|
| Overlaps the feature suites on purpose (ADR-030/033/034/036). This file is the
| Store security contract: public allow-list, existence non-disclosure, tenant
| isolation, admin-permission gating.
*/

beforeEach(function (): void {
    $this->seedAll();
    $this->withoutMiddleware(ThrottleRequests::class);
});

it('never exposes an internal id or private field on the public storefront (ADR-034)', function (): void {
    $store = Store::factory()->active()->create(['slug' => 'public-store']);

    $data = $this->getJson('/api/v1/store/public-store')->assertOk()->json('data');

    expect($data['id'])->toBe($store->uuid)
        ->and($data)->not->toHaveKey('organization_id')
        ->and($data)->not->toHaveKey('opening_request_uuid')
        ->and($data)->not->toHaveKey('settings')
        ->and($data)->not->toHaveKey('status')
        // The internal integer id must never appear anywhere in the payload.
        ->and((string) json_encode($data))->not->toContain('"'.$store->getKey().'"');
});

it('does not disclose that a non-active store exists (ADR-034)', function (): void {
    Store::factory()->create(['slug' => 'hidden', 'status' => StoreStatus::Draft]);

    $this->getJson('/api/v1/store/hidden')->assertNotFound();
});

it('isolates one organization\'s store from another org\'s member (ADR-030)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $managerB = Seller::factory()->create();
    OrganizationMember::factory()->for($orgB)->role(OrganizationRole::Manager)->create(['user_id' => $managerB->getKey()]);
    $storeA = Store::factory()->create(['organization_id' => $orgA->getKey()]);

    $this->actingAs($managerB, 'seller');
    $this->getJson("/api/v1/stores/{$storeA->uuid}")->assertForbidden();
    $this->patchJson("/api/v1/stores/{$storeA->uuid}", ['name' => 'x'])->assertForbidden();
});

it('gates admin enforcement on the store.* permission, not mere admin status', function (): void {
    $store = Store::factory()->active()->create();
    $admin = Admin::factory()->create(); // no store.* permissions

    $this->actingAs($admin, 'admin');
    $this->postJson("/api/v1/admin/stores/{$store->uuid}/suspend", ['reason' => 'x'])->assertForbidden();
});
