<?php

declare(strict_types=1);

namespace App\Modules\Marketing;

use App\Modules\Marketing\Application\Listeners\SendMetaPurchase;
use App\Modules\Marketing\Domain\Contracts\ConversionsApiContract;
use App\Modules\Marketing\Infrastructure\MetaConversionsApiClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Marketing — outbound ad-platform integrations (Marketing.md).
 *
 * **IT IMPORTS NO MODULE.** It reacts to `PaymentSucceeded` BY CLASS-STRING (the
 * literal below, never a `use` of Payment) and reads everything through
 * `OrderQueryContract`, a Core port. `LayeringTest` stays green in both
 * directions — the same discipline Offer/Inventory/Order keep.
 *
 * **REGISTER IT AFTER PAYMENT** in `bootstrap/providers.php`. Order is not
 * strictly required for a class-string subscription, but it keeps the intent
 * legible: Marketing observes money, it does not produce it.
 *
 * The Conversions API is INERT until `config('marketing.meta.enabled')` is true
 * and a token is set — the listener and the HTTP client both gate on it.
 */
final class MarketingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ConversionsApiContract::class, MetaConversionsApiClient::class);
    }

    public function boot(): void
    {
        // BY CLASS-STRING (Payment.md §3): the event class is named, never imported.
        Event::listen(
            'App\\Modules\\Payment\\Domain\\Events\\PaymentSucceeded',
            [SendMetaPurchase::class, 'handle'],
        );
    }
}
