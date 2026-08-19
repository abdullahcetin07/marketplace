<?php

declare(strict_types=1);

use App\Core\Presentation\Middleware\SetLocale;
use App\Modules\Catalog\Presentation\Controllers\Api\Storefront\PublicProductController;
use App\Modules\Catalog\Presentation\Controllers\Api\Storefront\PublicProductRankingController;
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
use App\Modules\Loyalty\Presentation\Controllers\Api\Customer\LoyaltyController;
use App\Modules\Loyalty\Presentation\Controllers\Api\Storefront\EarnPreviewController;
use App\Modules\Offer\Presentation\Controllers\Api\Seller\OfferFeedController;
use App\Modules\Offer\Presentation\Controllers\Api\Storefront\PublicBuyBoxPriceController;
use App\Modules\Offer\Presentation\Controllers\Api\Storefront\PublicProductOfferController;
use App\Modules\Order\Presentation\Controllers\Api\CancellationRequestController;
use App\Modules\Order\Presentation\Controllers\Api\CartController;
use App\Modules\Order\Presentation\Controllers\Api\CustomerAddressController;
use App\Modules\Order\Presentation\Controllers\Api\CustomerOrderController;
use App\Modules\Order\Presentation\Controllers\Api\ReturnRequestController;
use App\Modules\Organization\Presentation\Controllers\Api\Admin\OrganizationController as AdminOrganizationController;
use App\Modules\Organization\Presentation\Controllers\Api\Admin\StoreRequestController as AdminStoreRequestController;
use App\Modules\Organization\Presentation\Controllers\Api\BankAccountController;
use App\Modules\Organization\Presentation\Controllers\Api\DocumentController;
use App\Modules\Organization\Presentation\Controllers\Api\InvitationController as OrganizationInvitationController;
use App\Modules\Organization\Presentation\Controllers\Api\MemberController;
use App\Modules\Organization\Presentation\Controllers\Api\OrganizationController;
use App\Modules\Organization\Presentation\Controllers\Api\StoreRequestController;
use App\Modules\Payment\Presentation\Controllers\Api\PaymentController;
use App\Modules\Payment\Presentation\Controllers\Api\PayoutController;
use App\Modules\Payment\Presentation\Controllers\Api\PayTrCallbackController;
use App\Modules\Payment\Presentation\Controllers\Api\RefundController;
use App\Modules\Payment\Presentation\Controllers\Api\ReturnController;
use App\Modules\Questions\Presentation\Controllers\Api\CustomerQuestionController;
use App\Modules\Questions\Presentation\Controllers\Api\Storefront\PublicProductQuestionController;
use App\Modules\Reviews\Presentation\Controllers\Api\CustomerReviewController;
use App\Modules\Reviews\Presentation\Controllers\Api\Storefront\ProductRatingsController;
use App\Modules\Reviews\Presentation\Controllers\Api\Storefront\PublicProductReviewController;
use App\Modules\Shipping\Presentation\Controllers\Api\ShipmentController;
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
            | THE PSP'S SERVER-TO-SERVER CALLBACK (ADR-060, Payment.md §3).
            |
            | UNAUTHENTICATED, AND IT HAS TO BE — PayTR has no session and no
            | token. The security model is the HASH: the gateway recomputes it
            | over the posted fields with the merchant key and salt, and a payload
            | that does not verify changes nothing.
            |
            | IT ALWAYS ANSWERS "OK", in plain text, whatever happened. PayTR
            | retries anything else for days, so a 500 on a payload we will never
            | accept becomes a retry storm, and a 422 on a duplicate makes it
            | re-send a payment already settled.
            |
            | ON THE API THROTTLE, not the storefront one: this is machine traffic
            | from one known source, and it must not share an allowance with the
            | anonymous browsing that a crawler can exhaust.
            */
            Route::post('/payments/paytr/callback', PayTrCallbackController::class)
                ->name('payments.paytr.callback');

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
            /*
            | A PRODUCT'S PUBLISHED REVIEWS AND ITS STARS (ADR-069, Reviews §7).
            |
            | DECLARED BEFORE `products/{product}` for the reason stated above:
            | the catch-all would otherwise swallow `/reviews` and the section
            | would 404 — the same trap `/offers` sits in front of.
            |
            | `data` is the reviews so pagination means what it says; the
            | SUMMARY rides in `meta`, where it is not paginated and cannot be
            | mistaken for a page of results. It describes the WHOLE product, not
            | the filtered page: a shopper narrowing to one seller still sees the
            | product's real average, because the filter narrows what they read
            | rather than what the product is.
            */
            Route::get('products/{product}/reviews', [PublicProductReviewController::class, 'index'])
                ->name('storefront.product.reviews');

            /*
            | A PRODUCT'S ANSWERED Q&A (ADR-070, Questions §8). Declared before
            | the `products/{product}` catch-all for the reason `/offers` and
            | `/reviews` are.
            |
            | NO SUMMARY IN `meta`, unlike the reviews endpoint beside it: a
            | question has no rating to roll up, so `data` is the Q&A and the
            | only meta is pagination. An UNANSWERED question is never here —
            | publishing one early would put a shopper's words on a page before
            | the merchant they were aimed at had seen them.
            */
            Route::get('products/{product}/questions', [PublicProductQuestionController::class, 'index'])
                ->name('storefront.product.questions');

            /*
            | THE THREE COMPUTED STRIPS (ADR-077, ADR-078).
            |
            | **DECLARED BEFORE `products/{product}`, LIKE EVERY SIBLING ABOVE.**
            | `best-sellers` and `most-reviewed` are literal paths, so the
            | catch-all would read them as a slug and answer 404 for a product
            | nobody named that.
            |
            | Each is computed on read from paid orders or published reviews, and
            | each answers `[]` with a 200 on a shop's first day. The storefront
            | hides a strip that returns nothing and shows it the moment it fills,
            | so an empty list is the contract rather than a failure.
            */
            Route::get('products/best-sellers', [PublicProductRankingController::class, 'bestSellers'])
                ->name('storefront.products.best-sellers');
            Route::get('products/most-reviewed', [PublicProductRankingController::class, 'mostReviewed'])
                ->name('storefront.products.most-reviewed');
            Route::get('products/{product}/also-bought', [PublicProductRankingController::class, 'alsoBought'])
                ->name('storefront.product.also-bought');

            /*
            | "BU ÜRÜNÜ ALINCA X PUAN KAZAN" (ADR-082). Public, because the product
            | page is: a signed-out shopper is exactly who the card is arguing with.
            | It reads only `settings()` — no ledger, no customer, no cart.
            |
            | The backend does the arithmetic so the storefront never multiplies a
            | price string by a rate in JavaScript floats, and so the preview cannot
            | disagree with what the nightly sweep actually credits.
            */
            /*
            | EVERY LIVE SHOP'S SLUG, FOR THE SITEMAP. Public and read-only: the
            | storefront cannot enumerate `/magaza/*` without it, so those pages
            | were simply absent from the sitemap. Same visibility rule the store
            | page itself uses — a suspended shop 404s there and must not be
            | advertised here.
            |
            | **`magazalar`, NOT `stores`, AND THE COLLISION IS THE REASON.**
            | `GET /api/v1/stores` already belongs to the SELLER panel — a
            | merchant's own shops, behind `auth:sanctum`. Two routes with one
            | method and URI do not both survive registration: the later one wins
            | the lookup, so publishing this as `stores` silently took the seller
            | endpoint's place (or, as it happened, lost to it and answered 401).
            | The public surface is Turkish-segmented anyway — `/magaza/{slug}` is
            | the page this enumerates.
            */
            Route::get('magazalar', [PublicStoreController::class, 'index'])
                ->name('storefront.stores.index');

            Route::get('loyalty/earn-preview', [EarnPreviewController::class, 'show'])
                ->name('storefront.loyalty.earn-preview');

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
            | THE STAR HALF OF THE SAME GRID (ADR-069) — the exact mirror of the
            | price call above, and a POST for the same reason: forty uuids do
            | not belong in a query string.
            |
            | AN UNREVIEWED PRODUCT IS ABSENT FROM THE ANSWER, never `"0.0"`. A
            | card handed a zero renders "★ 0.0", which reads as "rated badly"
            | rather than "not rated yet" — the client's own no-rating branch is
            | the correct rendering, and omission is what triggers it.
            */
            Route::post('products/ratings', [ProductRatingsController::class, 'batch'])
                ->name('storefront.products.ratings');

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
        | The seller offer feed (ADR-076) — price + stock ingress
        |------------------------------------------------------------------
        |
        | **OUTSIDE THE `auth:sanctum` GROUP ABOVE, DELIBERATELY.** Route
        | middleware ACCUMULATES: nesting these inside it would run
        | `auth:sanctum` first, and that guard is bound to the `customers`
        | provider — a seller's bearer token authenticates against the token
        | table and is then refused by `Sanctum::hasValidProvider()`. The
        | second guard would never be reached. So this is a sibling group, not
        | a child, and the nesting is the load-bearing part.
        |
        | **GUARD ISOLATION IS NOW ENFORCED BY THE GUARD ITSELF**: an admin or
        | customer token cannot authenticate here at all, so it never reaches a
        | policy to be denied by one. 401, not 403 — the stronger answer.
        |
        | **A BATCH IS A REPORT**: every call answers 200 with a per-item result
        | even when some items failed, because a seller pushing four thousand
        | SKUs needs to know which forty were rejected. 422 is for a malformed
        | request or one over `offer.feed.max_batch`.
        |
        | THE MERCHANT IS NEVER IN THE PAYLOAD. It is resolved from the token's
        | user, so there is no field for a token to name somebody else's shop.
        */
        Route::middleware(['auth:sanctum_seller', 'throttle:api'])
            ->prefix('seller/offers')
            ->name('seller.offers.')
            ->group(function (): void {
                Route::post('/sync', [OfferFeedController::class, 'sync'])->name('sync');
                Route::post('/stock', [OfferFeedController::class, 'stock'])->name('stock');
                Route::post('/withdraw', [OfferFeedController::class, 'withdraw'])->name('withdraw');
            });

        /*
        |------------------------------------------------------------------
        | Authenticated
        |------------------------------------------------------------------
        |
        | Sanctum resolves either a bearer token or, for the Next.js app on a
        | stateful origin, the session cookie.
        */
        Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {

            /*
            | "PUANLARIM" (ADR-081). Balance and history for the SIGNED-IN
            | customer — there is no `{customer}` in either path and no id in the
            | query, so reading somebody else's ledger is not denied but
            | unexpressible. Under `auth:sanctum` like every other self-service
            | route; the controller refuses a seller or an admin outright rather
            | than showing them an empty balance they were never eligible for.
            */
            Route::get('/loyalty/balance', [LoyaltyController::class, 'balance'])->name('loyalty.balance');
            Route::get('/loyalty/ledger', [LoyaltyController::class, 'ledger'])->name('loyalty.ledger');
            /*
            | THE REDEMPTION PREVIEW (ADR-084). POST because it carries a body, not
            | because it changes anything — nothing moves here. The checkout page
            | calls it on every drag of the "Puanını kullan" slider.
            */
            Route::post('/loyalty/redeem/quote', [LoyaltyController::class, 'quote'])->name('loyalty.redeem.quote');

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

            /*
            | PAYING FOR A CHECKOUT GROUP (ADR-060, Payment.md §3).
            |
            | ONE CHARGE FOR THE WHOLE BASKET, which is why the route is keyed to
            | the GROUP and not to an order: Order split the basket so N sellers
            | could fulfil independently (ADR-052), and a card is charged once.
            |
            | IT TAKES NO AMOUNT. The total is summed from the orders the group
            | already holds — a client-supplied amount is the oldest vulnerability
            | in e-commerce, and there is no field here that could carry one.
            |
            | The response is a TOKEN, not a redirect: the storefront embeds
            | PayTR's iframe with it, so the card and the 3-D Secure step never
            | touch this application.
            */
            Route::post('/checkout/{group}/pay', [PaymentController::class, 'store'])->name('checkout.pay');

            /*
            | What the result page asks after PayTR sends the browser back. The
            | answer comes from what the SERVER learned on the callback — the
            | redirect's query string is whatever the browser was handed.
            */
            Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');

            /*
            | THE PARCEL, AND THE ONE BUTTON THE BUYER HAS (ADR-064,
            | Shipping.md §6). Both are keyed on the ORDER, which the customer
            | already holds — making them fetch a shipment uuid first would put a
            | second identifier on a public surface for no benefit.
            |
            | `confirm` is "Teslim aldım", and it is one of exactly TWO things on
            | this platform that can set a delivery date; the other is the transit
            | sweep. There is no seller or admin route that writes one, because
            | payout waits on delivery and a seller asserting it would be
            | asserting their own payday.
            */
            Route::get('/orders/{order}/shipment', [ShipmentController::class, 'show'])
                ->name('orders.shipment');
            Route::post('/orders/{order}/shipment/confirm', [ShipmentController::class, 'confirm'])
                ->name('orders.shipment.confirm');

            /*
            | SENDING IT BACK (S4, Payment.md §8). Keyed on the order for the same
            | reason the two routes above are, and sitting beside them on purpose:
            | the return window opens when that `confirm` fires, so the button the
            | buyer presses second is next to the one they pressed first.
            |
            | THE GET IS NOT DECORATION. It answers whether the window is still
            | open, how many of each line have already gone back, and what the
            | platform will pay for the rest — the last of which a storefront
            | cannot compute, because the final unit of a line carries whatever
            | kuruş the rounding left.
            |
            | **THE POST THAT USED TO SIT HERE IS GONE (ADR-073).** It refunded on
            | the spot, because ADR-064 treated the window as the approval — for
            | physical goods that is paying out on trust. The buyer's write is now
            | `POST /orders/{order}/return-request` further down, which moves no
            | money; the refund fires when the SELLER confirms the parcel is back.
            |
            | THE GET SURVIVES UNCHANGED and is what that request form is built
            | from. The two are not redundant: this one answers "what may still go
            | back, priced, and until when", and the request endpoint answers "what
            | did I ask for and what came of it".
            */
            Route::get('/orders/{order}/return', [ReturnController::class, 'show'])
                ->name('orders.return.show');

            /*
            | ASKING TO CANCEL BEFORE IT SHIPS (ADR-065, C2) — the near half of
            | the lifecycle, where the return above is the far half.
            |
            | **THE POST DOES NOT CANCEL ANYTHING.** It creates a `pending`
            | request and the order does not move: a paid order may already be
            | picked and packed, and the buyer does not get to yank it out from
            | under the person doing that work. The storefront must say "satıcı
            | onayında" rather than confirm a cancellation that has not happened
            | — ADR-065 names that as this feature's cost.
            |
            | The seller's approve/reject is the panel inbox, deliberately not a
            | branch on these routes: they are two halves of an argument, and one
            | surface serving both is one where either can act as the other.
            */
            /*
            | THE BUYER'S OWN REVIEWS (ADR-067/068, Reviews §8).
            |
            | `eligible` DRAWS THE FORM; IT DOES NOT AUTHORISE IT. It answers
            | which delivered purchases are still unreviewed, so the screen can
            | name WHICH one it is offering — a shopper who bought the same thing
            | twice needs to know. `POST /reviews` re-verifies every line
            | server-side against the Core Order port, so the two disagreeing
            | means the form was wrong, never that the gate was.
            |
            | THE 201 SAYS `pending_review`. Nothing a buyer writes is visible to
            | anybody else until a moderator publishes it, and the UI has to say
            | "onay bekliyor" rather than congratulate them.
            |
            | THERE IS NO PATCH. A review is deleted and rewritten (§8) — an
            | editable one lets somebody earn credibility with five stars and
            | then rewrite it, with every earlier reader none the wiser.
            */
            /*
            | "SATICIYA SOR" (ADR-070, Questions §8).
            |
            | THERE IS NO `eligible` ROUTE HERE, and the absence is the module:
            | Reviews needs one because a review binds to a delivered purchase
            | and the form must name which. A question needs no purchase at all —
            | the shopper is standing on the product page, so posting the product
            | is the whole flow.
            |
            | THE 201 SAYS `pending`. Only the merchant's answer publishes it, so
            | the UI says "sorunuz satıcıya iletildi" rather than showing it live.
            |
            | AND NO SELLER FIELD: the target is the buy-box winner, read and
            | frozen on the server, so a hostile question cannot be aimed at a
            | merchant who never sold the thing.
            */
            Route::get('/questions/mine', [CustomerQuestionController::class, 'mine'])
                ->name('questions.mine');
            Route::post('/questions', [CustomerQuestionController::class, 'store'])
                ->name('questions.store');
            Route::delete('/questions/{question}', [CustomerQuestionController::class, 'destroy'])
                ->name('questions.destroy');

            Route::get('/reviews/eligible', [CustomerReviewController::class, 'eligible'])
                ->name('reviews.eligible');
            Route::get('/reviews/mine', [CustomerReviewController::class, 'mine'])
                ->name('reviews.mine');
            Route::post('/reviews', [CustomerReviewController::class, 'store'])
                ->name('reviews.store');
            Route::delete('/reviews/{review}', [CustomerReviewController::class, 'destroy'])
                ->name('reviews.destroy');

            Route::post('/orders/{order}/cancellation-request', [CancellationRequestController::class, 'store'])
                ->name('orders.cancellation-request.store');
            Route::get('/orders/{order}/cancellation-request', [CancellationRequestController::class, 'show'])
                ->name('orders.cancellation-request.show');

            /*
            | ASKING TO SEND IT BACK AFTER DELIVERY (ADR-073) — the far half of
            | the lifecycle, and now the same SHAPE as the near half: the buyer
            | asks, the seller answers, and only the seller's confirmation moves
            | money. **The 201 is "talep alındı", not "iade edildi"**; the
            | storefront says "satıcı onayında".
            */
            Route::post('/orders/{order}/return-request', [ReturnRequestController::class, 'store'])
                ->name('orders.return-request.store');
            Route::get('/orders/{order}/return-request', [ReturnRequestController::class, 'show'])
                ->name('orders.return-request.show');
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
                | PAYOUTS (ADR-062, Payment.md §8). Admin-only: there is no
                | seller-facing payout endpoint in v1 — a merchant sees their
                | BALANCE, and the platform's transfer records are its own.
                |
                | NOTHING HERE MOVES MONEY. `store` records that an admin decided
                | to send a seller their balance (and debits it, so the same lira
                | cannot be promised twice); `settle` records what the bank did
                | afterwards. A human makes the transfer.
                |
                | ONE `settle` ROUTE FOR BOTH OUTCOMES rather than `/pay` and
                | `/fail`: it is one decision — "what did the bank say" — and
                | splitting it would let a client mark something paid without ever
                | having considered that it might not have been.
                */
                Route::get('/payouts', [PayoutController::class, 'index'])->name('payouts.index');
                Route::post('/payouts', [PayoutController::class, 'store'])->name('payouts.store');
                Route::post('/payouts/{payout}/settle', [PayoutController::class, 'settle'])
                    ->name('payouts.settle');

                // The number a payout is checked against, so an admin can see
                // what they may send before trying to send it.
                Route::get('/sellers/{seller}/balance', [PayoutController::class, 'balance'])
                    ->name('sellers.balance');

                /*
                | REFUNDS (Payment.md §8, P5) — the one endpoint on this platform
                | that moves real money OUT. A payout only records a transfer a
                | human made and the callback only records what a buyer did; a POST
                | here makes PayTR send money back, debits the seller and puts the
                | units on the shelf again.
                |
                | IT TAKES ORDERS, NOT AN AMOUNT: a refund names one seller's order,
                | which is the unit the ledger is keyed on and the unit whose stock
                | can be restocked. An empty list means the whole payment.
                |
                | Admin-only in v1. The spec also allows a policy-permitted customer
                | cancel; that half waits for a fulfilment state to exist to judge
                | it by — see `PaymentPolicy::refund()`.
                */
                Route::post('/payments/{payment}/refund', [RefundController::class, 'store'])
                    ->name('payments.refund');
                Route::get('/payments/{payment}/refunds', [RefundController::class, 'index'])
                    ->name('payments.refunds');

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
