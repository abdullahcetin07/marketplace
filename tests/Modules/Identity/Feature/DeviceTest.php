<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Modules\Identity\Domain\Events\DeviceTrusted;
use App\Modules\Identity\Domain\Models\UserDevice;
use App\Modules\Identity\Domain\Models\UserSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Devices — Phase 5
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->seedPlatform();
    $this->seedRolesAndPermissions();
});

it('lists only the current user devices', function (): void {
    $me = Customer::factory()->create();
    $other = Customer::factory()->create();

    UserDevice::factory()->count(2)->create(['user_id' => $me->getKey()]);
    UserDevice::factory()->create(['user_id' => $other->getKey()]);

    $this->actingAsCustomer($me);

    $this->getJson('/api/v1/devices')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('never exposes the fingerprint', function (): void {
    $me = Customer::factory()->create();
    UserDevice::factory()->create([
        'user_id' => $me->getKey(),
        'fingerprint' => 'super-secret-fingerprint',
    ]);
    $this->actingAsCustomer($me);

    $response = $this->getJson('/api/v1/devices');

    expect(json_encode($response->json()))->not->toContain('super-secret-fingerprint')
        ->and(json_encode($response->json()))->not->toContain('fingerprint');
});

it('trusts a device', function (): void {
    Event::fake([DeviceTrusted::class]);
    $me = Customer::factory()->create();
    $device = UserDevice::factory()->create(['user_id' => $me->getKey()]);
    $this->actingAsCustomer($me);

    $this->postJson("/api/v1/devices/{$device->uuid}/trust")
        ->assertOk()
        ->assertJsonPath('data.trusted', true);

    expect($device->fresh()->is_trusted)->toBeTrue();
    Event::assertDispatched(DeviceTrusted::class);
});

it('reports an expired trust as untrusted', function (): void {
    $me = Customer::factory()->create();
    // Trusted longer ago than the trust window.
    $device = UserDevice::factory()->trustExpired()->create(['user_id' => $me->getKey()]);
    $this->actingAsCustomer($me);

    $this->getJson('/api/v1/devices')
        ->assertOk()
        // is_trusted is still true in the column, but isTrusted() applies the
        // window, so the API reports the honest answer.
        ->assertJsonPath('data.0.trusted', false);
});

it('cannot trust another user device', function (): void {
    $me = Customer::factory()->create();
    $other = Customer::factory()->create();
    $device = UserDevice::factory()->create(['user_id' => $other->getKey()]);
    $this->actingAsCustomer($me);

    $this->postJson("/api/v1/devices/{$device->uuid}/trust")->assertStatus(403);

    expect($device->fresh()->is_trusted)->toBeFalse();
});

it('cannot view another user device implicitly', function (): void {
    // The list is scoped; a foreign device simply is not in it.
    $me = Customer::factory()->create();
    $other = Customer::factory()->create();
    UserDevice::factory()->create(['user_id' => $other->getKey()]);
    $this->actingAsCustomer($me);

    $this->getJson('/api/v1/devices')->assertJsonCount(0, 'data');
});

/*
| FORGET — must end access, not just delete the row
*/

it('forgets a device and revokes its sessions', function (): void {
    $me = Customer::factory()->create();
    $device = UserDevice::factory()->create(['user_id' => $me->getKey()]);

    // A live cookie session on this device.
    $session = UserSession::factory()->create([
        'user_id' => $me->getKey(),
        'device_id' => $device->getKey(),
        'session_id' => 'framework-session-id',
    ]);
    DB::table('sessions')->insert([
        'id' => 'framework-session-id',
        'payload' => 'x',
        'last_activity' => now()->timestamp,
    ]);

    $this->actingAsCustomer($me);

    $this->deleteJson("/api/v1/devices/{$device->uuid}")->assertNoContent();

    // The device is gone, its session is revoked, and — the point — the
    // underlying framework session is destroyed, not merely marked.
    expect(UserDevice::query()->whereKey($device->getKey())->exists())->toBeFalse()
        ->and($session->fresh()->revoked_at)->not->toBeNull()
        ->and(DB::table('sessions')->where('id', 'framework-session-id')->exists())->toBeFalse();
});

it('cannot forget another user device', function (): void {
    $me = Customer::factory()->create();
    $other = Customer::factory()->create();
    $device = UserDevice::factory()->create(['user_id' => $other->getKey()]);
    $this->actingAsCustomer($me);

    $this->deleteJson("/api/v1/devices/{$device->uuid}")->assertStatus(403);

    expect(UserDevice::query()->whereKey($device->getKey())->exists())->toBeTrue();
});

it('requires authentication for every device route', function (): void {
    $device = UserDevice::factory()->create();

    $this->getJson('/api/v1/devices')->assertStatus(401);
    $this->postJson("/api/v1/devices/{$device->uuid}/trust")->assertStatus(401);
    $this->deleteJson("/api/v1/devices/{$device->uuid}")->assertStatus(401);
});
