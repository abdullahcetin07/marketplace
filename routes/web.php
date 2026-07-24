<?php

declare(strict_types=1);

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
*/
Route::get('/', static fn () => redirect()->away((string) config('marketplace.frontend_url')))
    ->name('home');

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
