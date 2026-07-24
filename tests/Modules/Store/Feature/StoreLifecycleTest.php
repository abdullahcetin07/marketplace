<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Modules\Store\Application\Actions\ActivateStoreAction;
use App\Modules\Store\Application\Actions\ArchiveStoreAction;
use App\Modules\Store\Application\Actions\CloseStoreAction;
use App\Modules\Store\Application\Actions\PauseStoreAction;
use App\Modules\Store\Application\Actions\ReinstateStoreAction;
use App\Modules\Store\Application\Actions\ResumeStoreAction;
use App\Modules\Store\Application\Actions\SuspendStoreAction;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Events\StoreActivated;
use App\Modules\Store\Domain\Events\StoreArchived;
use App\Modules\Store\Domain\Events\StoreClosed;
use App\Modules\Store\Domain\Events\StorePaused;
use App\Modules\Store\Domain\Events\StoreReinstated;
use App\Modules\Store\Domain\Events\StoreSuspended;
use App\Modules\Store\Domain\Exceptions\StoreException;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Store — operational state transitions (§7)
|--------------------------------------------------------------------------
|
| Each transition guards its from-state and emits a domain event so downstream
| modules react without Store knowing they exist (ADR-033).
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

it('activates a draft store and emits StoreActivated', function (): void {
    Event::fake([StoreActivated::class]);
    $store = Store::factory()->create(['status' => StoreStatus::Draft]);

    $result = ActivateStoreAction::make()->run($store);

    expect($result->status)->toBe(StoreStatus::Active)
        ->and($result->activated_at)->not->toBeNull();
    Event::assertDispatched(StoreActivated::class, fn (StoreActivated $e): bool => $e->storeId === $store->getKey());
});

it('refuses to activate an already-active store', function (): void {
    $store = Store::factory()->active()->create();

    expect(fn () => ActivateStoreAction::make()->run($store))->toThrow(StoreException::class);
});

it('pauses and resumes a store', function (): void {
    Event::fake([StorePaused::class, StoreActivated::class]);
    $store = Store::factory()->active()->create();

    PauseStoreAction::make()->run($store);
    expect($store->refresh()->status)->toBe(StoreStatus::Paused);
    Event::assertDispatched(StorePaused::class);

    ResumeStoreAction::make()->run($store);
    expect($store->refresh()->status)->toBe(StoreStatus::Active);
    Event::assertDispatched(StoreActivated::class);
});

it('closes an active store and emits StoreClosed', function (): void {
    Event::fake([StoreClosed::class]);
    $store = Store::factory()->active()->create();

    CloseStoreAction::make()->run($store);

    expect($store->refresh()->status)->toBe(StoreStatus::Closed);
    Event::assertDispatched(StoreClosed::class);
});

it('reopens a closed store via activate', function (): void {
    $store = Store::factory()->create(['status' => StoreStatus::Closed]);

    ActivateStoreAction::make()->run($store);

    expect($store->refresh()->status)->toBe(StoreStatus::Active);
});

it('suspends a store with a reason and records the prior state', function (): void {
    Event::fake([StoreSuspended::class]);
    $store = Store::factory()->active()->create();
    $admin = Admin::factory()->create();

    SuspendStoreAction::make()->run($store, $admin, 'Counterfeit goods');

    $store->refresh();
    expect($store->status)->toBe(StoreStatus::Suspended)
        ->and($store->status_before_suspension)->toBe(StoreStatus::Active)
        ->and($store->suspended_by)->toBe($admin->getKey())
        ->and($store->suspension_reason)->toBe('Counterfeit goods');
    Event::assertDispatched(StoreSuspended::class, fn (StoreSuspended $e): bool => $e->reason === 'Counterfeit goods');
});

it('reinstates a store to its exact prior state (not blindly Active)', function (): void {
    Event::fake([StoreReinstated::class]);
    // A store suspended while still a Draft must return to Draft.
    $store = Store::factory()->create(['status' => StoreStatus::Draft]);
    $admin = Admin::factory()->create();

    SuspendStoreAction::make()->run($store, $admin, 'Review');
    ReinstateStoreAction::make()->run($store->refresh(), $admin);

    $store->refresh();
    expect($store->status)->toBe(StoreStatus::Draft)
        ->and($store->status_before_suspension)->toBeNull()
        ->and($store->suspended_at)->toBeNull()
        ->and($store->suspended_by)->toBeNull();
    Event::assertDispatched(StoreReinstated::class);
});

it('archives a closed store and emits StoreArchived', function (): void {
    Event::fake([StoreArchived::class]);
    $store = Store::factory()->create(['status' => StoreStatus::Closed]);

    ArchiveStoreAction::make()->run($store);

    expect($store->refresh()->status)->toBe(StoreStatus::Archived);
    Event::assertDispatched(StoreArchived::class);
});

it('refuses to archive a live store', function (): void {
    $store = Store::factory()->active()->create();

    expect(fn () => ArchiveStoreAction::make()->run($store))->toThrow(StoreException::class);
});

it('refuses to suspend an archived store (terminal)', function (): void {
    $store = Store::factory()->create(['status' => StoreStatus::Archived]);
    $admin = Admin::factory()->create();

    expect(fn () => SuspendStoreAction::make()->run($store, $admin, 'x'))->toThrow(StoreException::class);
});
