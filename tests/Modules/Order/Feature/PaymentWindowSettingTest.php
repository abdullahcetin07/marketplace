<?php

declare(strict_types=1);

use App\Modules\Settings\Domain\Enums\SettingGroup;
use App\Modules\Settings\Domain\Models\Setting;

/*
|--------------------------------------------------------------------------
| The payment window is an operator's number (ADR-072)
|--------------------------------------------------------------------------
|
| Five minutes is deliberately aggressive: a hold that outlives the shopper's
| attention costs the seller every sale it blocks. It is also shorter than
| PayTR's own iframe session, so a slow 3-D Secure can expire mid-payment — which
| is why the value lives in `settings()` rather than in code, and why the
| late-payment path exists at all.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

it('seeds a five-minute window on the order tab', function (): void {
    $this->seed(Database\Modules\Settings\Seeders\SettingsSeeder::class);

    $setting = Setting::query()->where('key', 'order.payment_window_minutes')->firstOrFail();

    expect($setting->group)->toBe(SettingGroup::Order);

    /*
     * ASSERTED THROUGH `settings()`, NOT OFF THE COLUMN. `register()` fills
     * `default_value` and never overwrites a value an operator set, so the
     * column alone would not prove what the sweep actually reads — and what the
     * sweep reads is the only thing that matters.
     */
    expect((int) settings('order.payment_window_minutes'))->toBe(5);

    // And the config floor agrees, for the boot where Settings is unreachable.
    expect((int) config('order.payment_window_minutes'))->toBe(5);
});

it('keeps the window off every public surface', function (): void {
    /*
     * HOW LONG A SHOPPER HAS TO PAY IS A DETAIL OF THE CHECKOUT. An
     * unauthenticated endpoint advertising it would be telling somebody exactly
     * how long a stock hold survives — which is the first thing you would want
     * to know in order to sit on somebody else's inventory.
     */
    expect(SettingGroup::Order->isPubliclyReadable())->toBeFalse()
        // And not a super-admin-only lever either: it is the operations team's
        // number, like the shipping windows beside it.
        ->and(SettingGroup::Order->isRestricted())->toBeFalse();
});

it('gives the new group an icon, a sort order and a label', function (): void {
    // `icon()` and `sortOrder()` are exhaustive matches with no default, so a new
    // case without an arm is a build failure rather than a blank tab.
    foreach (SettingGroup::cases() as $group) {
        expect($group->icon())->toStartWith('heroicon-')
            ->and($group->sortOrder())->toBeGreaterThan(0)
            ->and($group->label())->not->toBe('');
    }
});
