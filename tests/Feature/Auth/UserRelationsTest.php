<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Seller;
use App\Modules\Activity\Domain\Models\ActivityEntry;
use App\Modules\Audit\Domain\Models\AuditEntry;
use App\Modules\Identity\Domain\Models\LoginAttempt;
use App\Modules\Identity\Domain\Models\UserDevice;
use App\Modules\Identity\Domain\Models\UserSession;
use App\Modules\Notification\Domain\Models\NotificationPreference;

/*
|--------------------------------------------------------------------------
| User relations under single-table inheritance
|--------------------------------------------------------------------------
|
| Every relation on User is declared once and inherited by Admin, Seller and
| Customer. Eloquent derives a HasMany's foreign key from the CLASS it is
| called on, so `$seller->sessions()` looked for `user_sessions.seller_id` — a
| column that does not exist and never will: there is one `users` table, and
| everything that points at a row in it uses `user_id`.
|
| Nothing in the app called these relations, so nothing failed and the bug sat
| in a public API that User's own docblock advertises. These tests call each
| one from each subclass, which is the only thing that would have caught it.
|
| @see App\Models\User::getForeignKey()
*/

it('resolves a user’s sessions, whichever subclass asks', function (string $model): void {
    $user = $model::factory()->create();
    $session = UserSession::factory()->create(['user_id' => $user->getKey()]);
    UserSession::factory()->create(); // somebody else's

    expect($user->sessions()->pluck('id')->all())->toBe([$session->id]);
})->with([Admin::class, Seller::class, Customer::class]);

it('resolves a user’s devices, whichever subclass asks', function (string $model): void {
    $user = $model::factory()->create();
    $device = UserDevice::factory()->create(['user_id' => $user->getKey()]);
    UserDevice::factory()->create();

    expect($user->devices()->pluck('id')->all())->toBe([$device->id]);
})->with([Admin::class, Seller::class, Customer::class]);

it('resolves a user’s login attempts, whichever subclass asks', function (string $model): void {
    $user = $model::factory()->create();
    $attempt = LoginAttempt::factory()->create(['user_id' => $user->getKey()]);
    LoginAttempt::factory()->create(); // an attempt against an unknown address

    expect($user->loginAttempts()->pluck('id')->all())->toBe([$attempt->id]);
})->with([Admin::class, Seller::class, Customer::class]);

it('resolves a user’s activities, whichever subclass asks', function (string $model): void {
    $user = $model::factory()->create();
    $activity = ActivityEntry::factory()->create(['user_id' => $user->getKey()]);
    ActivityEntry::factory()->create();

    expect($user->activities()->pluck('id')->all())->toBe([$activity->id]);
})->with([Admin::class, Seller::class, Customer::class]);

it('resolves a user’s notification preferences, whichever subclass asks', function (string $model): void {
    $user = $model::factory()->create();
    $preference = NotificationPreference::factory()->create(['user_id' => $user->getKey()]);
    NotificationPreference::factory()->create();

    expect($user->notificationPreferences()->pluck('id')->all())->toBe([$preference->id]);
})->with([Admin::class, Seller::class, Customer::class]);

it('resolves the audit entries a user caused, whichever subclass asks', function (string $model): void {
    // The causer is written as the CONCRETE class (AuditLogger, Auditable,
    // AuditEntryFactory all store `$causer::class`), so the relation has to
    // read it back the same way. Filtering on the base User class matched
    // nothing and the trail looked empty for every real actor.
    $user = $model::factory()->create();
    $entry = AuditEntry::factory()->causedBy($user)->create();
    AuditEntry::factory()->create(); // system context, no causer

    expect($user->auditEntries()->pluck('id')->all())->toBe([$entry->id]);
})->with([Admin::class, Seller::class, Customer::class]);

it('does not confuse two actors who share an id across guards', function (): void {
    // The whole point of the user_id key: ids are unique across `users`, so a
    // seller must never see a customer's rows even if the relation is declared
    // on their common parent.
    $seller = Seller::factory()->create();
    $customer = Customer::factory()->create();

    UserSession::factory()->create(['user_id' => $customer->getKey()]);

    expect($seller->sessions()->count())->toBe(0)
        ->and($customer->sessions()->count())->toBe(1);
});
