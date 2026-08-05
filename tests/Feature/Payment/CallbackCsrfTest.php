<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\TokenMismatchException;

/*
|--------------------------------------------------------------------------
| The PSP callback and CSRF (Payment.md §3)
|--------------------------------------------------------------------------
|
| **A 419 HERE MEANS MONEY COLLECTED AND AN ORDER NEVER CONFIRMED** — the one
| state on this platform nothing later can repair. PayTR posts server-to-server,
| from its own network, with no browser, no session and no token, and it retries
| until it is answered `"OK"`.
|
| WHY THIS FILE EXERCISES THE MIDDLEWARE DIRECTLY INSTEAD OF CALLING THE ROUTE.
| `ValidateCsrfToken::handle()` begins with `$this->runningUnitTests()`, so every
| request made through `$this->postJson()` passes CSRF unconditionally — the
| framework switches the check off for the whole suite. A feature test asserting
| a 200 on this endpoint therefore proves NOTHING about CSRF, which is exactly
| why P1's suite was green while a same-origin POST answered 419 in production.
|
| So the middleware is instantiated with that one short-circuit overridden, and
| the real `inExceptArray()` / `tokensMatch()` logic decides.
|
*/

/**
 * The real middleware, with only the suite-wide bypass removed.
 *
 * Named for this file because Pest shares ONE global function namespace.
 */
function csrfMiddleware(): ValidateCsrfToken
{
    return new class(app(), app(Encrypter::class)) extends ValidateCsrfToken
    {
        /**
         * THE ONE OVERRIDE. Everything else — the except list, the token
         * comparison, the cookie handling — is the framework's own code, which
         * is the point: a test that reimplemented the decision would agree with
         * itself and not with production.
         */
        protected function runningUnitTests(): bool
        {
            return false;
        }
    };
}

/**
 * A cookieless, tokenless POST — the shape PayTR sends.
 *
 * @param array<string, string> $headers
 */
function csrfPost(string $path, array $headers = []): Request
{
    $request = Request::create("https://test.raftabul.com/{$path}", 'POST', [
        'merchant_oid' => 'deadbeef',
        'status' => 'success',
    ]);

    foreach ($headers as $header => $value) {
        $request->headers->set($header, $value);
    }

    $request->setLaravelSession(app('session')->driver());

    return $request;
}

it('lets the PayTR callback through without a token', function (): void {
    $response = csrfMiddleware()->handle(
        csrfPost('api/v1/payments/paytr/callback'),
        fn (): Response => new Response('OK'),
    );

    // PayTR retries until it hears this, so anything else is a payment that
    // never settles.
    expect($response->getContent())->toBe('OK');
});

it('lets it through even when the request looks like it came from our own site', function (): void {
    /*
     * THE HEADER THAT MADE THIS A REAL BUG. Sanctum promotes a request to the
     * session stack when its Origin/Referer names a stateful domain, and CSRF
     * comes with it. PayTR happens not to send those headers — but that is a
     * third party's choice, not a guarantee, and "settlement works because the
     * PSP omits a Referer" is not a property to rely on.
     */
    $response = csrfMiddleware()->handle(
        csrfPost('api/v1/payments/paytr/callback', [
            'Referer' => 'https://test.raftabul.com/',
            'Origin' => 'https://test.raftabul.com',
        ]),
        fn (): Response => new Response('OK'),
    );

    expect($response->getContent())->toBe('OK');
});

it('still refuses a tokenless POST to any other endpoint', function (): void {
    /*
     * THE EXEMPTION IS ONE PATH, NOT A HOLE. If this ever passes, somebody has
     * widened the except list into a wildcard and every session-authenticated
     * write on the platform is forgeable from another site.
     */
    expect(fn (): mixed => csrfMiddleware()->handle(
        csrfPost('api/v1/admin/payouts'),
        fn (): Response => new Response('OK'),
    ))->toThrow(TokenMismatchException::class);
});

it('names the route the application actually registers', function (): void {
    /*
     * THE EXCEPT LIST IS A STRING AND THE ROUTE IS A STRING, and nothing makes
     * them agree. Renaming or re-prefixing the route would silently un-exempt
     * it — the failure being a 419 in production months later, on the one
     * endpoint where that costs a customer their order.
     */
    $uri = app('router')->getRoutes()->getByName('api.v1.payments.paytr.callback')?->uri();

    expect($uri)->toBe('api/v1/payments/paytr/callback');
});
