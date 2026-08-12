<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Localization\Domain\Contracts\LanguageRepositoryContract;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Localization\Domain\Models\Language;
use App\Modules\Settings\Application\Services\SettingsService;

/**
 * Global helpers.
 *
 * Kept deliberately tiny. A helper earns its place here only when it is needed
 * in Blade templates, where importing a class is awkward. Everything else
 * belongs on a class where it can be typed, mocked and tested.
 *
 * Every function is guarded so the file is safe to autoload more than once.
 */
if (! function_exists('money')) {
    /**
     * Format an integer amount of minor units as currency.
     *
     *     money(149900)         => "1.499,00 ₺"
     *     money(1999, 'USD')    => "$19.99"
     *
     * Currency became a lookup table in Sprint 1, so the second argument is an
     * ISO code or a model rather than an enum case. Falls back to the platform
     * default currency when the code is unknown — a mistyped code should not
     * render a price as an exception.
     */
    function money(int $minorAmount, Currency|string|null $currency = null): string
    {
        // ADR-019: cached lookups live behind the repository, not on the model.
        // Resolving from the container here is fine — helpers are not Domain.
        $repository = app(CurrencyRepositoryContract::class);

        $resolved = match (true) {
            $currency instanceof Currency => $currency,
            is_string($currency) => $repository->findByCode($currency),
            default => null,
        } ?? $repository->default();

        return $resolved->format($minorAmount);
    }
}

if (! function_exists('current_language')) {
    /**
     * The active language row, not just the locale string.
     */
    function current_language(): Language
    {
        return app(LanguageRepositoryContract::class)->current();
    }
}

if (! function_exists('current_currency')) {
    /**
     * The authenticated user's display currency, or the platform default.
     */
    function current_currency(): Currency
    {
        return current_actor()?->preferredCurrency()
            ?? app(CurrencyRepositoryContract::class)->default();
    }
}

if (! function_exists('current_actor')) {
    /**
     * The authenticated user across whichever guard is active, or null. Use
     * instead of auth()->user(), which silently resolves only the default guard
     * and therefore returns null for a logged-in seller.
     *
     * **THE TOKEN GUARDS ARE IN THE LIST BECAUSE A BEARER TOKEN POPULATES NO
     * NAMED GUARD** (ADR-076). A session request fills `admin`/`seller`/
     * `customer`; a token request fills only the sanctum guard that authenticated
     * it. Without them, a seller pushing their price feed authenticated
     * perfectly well and then read as nobody — every policy denying, every
     * ownership check failing, for a request that was correctly signed.
     *
     * **THE ORDER IS SESSION FIRST, AND IT MATTERS.** A Filament page and an API
     * call can share a browser; resolving the session actor first keeps the panel
     * reading the person who is looking at it rather than whichever token was
     * last presented.
     *
     * **WHAT COMES BACK IS A REAL ACTOR SUBCLASS**, so `->type` answers correctly
     * and every policy, org scope and guard-name check downstream behaves exactly
     * as it does for a session — the token changes how you arrive, never who you
     * are.
     */
    function current_actor(): ?User
    {
        foreach (['admin', 'seller', 'customer', 'sanctum', 'sanctum_seller'] as $guard) {
            $user = auth()->guard($guard)->user();

            if ($user instanceof User) {
                return $user;
            }
        }

        return null;
    }
}

if (! function_exists('settings')) {
    /**
     * Read a platform setting.
     *
     *     settings('company.name')
     *     settings('checkout.guest_enabled', false)
     *     settings()->boolean('checkout.guest_enabled')
     *
     * Called with no arguments it returns the service, so a caller who wants a
     * typed accessor is not forced to inject it.
     *
     * NOTE: this reads BUSINESS configuration, not application config. A value
     * needed before the framework boots belongs in config/*.php — reading it
     * here requires the database connection that config already defined.
     */
    function settings(?string $key = null, mixed $default = null): mixed
    {
        $service = app(SettingsService::class);

        return $key === null ? $service : $service->get($key, $default);
    }
}

if (! function_exists('correlation_id')) {
    /**
     * The id tying together every log line, event and job for one request.
     */
    function correlation_id(): string
    {
        return app()->bound('correlation_id') && is_string(app('correlation_id'))
            ? app('correlation_id')
            : '';
    }
}
