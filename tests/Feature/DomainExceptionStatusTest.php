<?php

declare(strict_types=1);

use App\Modules\Store\Domain\Exceptions\StorefrontException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/*
|--------------------------------------------------------------------------
| A domain failure keeps its status for a BROWSER, not only for the API
|--------------------------------------------------------------------------
|
| `BaseException::render()` returns null for a non-JSON request, on purpose, so
| Laravel can draw its own error page. But Laravel only reads a status off an
| exception that implements `HttpExceptionInterface` — everything else is a 500.
| So every domain failure on this platform answered the API with a correct 404 or
| 403 and answered a browser with `500 Server Error`.
|
| It hid for months because the storefront is a Next.js server that sends
| `Accept: application/json` and therefore always saw the right status. It
| surfaced on 2026-08-20, minutes after 110 seeded test stores were deleted from
| production: `/api/v1/magaza/{slug}` in a browser returned 500 for a shop that
| was simply gone.
|
| The difference is not cosmetic. A 500 tells a crawler the site is broken rather
| than the page is gone — a de-indexing signal instead of a routine one — and it
| tells whoever is on call to hunt an incident that never happened.
|
*/

/*
| SEED FIRST, OR THIS FILE LIES. Without Localization the request 404s on
| route-model binding for `Language` and never reaches the store controller — so
| both assertions below pass whether or not the bug is fixed. The first draft of
| this test did exactly that.
*/
beforeEach(fn () => $this->seedPlatform());

it('answers a browser with the declared status, not 500', function (): void {
    $this->get('/api/v1/magaza/bu-magaza-yok', ['Accept' => 'text/html'])
        ->assertNotFound();
});

it('answers an API client with the same status, in the ADR-009 envelope', function (): void {
    $this->getJson('/api/v1/magaza/bu-magaza-yok')
        ->assertNotFound()
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'STOREFRONT_EXCEPTION');
});

it('exposes the status to the framework, not just to render()', function (): void {
    $exception = StorefrontException::unavailable();

    expect($exception)->toBeInstanceOf(HttpExceptionInterface::class)
        ->and($exception->getStatusCode())->toBe(404)
        ->and($exception->getStatusCode())->toBe($exception->getStatus())
        ->and($exception->getHeaders())->toBe([]);
});
