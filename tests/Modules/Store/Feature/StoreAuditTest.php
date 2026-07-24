<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Modules\Store\Application\Actions\ActivateStoreAction;
use App\Modules\Store\Application\Actions\ReinstateStoreAction;
use App\Modules\Store\Application\Actions\SuspendStoreAction;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| Store — audit verification (ADR-027)
|--------------------------------------------------------------------------
|
| Store is an auditable aggregate. Admin enforcement must leave a forensic record
| carrying the reason; ordinary transitions must be recorded too.
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

it('records the reason on the forensic entry when an admin suspends a store', function (): void {
    $store = Store::factory()->active()->create();
    $admin = Admin::factory()->create();

    SuspendStoreAction::make()->run($store, $admin, 'Counterfeit goods');

    $entry = $store->audits()->first();
    expect($entry)->not->toBeNull()
        ->and($entry->metadata['reason'] ?? null)->toBe('Counterfeit goods');
});

it('records a forensic entry for a reinstatement', function (): void {
    $store = Store::factory()->active()->create();
    $admin = Admin::factory()->create();

    SuspendStoreAction::make()->run($store, $admin, 'Review');
    ReinstateStoreAction::make()->run($store->refresh(), $admin, 'Cleared');

    // Suspension + reinstatement both leave records.
    expect($store->audits()->count())->toBeGreaterThanOrEqual(2);
});

it('audits an ordinary lifecycle transition', function (): void {
    $store = Store::factory()->create(['status' => StoreStatus::Draft]);

    ActivateStoreAction::make()->run($store);

    expect($store->audits()->count())->toBeGreaterThan(0);
});
