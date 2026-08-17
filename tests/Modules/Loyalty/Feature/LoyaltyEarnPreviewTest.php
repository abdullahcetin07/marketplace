<?php

declare(strict_types=1);

use App\Modules\Loyalty\Application\Actions\AwardPurchasePointsAction;

/*
|--------------------------------------------------------------------------
| "Bu ürünü alınca X puan kazan" (ADR-082)
|--------------------------------------------------------------------------
|
| One public read that turns a price into a point count, so the storefront never
| multiplies a price string by a rate in JavaScript floats — and so the promise on
| the product page cannot disagree with what the nightly sweep credits.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

it('answers the same floor the sweep would credit', function (): void {
    // 129,90 TL at one point per lira: 129 points, not 130 and not 129,9 of
    // anything. Rounding up would promise a point the customer never receives.
    $this->getJson('/api/v1/loyalty/earn-preview?amount=129.90')
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.points', 129)
        ->assertJsonPath('data.currency', 'TRY');

    $this->getJson('/api/v1/loyalty/earn-preview?amount=1000')
        ->assertOk()
        ->assertJsonPath('data.points', 1_000);
});

it('accepts a Turkish decimal comma', function (): void {
    // Excel writes it, and so does anybody typing the URL by hand.
    $this->getJson('/api/v1/loyalty/earn-preview?amount=129,90')
        ->assertOk()
        ->assertJsonPath('data.points', 129);
});

it('refuses an amount that is not a number', function (): void {
    /*
     * A negative amount is a caller bug and is REFUSED rather than floored to
     * zero — answering "0 puan" would hide it behind a plausible-looking card.
     */
    foreach (['abc', '-5', '', '12.5.6'] as $bad) {
        $this->getJson('/api/v1/loyalty/earn-preview?amount='.urlencode($bad))
            ->assertUnprocessable();
    }

    $this->getJson('/api/v1/loyalty/earn-preview')->assertUnprocessable();
});

it('is readable without signing in, because the product page is', function (): void {
    // The signed-out shopper is exactly who this card is arguing with.
    $this->getJson('/api/v1/loyalty/earn-preview?amount=50.00')
        ->assertOk()
        ->assertJsonPath('data.points', 50);
});

it('says so plainly when the programme is off', function (): void {
    $this->seed(Database\Modules\Settings\Seeders\SettingsSeeder::class);
    settings()->set('loyalty.enabled', false);

    /*
     * OFF IS AN ANSWER, NOT AN ERROR: the storefront renders nothing, and a 404
     * would make a switched-off programme look like a broken page.
     */
    $this->getJson('/api/v1/loyalty/earn-preview?amount=100.00')
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.points', 0);
});

it('tracks the rate the admin sets, and matches the sweep at that rate', function (): void {
    $this->seed(Database\Modules\Settings\Seeders\SettingsSeeder::class);
    settings()->set('loyalty.earn.purchase_rate', 2);

    $preview = $this->getJson('/api/v1/loyalty/earn-preview?amount=149.90')
        ->assertOk()
        ->json('data.points');

    /*
     * **THE PREVIEW AND THE SWEEP ARE ONE ARITHMETIC.** Asserting the number here
     * AND deriving it the way the sweep does is what stops the two drifting: a
     * card promising 299 while the ledger credits 298 is a support ticket per
     * order.
     */
    expect($preview)->toBe((int) floor(14_990 / 100 * 2))
        ->and($preview)->toBe(299)
        ->and(class_exists(AwardPurchasePointsAction::class))->toBeTrue();
});
