<?php

declare(strict_types=1);

use App\Core\Presentation\Middleware\SetLocale;
use App\Modules\Catalog\Presentation\Controllers\Api\Storefront\PublicProductController;
use App\Modules\Catalog\Presentation\Controllers\Api\Storefront\PublicTaxonomyController;
use App\Modules\Catalog\Presentation\Controllers\Api\Storefront\SlugResolverController;
use App\Modules\Identity\Presentation\Controllers\Api\Admin\UserController as AdminUserController;
use App\Modules\Identity\Presentation\Controllers\Api\AuthController;
use App\Modules\Identity\Presentation\Controllers\Api\DeviceController;
use App\Modules\Identity\Presentation\Controllers\Api\EmailVerificationController;
use App\Modules\Identity\Presentation\Controllers\Api\PasswordController;
use App\Modules\Identity\Presentation\Controllers\Api\ProfileController;
use App\Modules\Identity\Presentation\Controllers\Api\SessionController;
use App\Modules\Identity\Presentation\Controllers\Api\TwoFactorController;
use App\Modules\Localization\Presentation\Controllers\Api\GeoController;
use App\Modules\Localization\Presentation\Controllers\Api\LocalizationController;
use App\Modules\Offer\Presentation\Controllers\Api\Storefront\PublicBuyBoxPriceController;
use App\Modules\Offer\Presentation\Controllers\Api\Storefront\PublicProductOfferController;
use App\Modules\Order\Presentation\Controllers\Api\CartController;
use App\Modules\Order\Presentation\Controllers\Api\CustomerAddressController;
use App\Modules\Order\Presentation\Controllers\Api\CustomerOrderController;
use App\Modules\Organization\Presentation\Controllers\Api\Admin\OrganizationController as AdminOrganizationController;
use App\Modules\Organization\Presentation\Controllers\Api\Admin\StoreRequestController as AdminStoreRequestController;
use App\Modules\Organization\Presentation\Controllers\Api\BankAccountController;
use App\Modules\Organization\Presentation\Controllers\Api\DocumentController;
use App\Modules\Organization\Presentation\Controllers\Api\InvitationController as OrganizationInvitationController;
use App\Modules\Organization\Presentation\Controllers\Api\MemberController;
use App\Modules\Organization\Presentation\Controllers\Api\OrganizationController;
use App\Modules\Organization\Presentation\Controllers\Api\StoreRequestController;
use App\Modules\Store\Presentation\Controllers\Api\Admin\StoreController as AdminStoreController;
use App\Modules\Store\Presentation\Controllers\Api\StoreController;
use App\Modules\Store\Presentation\Controllers\Api\Storefront\PublicStoreController;
use App\Modules\Store\Presentation\Controllers\Api\StoreLifecycleController;
use App\Modules\Store\Presentation\Controllers\Api\StoreProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — v1
|--------------------------------------------------------------------------
|
| Consumed by the Next.js storefront. Versioned from the first line: a public
| API without a version prefix cannot be changed without breaking whatever is
| already deployed against it, and adding the prefix later is the breaking
| change you were trying to avoid.
|
| THREE RULES THIS FILE FOLLOWS:
|
|  1. Every group carries an explicit rate limiter. There is no unthrottled
|     route. @see AppServiceProvider::configureRateLimiting()
|  2. `SetLocale` runs on everything, so a response is always in a known
|     language and carries Content-Language + Vary.
|  3. Route model binding is by UUID everywhere — no internal id is ever in a
|     URL. @see HasUuid::getRouteKeyName()
|
| Business endpoints arrive with their modules. Sprint 1 ships identity,
| sessions and locale bootstrap.
|
*/

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(SetLocale::class)
    ->group(function (): void {

        /*
        |------------------------------------------------------------------
        | Public
        |------------------------------------------------------------------
        */
        Route::middleware('throttle:api')->group(function (): void {

            // ADR-009 envelope, hand-built: a health check must not depend on
            // the controller stack it exists to report on.
            Route::get('/health', static fn (): array => [
                'success' => true,
                'data' => [
                    'status' => 'ok',
                    'version' => (string) config('app.version', '1.0.0'),
                    'time' => now()->toIso8601String(),
                ],
            ])->name('health');

            // Locale bootstrap — needed before anyone signs in.
            Route::get('/localization', [LocalizationController::class, 'index'])->name('localization');
            Route::get('/localization/countries', [LocalizationController::class, 'countries'])->name('localization.countries');
            Route::get('/localization/timezones', [LocalizationController::class, 'timezones'])->name('localization.timezones');

            /*
            | The address form's il → ilçe → mahalle cascade (ADR-056 amendment).
            |
            | ANONYMOUS, like the country list above: a guest fills in a delivery
            | address before signing up, and Turkey's administrative divisions
            | are published by the state — a login in front of them protects
            | nothing.
            |
            | ONE ROUTE PER LEVEL, each returning ONE PARENT'S CHILDREN. There is
            | no "all neighbourhoods" route on purpose: it would be 73,300 rows,
            | and a route that can express that is one somebody eventually calls
            | on page load.
            */
            Route::get('/geo/provinces', [GeoController::class, 'provinces'])->name('geo.provinces');
            Route::get('/geo/districts', [GeoController::class, 'districts'])->name('geo.districts');
            Route::get('/geo/neighborhoods', [GeoController::class, 'neighborhoods'])->name('geo.neighborhoods');
        });

        /*
        |------------------------------------------------------------------
        | Public storefront (ADR-034/035/036)
        |------------------------------------------------------------------
        |
        | The marketplace's canonical public entry point. Unauthenticated,
        | slug-resolved, its OWN throttle (anonymous browsing, not the api one).
        | One route per configured localised segment — `/store/{slug}`,
        | `/magaza/{slug}` — all resolving to the same store by slug. Only Active
        | stores render; a slug is a public handle, never a UUID.
        */
        Route::middleware('throttle:storefront')->group(function (): void {
            foreach ((array) config('marketplace.store.public_path_segments', ['store']) as $segment) {
                Route::get($segment.'/{slug}', [PublicStoreController::class, 'show'])
                    ->name('storefront.'.$segment);
            }

            /*
            | "Who sells this, and for how much" (Offer §5) — the buy box,
            | computed on every read (ADR-045). Same throttle and same rules as
            | the storefront above: anonymous, uuid-resolved, live stores only.
            |
            | Deliberately `/offers` rather than a bare `/products/{uuid}`: the
            | product page itself belongs to a future Catalog public surface,
            | and squatting on that path now would make it a breaking change to
            | build. This route answers only the question Offer owns.
            */
            Route::get('products/{product}/offers', [PublicProductOfferController::class, 'show'])
                ->name('storefront.product.offers');

            /*
            | THE MARKETPLACE-WIDE BUYER READ (ADR-058, Storefront.md §1.1) — the
            | first surface that answers "what can I buy here" rather than "what
            | does this one store sell".
            |
            | COMPOSED, NOT MERGED. Catalog owns the content and returns NO PRICE;
            | Offer owns price and availability. The listing filters to SELLABLE
            | through `OfferQueryContract`, so a card never leads to a page that
            | says unavailable — and the storefront overlays prices for the whole
            | page in one `POST /offers/prices` call.
            |
            | THE DETAIL ROUTE IS DECLARED AFTER `/products/{product}/offers`
            | deliberately: `{product}` would otherwise swallow the more specific
            | path and the buy box would 404.
            */
            Route::get('products', [PublicProductController::class, 'index'])
                ->name('storefront.products.index');
            Route::get('products/{product}', [PublicProductController::class, 'show'])
                ->name('storefront.products.show');

            /*
            | THE PRICE HALF OF THE COMPOSED READ (ADR-058, Storefront.md §1.2).
            | Catalog's cards carry no price; this returns the buy box for a whole
            | page in one call, so a listing renders "₺X'den başlayan fiyatlarla"
            | without one request per card.
            |
            | A POST THAT READS, deliberately: 24 uuids in a query string is past
            | what some proxies will forward and impossible to grow, so a
            | variable-length list belongs in the body. Capped, because uncapped it
            | would be a denial-of-service written as a feature.
            */
            Route::post('offers/prices', [PublicBuyBoxPriceController::class, 'index'])
                ->name('storefront.offers.prices');

            /*
            | THE FLAT URL SCHEME'S KEYSTONE (ADR-059). The storefront addresses
            | product, category and brand at the ROOT — `/bioderma`,
            | `/cilt-bakimi`, `/avene-...-krem` — with no type prefix, so its one
            | catch-all route cannot tell from the URL what it is rendering. This
            | tells it, in one request; the alternative is three, two of them 404s,
            | on every first paint.
            |
            | It answers with a TYPE and a uuid rather than a page, plus the
            | canonical slug so a visitor on a retired alias gets a 301 instead of
            | a rendered duplicate.
            */
            Route::get('resolve/{slug}', [SlugResolverController::class, 'show'])
                ->name('storefront.resolve');

            /*
            | THE MENU AND THE LANDING PAGES the flat scheme needs (ADR-059).
            | Both collections carry SELLABLE counts — a menu promising "48" that
            | opens on an empty listing is worse than a menu with no numbers.
            |
            | Slug-addressed and uuid-tolerant, resolved BY SHAPE: a value that is
            | not uuid-shaped never touches a uuid column, which is the 500 this
            | sprint existed to end.
            */
            Route::get('categories', [PublicTaxonomyController::class, 'categories'])
                ->name('storefront.categories.index');
            Route::get('categories/{category}', [PublicTaxonomyController::class, 'category'])
                ->name('storefront.categories.show');

            Route::get('brands', [PublicTaxonomyController::class, 'brands'])
                ->name('storefront.brands.index');
            Route::get('brands/{brand}', [PublicTaxonomyController::class, 'brand'])
                ->name('storefront.brands.show');
        });

        /*
        |------------------------------------------------------------------
        | Authentication — the credential-stuffing surface
        |------------------------------------------------------------------
        |
        | `throttle:auth` is deliberately harsh (5/min, keyed on email AND IP
        | independently) because this is where an attacker spends their time.
        */
        Route::prefix('auth')->name('auth.')->middleware('throttle:auth')->group(function (): void {
            Route::post('/login', [AuthController::class, 'login'])->name('login');
            Route::post('/register', [AuthController::class, 'register'])->name('register');

            /*
            | Password reset. The response NEVER carries a token (ADR-025) —
            | it is emailed. Both routes answer identically whether or not the
            | account exists.
            */
            Route::post('/password/forgot', [PasswordController::class, 'forgot'])->name('password.forgot');
            Route::post('/password/reset', [PasswordController::class, 'reset'])->name('password.reset');

            /*
            | Email verification. The callback is authorised by its SIGNATURE,
            | not a session — the credential was emailed (ADR-025), never
            | returned by an API. The route NAME 'email.verify' must match what
            | VerifyEmailNotification signs.
            */
            Route::post('/email/verify/{uuid}/{hash}', [EmailVerificationController::class, 'verify'])
                ->name('email.verify');
            Route::post('/email/resend', [EmailVerificationController::class, 'resend'])
                ->name('email.resend');

        });

        /*
        | Email-OTP fallback (Q5): public and requested mid-login, but
        | rate-limited like the rest of the credential surface. Registered
        | OUTSIDE the auth/ path prefix so it is served at /two-factor/email-otp
        | — the path the controller documents and a sibling of the enrolment
        | routes — not /auth/two-factor/email-otp. The CODE is emailed, never
        | returned: the one 2FA value ADR-025 applies to.
        */
        Route::post('/two-factor/email-otp', [TwoFactorController::class, 'requestEmailOtp'])
            ->middleware('throttle:auth')
            ->name('two-factor.email-otp');

        /*
        |------------------------------------------------------------------
        | Authenticated
        |------------------------------------------------------------------
        |
        | Sanctum resolves either a bearer token or, for the Next.js app on a
        | stateful origin, the session cookie.
        */
        Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {

            Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
            Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

            /*
            | Self-service profile. No {uuid} — these act on current_actor()
            | only, so editing someone else's account is impossible by
            | construction. Password change verifies the current password.
            */
            Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
            Route::post('/profile/password', [ProfileController::class, 'changePassword'])->name('profile.password');

            // Recognised devices. Bound by UUID; DevicePolicy scopes ownership.
            Route::get('/devices', [DeviceController::class, 'index'])->name('devices.index');
            Route::post('/devices/{device}/trust', [DeviceController::class, 'trust'])->name('devices.trust');
            Route::delete('/devices/{device}', [DeviceController::class, 'destroy'])->name('devices.destroy');

            /*
            | Two-factor enrolment and lifecycle. The secret and recovery codes
            | ARE returned here — they are shown once to the authenticated owner
            | setting them up, which ADR-025 does not cover (see the controller).
            | Disable and regenerate re-prove the password.
            */
            Route::get('/two-factor', [TwoFactorController::class, 'status'])->name('two-factor.status');
            Route::post('/two-factor/enable', [TwoFactorController::class, 'enable'])->name('two-factor.enable');
            Route::post('/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
            Route::delete('/two-factor', [TwoFactorController::class, 'disable'])->name('two-factor.disable');
            Route::post('/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
                ->name('two-factor.recovery-codes');

            // Security page. `sessions` (plural, no parameter) must be
            // registered before `{session}` or Laravel matches the literal
            // path as a UUID parameter.
            Route::get('/sessions', [SessionController::class, 'index'])->name('sessions.index');
            Route::delete('/sessions', [SessionController::class, 'destroyOthers'])->name('sessions.destroy-others');
            Route::delete('/sessions/{session}', [SessionController::class, 'destroy'])->name('sessions.destroy');

            /*
            |------------------------------------------------------------------
            | Organization — seller surface (ADR-030, membership-scoped)
            |------------------------------------------------------------------
            |
            | Plural + org-in-path because a user may belong to several
            | organizations (ADR-030 supersedes the spec's singular /organization).
            | Every route is policy-gated by the actor's membership of THAT org;
            | a member of another org is denied by construction.
            */
            Route::prefix('organizations')->name('organizations.')->group(function (): void {
                Route::get('/', [OrganizationController::class, 'index'])->name('index');
                Route::post('/', [OrganizationController::class, 'store'])->name('store');
                Route::get('/{organization}', [OrganizationController::class, 'show'])->name('show');
                Route::patch('/{organization}', [OrganizationController::class, 'update'])->name('update');
                Route::post('/{organization}/kyc', [OrganizationController::class, 'submitKyc'])->name('kyc');
                Route::post('/{organization}/ownership/transfer', [OrganizationController::class, 'transferOwnership'])
                    ->name('ownership.transfer');

                Route::get('/{organization}/members', [MemberController::class, 'index'])->name('members.index');
                Route::patch('/{organization}/members/{member}', [MemberController::class, 'updateRole'])->name('members.update');
                Route::delete('/{organization}/members/{member}', [MemberController::class, 'remove'])->name('members.remove');
                Route::post('/{organization}/invitations', [MemberController::class, 'invite'])->name('invitations.store');
                Route::delete('/{organization}/invitations/{invitation}', [MemberController::class, 'cancelInvitation'])->name('invitations.cancel');
                Route::post('/{organization}/invitations/{invitation}/resend', [MemberController::class, 'resendInvitation'])->name('invitations.resend');

                Route::get('/{organization}/bank-account', [BankAccountController::class, 'show'])->name('bank.show');
                Route::put('/{organization}/bank-account', [BankAccountController::class, 'update'])->name('bank.update');

                Route::get('/{organization}/documents', [DocumentController::class, 'index'])->name('documents.index');
                Route::post('/{organization}/documents', [DocumentController::class, 'store'])->name('documents.store');

                Route::get('/{organization}/store-requests', [StoreRequestController::class, 'index'])->name('store-requests.index');
                Route::post('/{organization}/store-requests', [StoreRequestController::class, 'store'])->name('store-requests.store');
                Route::post('/{organization}/store-requests/{storeRequest}/submit', [StoreRequestController::class, 'submit'])->name('store-requests.submit');
                Route::post('/{organization}/store-requests/{storeRequest}/cancel', [StoreRequestController::class, 'cancel'])->name('store-requests.cancel');
            });

            /*
            | Invitation acceptance (ADR-031) — the invitee surface. Behind auth:
            | an invitation is only ever accepted by an authenticated account, and
            | the token is a path segment, not a bound model.
            */
            Route::post('/organization-invitations/{token}/accept', [OrganizationInvitationController::class, 'acceptInvitation'])
                ->name('organization-invitations.accept');
            Route::post('/organization-invitations/{token}/reject', [OrganizationInvitationController::class, 'rejectInvitation'])
                ->name('organization-invitations.reject');

            /*
            |------------------------------------------------------------------
            | Store — seller surface (ADR-030/033, membership-scoped)
            |------------------------------------------------------------------
            |
            | `{store}` binds by UUID. Every route is policy-gated by
            | `store.manage` on the store's OWNING organization, resolved through
            | the Core OrganizationAuthorizationContract — a member of another
            | org is denied by construction. `index` is scoped to the actor's
            | organizations, never the whole table. Read-only public rendering is
            | a separate surface entirely (the storefront routes above).
            */
            Route::prefix('stores')->name('stores.')->group(function (): void {
                Route::get('/', [StoreController::class, 'index'])->name('index');
                Route::get('/{store}', [StoreController::class, 'show'])->name('show');
                Route::patch('/{store}', [StoreController::class, 'update'])->name('update');

                Route::patch('/{store}/settings', [StoreProfileController::class, 'updateSettings'])->name('settings');
                Route::patch('/{store}/branding', [StoreProfileController::class, 'updateBranding'])->name('branding');
                Route::patch('/{store}/seo', [StoreProfileController::class, 'updateSeo'])->name('seo');
                Route::patch('/{store}/contact', [StoreProfileController::class, 'updateContact'])->name('contact');
                Route::patch('/{store}/localization', [StoreProfileController::class, 'updateLocalization'])->name('localization');

                Route::post('/{store}/activate', [StoreLifecycleController::class, 'activate'])->name('activate');
                Route::post('/{store}/pause', [StoreLifecycleController::class, 'pause'])->name('pause');
                Route::post('/{store}/resume', [StoreLifecycleController::class, 'resume'])->name('resume');
                Route::post('/{store}/close', [StoreLifecycleController::class, 'close'])->name('close');
            });

            /*
            |------------------------------------------------------------------
            | Order — the CUSTOMER surface (ADR-052…056)
            |------------------------------------------------------------------
            |
            | API-ONLY BY DESIGN. The storefront is a separate Next.js app that
            | does not exist yet (Order.md §0.8), so this ships and waits — the
            | same discipline that had Inventory's reservation contract ship
            | before its caller.
            |
            | AUTHENTICATED CUSTOMERS ONLY (ADR-056, owner decision): no guest
            | checkout in v1, so a basket belongs to an account from the first
            | item and is never migrated from a session at login.
            |
            | NO IDENTIFIER FOR THE BASKET. `/cart` is singular because a customer
            | has exactly one, and exposing an id would create a way to ask for
            | one that is not yours. Lines are addressed by uuid and resolved
            | THROUGH the acting customer's own cart.
            |
            | CHECKOUT AND PLACE ARE TWO CALLS (ADR-054). The first reserves and
            | splits; the second commits. Between them is the reservation window a
            | payment step will occupy, and this shape does not change when it
            | arrives.
            */
            Route::get('/cart', [CartController::class, 'show'])->name('cart.show');
            Route::post('/cart/items', [CartController::class, 'store'])->name('cart.items.store');
            Route::patch('/cart/items/{item}', [CartController::class, 'update'])->name('cart.items.update');
            Route::delete('/cart/items/{item}', [CartController::class, 'destroy'])->name('cart.items.destroy');

            Route::prefix('addresses')->name('addresses.')->group(function (): void {
                Route::get('/', [CustomerAddressController::class, 'index'])->name('index');
                Route::post('/', [CustomerAddressController::class, 'store'])->name('store');
                Route::patch('/{address}', [CustomerAddressController::class, 'update'])->name('update');
                Route::delete('/{address}', [CustomerAddressController::class, 'destroy'])->name('destroy');
                Route::post('/{address}/default', [CustomerAddressController::class, 'setDefault'])->name('default');
            });

            Route::post('/checkout', [CustomerOrderController::class, 'checkout'])->name('checkout');
            Route::post('/checkout/{group}/place', [CustomerOrderController::class, 'place'])->name('checkout.place');

            Route::get('/orders', [CustomerOrderController::class, 'index'])->name('orders.index');
            Route::get('/orders/{order}', [CustomerOrderController::class, 'show'])->name('orders.show');
            // PER ORDER, not per group: each seller's half of a purchase is
            // independently fulfilled, so each is independently cancellable.
            Route::post('/orders/{order}/cancel', [CustomerOrderController::class, 'cancel'])->name('orders.cancel');
        });

        /*
        |------------------------------------------------------------------
        | Admin — account administration (Phase 8)
        |------------------------------------------------------------------
        |
        | `throttle:panel` rather than `throttle:api`: these are operator tools,
        | used at human pace behind a permission wall, not the storefront's hot
        | path. Every route is policy-guarded — the permission lives in
        | UserPolicy, never as a `can:` middleware here that could drift from it.
        |
        | `{user}` binds the base User by UUID (HasUuid::getRouteKeyName), across
        | all actor types: guard isolation is an authentication property, and an
        | admin holding `user.*` administers every type. Impersonation is Q4,
        | deferred to Phase 9.
        */
        Route::middleware(['auth:sanctum', 'throttle:panel'])
            ->prefix('admin')->name('admin.')
            ->group(function (): void {
                Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
                Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
                Route::patch('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
                Route::post('/users/{user}/reset-password', [AdminUserController::class, 'resetPassword'])
                    ->name('users.reset-password');
                Route::delete('/users/{user}/two-factor', [AdminUserController::class, 'disableTwoFactor'])
                    ->name('users.two-factor.disable');
                Route::get('/users/{user}/login-history', [AdminUserController::class, 'loginHistory'])
                    ->name('users.login-history');

                /*
                | Organization administration (ADR-028) — the KYC/lifecycle queue
                | and the Store Opening Request queue. Policy-gated per action.
                */
                Route::get('/organizations', [AdminOrganizationController::class, 'index'])->name('organizations.index');
                Route::get('/organizations/{organization}', [AdminOrganizationController::class, 'show'])->name('organizations.show');
                Route::post('/organizations/{organization}/approve', [AdminOrganizationController::class, 'approve'])->name('organizations.approve');
                Route::post('/organizations/{organization}/reject', [AdminOrganizationController::class, 'reject'])->name('organizations.reject');
                Route::post('/organizations/{organization}/suspend', [AdminOrganizationController::class, 'suspend'])->name('organizations.suspend');
                Route::post('/organizations/{organization}/reinstate', [AdminOrganizationController::class, 'reinstate'])->name('organizations.reinstate');
                Route::patch('/organizations/{organization}/store-limit', [AdminOrganizationController::class, 'setStoreLimit'])->name('organizations.store-limit');
                Route::post('/organizations/{organization}/documents/{document}/review', [AdminOrganizationController::class, 'reviewDocument'])->name('organizations.documents.review');

                Route::get('/store-requests', [AdminStoreRequestController::class, 'index'])->name('store-requests.index');
                Route::post('/store-requests/{storeRequest}/approve', [AdminStoreRequestController::class, 'approve'])->name('store-requests.approve');
                Route::post('/store-requests/{storeRequest}/reject', [AdminStoreRequestController::class, 'reject'])->name('store-requests.reject');

                /*
                | Store administration (ADR-034) — PLATFORM-LEVEL ONLY. Admins
                | view and enforce (suspend / reinstate / archive); they never
                | manage a store's content — that is the seller's surface. Each
                | route is policy-gated by a `store.*` permission.
                */
                Route::get('/stores', [AdminStoreController::class, 'index'])->name('stores.index');
                Route::get('/stores/{store}', [AdminStoreController::class, 'show'])->name('stores.show');
                Route::post('/stores/{store}/suspend', [AdminStoreController::class, 'suspend'])->name('stores.suspend');
                Route::post('/stores/{store}/reinstate', [AdminStoreController::class, 'reinstate'])->name('stores.reinstate');
                Route::post('/stores/{store}/archive', [AdminStoreController::class, 'archive'])->name('stores.archive');
            });

        /*
        |------------------------------------------------------------------
        | Search — tighter limit
        |------------------------------------------------------------------
        |
        | Every call reaches OpenSearch and is the cheapest way to load the
        | cluster from outside. Populated by the Catalogue module.
        */
        Route::middleware('throttle:search')->group(function (): void {
            //
        });
    });
