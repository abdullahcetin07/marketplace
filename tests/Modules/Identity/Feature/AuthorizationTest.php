<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Customer;
use App\Modules\Audit\Domain\Enums\AuditEventType;
use App\Modules\Audit\Domain\Enums\AuditSeverity;
use App\Modules\Audit\Domain\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Admin surface authorization (Phase 8)
|--------------------------------------------------------------------------
|
| These are security properties: every admin endpoint is gated on a specific
| permission, the privilege-escalation guard protects super-admins, and account
| changes leave a forensic record. A failure here is an authorization hole, not
| a feature regression — read the assertion before "fixing" it.
|
| Role → permission mapping is the seeder's (RolePermissionSeeder): the `admin`
| role holds user.view_any/view/update/reset_password/view_login_history but NOT
| disable_two_factor; `support` holds reset_password + view_login_history but not
| update; `super_admin` bypasses every policy.
*/

beforeEach(function (): void {
    $this->seedAll();
});

it('lets an admin list users but denies a customer', function (): void {
    Customer::factory()->count(2)->create();

    $this->actingAsAdmin(Admin::factory()->admin()->create());
    $this->getJson('/api/v1/admin/users')->assertOk();

    $this->actingAsCustomer();
    $this->getJson('/api/v1/admin/users')->assertForbidden();
});

it('shows a single user to a permitted admin', function (): void {
    $target = Customer::factory()->create();
    $this->actingAsAdmin(Admin::factory()->admin()->create());

    $this->getJson("/api/v1/admin/users/{$target->uuid}")
        ->assertOk()
        ->assertJsonPath('data.id', $target->uuid);
});

it('updates an account and records the change with the admin reason', function (): void {
    $target = Customer::factory()->create(['first_name' => 'Old']);
    $this->actingAsAdmin(Admin::factory()->admin()->create());

    $this->patchJson("/api/v1/admin/users/{$target->uuid}", [
        'first_name' => 'New',
        'reason' => 'Name correction ticket #42',
    ])->assertOk()->assertJsonPath('data.first_name', 'New');

    // latest: the factory create wrote a model_created entry first; we want the
    // update.
    $entry = AuditEntry::query()->forModel($target->fresh())->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->event_type)->toBe(AuditEventType::ModelUpdated)
        ->and($entry->metadata)->toBe(['reason' => 'Name correction ticket #42'])
        // Concrete subclass, not the base User — same morph as a self-service edit.
        ->and($entry->auditable_type)->toBe(Customer::class);
});

it('forbids a non-super-admin from modifying a super-admin', function (): void {
    $superAdmin = Admin::factory()->superAdmin()->create();
    $this->actingAsAdmin(Admin::factory()->admin()->create());

    $this->patchJson("/api/v1/admin/users/{$superAdmin->uuid}", ['first_name' => 'Hijack'])
        ->assertForbidden();
});

it('denies disabling 2FA without the dedicated permission', function (): void {
    $target = Customer::factory()->withTwoFactor()->create();

    // The plain `admin` role is deliberately NOT granted user.disable_two_factor.
    $this->actingAsAdmin(Admin::factory()->admin()->create());

    $this->deleteJson("/api/v1/admin/users/{$target->uuid}/two-factor")
        ->assertForbidden();
});

it('lets a super-admin disable 2FA and writes a high-severity forensic entry', function (): void {
    $target = Customer::factory()->withTwoFactor()->create();
    $this->actingAsAdmin(Admin::factory()->superAdmin()->create());

    $this->deleteJson("/api/v1/admin/users/{$target->uuid}/two-factor", [
        'reason' => 'Lost device, verified by phone',
    ])->assertOk();

    $entry = AuditEntry::query()->security()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->event_type)->toBe(AuditEventType::SecurityTwoFactorDisabled)
        ->and($entry->severity)->toBe(AuditSeverity::High)
        ->and($entry->metadata)->toMatchArray([
            'reason' => 'Lost device, verified by phone',
            'by_administrator' => true,
        ])
        ->and($entry->auditable_type)->toBe(Customer::class);

    expect($target->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('lets support trigger a password reset but denies an editor', function (): void {
    $target = Customer::factory()->create();
    Notification::fake();

    $this->actingAsAdmin(Admin::factory()->support()->create());
    $this->postJson("/api/v1/admin/users/{$target->uuid}/reset-password")->assertOk();
    Notification::assertSentTo($target, ResetPasswordNotification::class);

    $this->actingAsAdmin(Admin::factory()->editor()->create());
    $this->postJson("/api/v1/admin/users/{$target->uuid}/reset-password")->assertForbidden();
});

it('records an admin-triggered reset as a forensic issued event with the reason and actor', function (): void {
    $target = Customer::factory()->create();
    $support = Admin::factory()->support()->create();
    Notification::fake();

    $this->actingAsAdmin($support);
    $this->postJson("/api/v1/admin/users/{$target->uuid}/reset-password", [
        'reason' => 'User called, verified identity',
    ])->assertOk();

    $entry = AuditEntry::query()
        ->where('event_type', AuditEventType::SecurityPasswordResetIssued->value)
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->severity)->toBe(AuditSeverity::Notice)
        ->and($entry->metadata['reason'] ?? null)->toBe('User called, verified identity')
        // Actor is the operator who triggered it; target is the account.
        ->and($entry->causer_id)->toBe($support->getKey())
        ->and($entry->auditable_type)->toBe(Customer::class)
        ->and($entry->auditable_id)->toBe($target->getKey());
});

it('gates login history on its permission', function (): void {
    $target = Customer::factory()->create();

    // Support holds user.view_login_history.
    $this->actingAsAdmin(Admin::factory()->support()->create());
    $this->getJson("/api/v1/admin/users/{$target->uuid}/login-history")->assertOk();

    // A customer holds none of the admin abilities.
    $this->actingAsCustomer();
    $this->getJson("/api/v1/admin/users/{$target->uuid}/login-history")->assertForbidden();
});
