<?php

declare(strict_types=1);

use App\Modules\Catalog\Presentation\Controllers\Api\Storefront\GoogleMerchantFeedController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Deliberately almost empty. The customer-facing storefront is a separate
| Next.js application that talks to routes/api.php; the two back-office
| surfaces are Filament panels that register their own routes.
|
| What remains here is the small amount of glue that has nowhere else to live.
|
*/

/*
| Root redirects to the storefront rather than rendering anything. Laravel
| serves the API and the panels; it is not the public website.
|
| GUARD. The storefront is a SEPARATE Next.js application (README; ADR-025), so
| FRONTEND_URL must name a different origin. When it is empty, malformed, or
| points back at this same application, `away()` would send the browser to this
| very route — an infinite redirect. `away()` validates nothing, so the check
| has to happen here. In that case fall back to the admin panel, the only thing
| Laravel itself can render.
|
| The comparison includes the PORT deliberately: locally APP_URL is :8080 while
| FRONTEND_URL is :3000 — the same host, but genuinely different applications.
| A correctly configured external FRONTEND_URL is unaffected and still redirects
| exactly as before.
*/
Route::get('/', static function () {
    $frontend = trim((string) config('marketplace.frontend_url'));

    $origin = static function (string $url): ?string {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        // strtolower, not mb_: a parsed scheme/host is ASCII by definition
        // (IDN hosts arrive punycode-encoded), so there is nothing multibyte here.
        return strtolower(($parts['scheme'] ?? '').'://'.$parts['host'])
            .(isset($parts['port']) ? ':'.$parts['port'] : '');
    };

    $frontendOrigin = $origin($frontend);

    if ($frontendOrigin === null || $frontendOrigin === $origin((string) config('app.url'))) {
        return redirect('/admin/login');
    }

    return redirect()->away($frontend);
})->name('home');

/*
| A named `login` route must exist: Laravel's auth middleware redirects
| unauthenticated web requests to route('login'), and both Filament panels sit
| behind it. Which panel a visitor is sent to depends on the path they were
| trying to reach — a seller bounced off /seller should land on the seller
| login form, not the admin one.
*/
Route::get('/login', static function (Request $request) {
    $intended = (string) $request->session()->get('url.intended', '');

    return str_contains($intended, '/seller')
        ? redirect('/seller/login')
        : redirect('/admin/login');
})->name('login');

/*
| THE GOOGLE MERCHANT CENTER PRODUCT FEED.
|
| A `web` route rather than an API one because the URL is what gets typed into
| Merchant Center's scheduled fetch, and `/api/v1/feed/google-merchant.xml` reads
| as an internal endpoint somebody may version or move. This one is a file at a
| stable address.
|
| **NGINX HAS TO KNOW ABOUT IT TOO.** `/feed` is not in the vhost's Laravel
| prefix list, and everything not in that list belongs to the Next.js storefront
| — so without a `location ^~ /feed` block this route returns the storefront's
| 404 page and nothing fails at deploy time. That is exactly how `/magaza` was
| served by the wrong application for months.
|
| No CSRF concern: GET, sessionless, and no state to protect.
*/
Route::get('/feed/google-merchant.xml', [GoogleMerchantFeedController::class, 'show'])
    ->name('feed.google-merchant');
