<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Modules\Identity\Domain\Events\UserLoggedOut;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\TransientToken;

/*
|--------------------------------------------------------------------------
| POST /api/v1/auth/logout
|--------------------------------------------------------------------------
|
| Logging out is two different operations wearing one route. A token client
| has a row in `personal_access_tokens` that must go; a storefront cookie
| session has a `TransientToken` — Sanctum's stand-in so `tokenCan()` answers
| the same way whichever way the request arrived — which has no row and no id.
|
| Production only ever exercised the second one, and it 500'd on
| `TransientToken::getKey()`: the session row was already marked revoked and
| the guard had not yet been told to log out, so the shopper stayed signed in
| while the server said it had broken. There was no test on this route at all.
|
| The cookie case is set up by hand — a real `TransientToken` on the user the
| sanctum guard resolves. `Sanctum::actingAs()` looks like the obvious helper
| and is the wrong one: it stamps a Mockery mock of the PersonalAccessToken
| MODEL, which has a getKey() and sails straight past the bug. Both this file
| and the fix were re-run against the unfixed action to confirm they fail.
|
*/

beforeEach(function (): void {
    // SetLocale runs on every /api/v1 route and Language::default() throws
    // unseeded — a 404 that has nothing to do with logging out.
    $this->seedPlatform();
});

it('logs out a cookie-authenticated shopper', function (): void {
    $customer = Customer::factory()->create();

    /*
    | NOT `Sanctum::actingAs()`: that helper stamps a MOCK of the
    | PersonalAccessToken model, which has a getKey() and therefore sails
    | straight past the bug. The cookie case is a real TransientToken on the
    | user the sanctum guard resolves — which is what production hands us.
    */
    $customer->withAccessToken(new TransientToken);
    $this->actingAs($customer, 'sanctum');

    $this->postJson('/api/v1/auth/logout')->assertNoContent();
});

it('deletes the token that authorised a token logout', function (): void {
    $customer = Customer::factory()->create();
    $token = $customer->createToken('storefront');
    $id = $token->accessToken->getKey();

    expect(DB::table('personal_access_tokens')->where('id', $id)->exists())->toBeTrue();

    $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/auth/logout')
        ->assertNoContent();

    expect(DB::table('personal_access_tokens')->where('id', $id)->exists())->toBeFalse();
});

it('announces the logout once the transaction has committed', function (): void {
    /*
    | The event is what the activity timeline hangs off; it fires from
    | `after()`, so a throw earlier in `handle()` took it with the response.
    */
    Event::fake([UserLoggedOut::class]);

    $customer = Customer::factory()->create();

    /*
    | NOT `Sanctum::actingAs()`: that helper stamps a MOCK of the
    | PersonalAccessToken model, which has a getKey() and therefore sails
    | straight past the bug. The cookie case is a real TransientToken on the
    | user the sanctum guard resolves — which is what production hands us.
    */
    $customer->withAccessToken(new TransientToken);
    $this->actingAs($customer, 'sanctum');

    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    Event::assertDispatched(UserLoggedOut::class);
});
