<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| The storefront limiter does not count the storefront's own renders
|--------------------------------------------------------------------------
|
| Measured on production, 2026-09-03: `/urunler` answered "Application error"
| under load while the API, the database and PHP-FPM were all healthy. The
| Next.js storefront renders on the same box and fetches through the loopback
| vhost, so every visitor's render arrived from 127.0.0.1 and shared ONE
| 300/minute bucket — 11,094 `429`s in the log, all of them first-party.
|
| The exemption is keyed on a CGI parameter the loopback vhost sets, NOT on the
| socket address (every request reaches PHP-FPM from nginx over loopback, so it
| cannot tell them apart) and NOT on a header (a client could send one). The
| test that matters most here is the last: a client-supplied header of the same
| name must NOT buy the exemption.
|
*/

/**
 * The limit the storefront limiter would apply to a request with these CGI
 * server variables.
 *
 * @param array<string, string> $server
 */
function storefrontLimit(array $server = []): Illuminate\Cache\RateLimiting\Limit
{
    $request = Request::create('/api/v1/products', 'GET', server: $server);

    $limit = app(RateLimiter::class)->limiter('storefront')($request);

    expect($limit)->toBeInstanceOf(Illuminate\Cache\RateLimiting\Limit::class);

    return $limit;
}

it('limits an ordinary shopper by ip', function (): void {
    $limit = storefrontLimit(['REMOTE_ADDR' => '203.0.113.7']);

    expect($limit->maxAttempts)->toBe((int) config('marketplace.rate_limits.storefront', 300))
        ->and($limit->key)->toContain('203.0.113.7');
});

it('does not limit the storefront rendering itself', function (): void {
    $limit = storefrontLimit(['INTERNAL_RENDER' => '1']);

    // `Limit::none()` is PHP_INT_MAX attempts — the limiter is still installed
    // on the route, it simply never trips for a first-party render.
    expect($limit->maxAttempts)->toBe(PHP_INT_MAX);
});

it('cannot be exempted by a header a client sends', function (): void {
    /*
    | THE ASSERTION THAT KEEPS THIS SAFE. nginx exposes request headers as
    | `HTTP_*`; the loopback vhost sets a bare CGI parameter. If this ever
    | passes, someone has swapped the check for a header and re-opened the
    | limiter to anybody who guesses its name.
    */
    $limit = storefrontLimit([
        'REMOTE_ADDR' => '203.0.113.7',
        'HTTP_INTERNAL_RENDER' => '1',
    ]);

    expect($limit->maxAttempts)->toBe((int) config('marketplace.rate_limits.storefront', 300));
});
