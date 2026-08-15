<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Seller;
use App\Modules\Loyalty\Domain\Models\LoyaltyLedgerEntry;
use App\Modules\Loyalty\Presentation\Filament\Pages\LoyaltySettings;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Loyalty — the two surfaces (ADR-081/082)
|--------------------------------------------------------------------------
|
| A customer reads their own balance and nobody else's; an admin or finance
| officer edits the five rates and nobody else does.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

it('answers the signed-in customer their own balance, in points and in lira', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    LoyaltyLedgerEntry::factory()->count(2)->create(['customer_uuid' => $customer->uuid, 'points' => 260]);

    $response = $this->actingAs($customer, 'customer')->getJson('/api/v1/loyalty/balance')->assertOk();

    /*
     * **THE LIRA ARE A RENDERING, NOT A BANKED TOTAL.** What a point is worth is a
     * setting an operator can change, so this is what today's balance is worth
     * today — as a decimal string, because it is money (ADR-005).
     */
    expect($response->json('data.points'))->toBe(520)
        ->and($response->json('data.value'))->toBe('26.00')
        ->and($response->json('data.currency'))->toBe('TRY');
});

it('cannot be asked for somebody else’s ledger, because there is nowhere to ask', function (): void {
    /** @var Customer $mine */
    $mine = Customer::factory()->create();
    /** @var Customer $theirs */
    $theirs = Customer::factory()->create();

    LoyaltyLedgerEntry::factory()->create(['customer_uuid' => $mine->uuid, 'points' => 10]);
    LoyaltyLedgerEntry::factory()->create(['customer_uuid' => $theirs->uuid, 'points' => 999]);

    $response = $this->actingAs($mine, 'customer')->getJson('/api/v1/loyalty/ledger')->assertOk();

    /*
     * No `{customer}` in the path and no id in the query: reading another
     * customer's ledger is not denied, it is unexpressible. The assertion is that
     * the route returns the CALLER's rows and only those.
     */
    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.points'))->toBe(10);
});

it('refuses a seller or an admin rather than showing them an empty balance', function (): void {
    /*
     * An empty balance implies the programme applies to them and merely has
     * nothing in it. It does not apply to them.
     */
    foreach ([['seller', Seller::factory()->create()], ['admin', Admin::factory()->create()]] as [$guard, $actor]) {
        $this->actingAs($actor, $guard)->getJson('/api/v1/loyalty/balance')->assertForbidden();
    }
});

it('refuses an anonymous caller before it looks at anything', function (): void {
    /*
     * ITS OWN TEST, DELIBERATELY. The guard caches its resolved user and the test
     * client keeps one container across requests, so an unauthenticated call in
     * the same `it()` as an `actingAs` answers from the earlier request's guard —
     * an artifact of the harness that would make this assertion prove nothing.
     */
    $this->getJson('/api/v1/loyalty/balance')->assertUnauthorized();
});

it('labels each row with what earned it', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    LoyaltyLedgerEntry::factory()->create([
        'customer_uuid' => $customer->uuid,
        'points' => 50,
        'source_type' => App\Modules\Loyalty\Domain\Enums\LoyaltyPointSource::Review,
    ]);

    $row = $this->actingAs($customer, 'customer')->getJson('/api/v1/loyalty/ledger')->assertOk()->json('data.0');

    // The label travels as DATA: three strings in two places is two places for
    // them to drift.
    expect($row['label'])->toBe('Değerlendirme')
        ->and($row['source_type'])->toBe('review')
        // The public id is the uuid; the internal id never leaves (#7).
        ->and($row['id'])->not->toBeNumeric();
});

it('lets an admin edit the rates and refuses everybody else', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->seed(Database\Modules\Settings\Seeders\SettingsSeeder::class);

    $admin = $this->actingAsAdmin();
    $admin->syncRoles([config('marketplace.roles.admin')]);
    app()[Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    Livewire::test(LoyaltySettings::class)
        ->set('data.signup', 250)
        ->set('data.redeem_value', '0.10')
        ->call('save');

    expect((int) settings('loyalty.earn.signup'))->toBe(250)
        // A DECIMAL, kept as digits rather than a float: what a point is worth
        // must not depend on binary rounding.
        ->and((string) settings('loyalty.redeem.value'))->toBe('0.10');
});

it('keeps the rates away from an actor without the ability', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $support = Admin::factory()->create();
    $support->syncRoles([config('marketplace.roles.support')]);

    $this->actingAs($support, 'admin');

    /*
     * **THE PERMISSION DECIDES, NOT THE ROLE NAME** (non-negotiable #5). Support
     * holds the admin panel and not this: the five numbers on that page are a
     * liability the business carries.
     */
    expect(LoyaltySettings::canAccess())->toBeFalse();
});

it('quotes a redemption against the live cart without moving anything', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    LoyaltyLedgerEntry::factory()->create(['customer_uuid' => $customer->uuid, 'points' => 500]);

    // No cart yet: nothing to discount, and that is an answer rather than an error.
    $empty = $this->actingAs($customer, 'customer')
        ->postJson('/api/v1/loyalty/redeem/quote', ['use_max' => true])
        ->assertOk();

    expect($empty->json('data.cart_total'))->toBe('0.00')
        ->and($empty->json('data.max_points'))->toBe(0)
        ->and($empty->json('data.payable'))->toBe('0.00')
        /*
         * **A PREVIEW MOVES NOTHING.** The checkout page calls this on every drag
         * of the slider; a hold here would earmark a balance on a page the shopper
         * may close.
         */
        ->and(App\Modules\Loyalty\Domain\Models\LoyaltyHold::query()->count())->toBe(0);
});

it('clamps the quote to the balance and answers in decimal strings', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    LoyaltyLedgerEntry::factory()->create(['customer_uuid' => $customer->uuid, 'points' => 120]);

    // Asking for more than the balance is not an error — a slider is allowed to be
    // optimistic, and the port clamps.
    $response = $this->actingAs($customer, 'customer')
        ->postJson('/api/v1/loyalty/redeem/quote', ['points' => 9_999])
        ->assertOk();

    expect($response->json('data.currency'))->toBe('TRY')
        // Money crosses as a decimal string; a point crosses as an integer (ADR-005).
        ->and($response->json('data.discount'))->toBeString()
        ->and($response->json('data.max_points'))->toBeInt();
});

it('keeps the quote to customers', function (): void {
    $this->actingAs(Seller::factory()->create(), 'seller')
        ->postJson('/api/v1/loyalty/redeem/quote', ['use_max' => true])
        ->assertForbidden();
});
