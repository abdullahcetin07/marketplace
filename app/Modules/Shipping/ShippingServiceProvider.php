<?php

declare(strict_types=1);

namespace App\Modules\Shipping;

use App\Modules\Shipping\Application\Listeners\CreateShipmentsOnPayment;
use App\Modules\Shipping\Domain\Models\CargoCompany;
use App\Modules\Shipping\Domain\Models\Shipment;
use App\Modules\Shipping\Presentation\Console\BackfillShipmentsCommand;
use App\Modules\Shipping\Presentation\Policies\CargoCompanyPolicy;
use App\Modules\Shipping\Presentation\Policies\ShipmentPolicy;
use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Shipping module wiring.
 *
 * THE MODULE THAT TURNS A PAID ORDER INTO A DELIVERY DATE (ADR-063/064). One
 * parcel per paid order, the seller marks it shipped with a carrier and a tracking
 * number, and delivery is INFERRED — never asserted by the seller, because payout
 * waits on it.
 *
 * IT IMPORTS NO MODULE, the sixth module to keep the platform's strictest
 * boundary. Orders arrive through `OrderQueryContract`; it learns that money
 * arrived by subscribing to Payment's event BY CLASS-STRING, from its own side;
 * and any future carrier integration will sit behind a provider-agnostic port that
 * has no implementation in v1. `LayeringTest` fails the build on any import, both
 * directions.
 *
 * NO MONEY ANYWHERE. v1 charges no shipping fee (ADR-063), so this module writes
 * no price, no KDV and no commission — and the minor-units rule does not apply to
 * it at all. It counts parcels, not kuruş.
 *
 * WHAT IT IS NOT: the checkout (Order), the money (Payment), a label printer or a
 * carrier API client. It records who has to send what, and when it arrived.
 *
 * @see docs/modules/Shipping.md
 */
final class ShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerPermissions();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Shipping/migrations'));

        /*
        | REGISTERED EXPLICITLY, because Laravel only auto-discovers commands in
        | `app/Console/Commands` and a module's CLI belongs to the module. Console
        | is a delivery mechanism like HTTP, hence `Presentation/Console`.
        */
        if ($this->app->runningInConsole()) {
            $this->commands([BackfillShipmentsCommand::class]);
        }

        Gate::policy(Shipment::class, ShipmentPolicy::class);
        Gate::policy(CargoCompany::class, CargoCompanyPolicy::class);

        /*
        | A PAID ORDER BECOMES A PARCEL (ADR-063).
        |
        | BY CLASS-STRING, so Shipping imports nothing from Payment — the platform's
        | standard for a cross-module event, and the reason `LayeringTest` stays
        | green in both directions. The cost is stated where the listener lives: a
        | rename in Payment breaks this at RUNTIME, and a feature test firing the
        | real callback is what bounds it.
        */
        Event::listen(
            'App\Modules\Payment\Domain\Events\PaymentSucceeded',
            [CreateShipmentsOnPayment::class, 'handle'],
        );
    }

    /**
     * Permissions are DERIVED from a registration, never hand-listed.
     *
     * `shipment` IS READ-ONLY FOR ADMINS, and that is not an omission. The one
     * write in this module — "kargoya ver" — belongs to the seller who is holding
     * the box, and it is authorized by MEMBERSHIP rather than by a permission
     * (`ShipmentPolicy::ship()`), the same shape every seller-facing write on this
     * platform uses. There is deliberately no `shipment.deliver` ability for
     * anybody: delivery is a fact the buyer or the clock establishes, not a
     * privilege (ADR-064), and an ability would invite somebody to grant it.
     *
     * `cargo_company` GETS THE FULL VERB SET, because it is the operator-owned
     * lookup table — a new carrier or a changed tracking URL without a release is
     * the entire reason it is a table (ADR-015).
     */
    private function registerPermissions(): void
    {
        PermissionRegistry::ability('shipment.view_any', [UserType::Admin]);
        PermissionRegistry::ability('shipment.view', [UserType::Admin]);

        PermissionRegistry::resource('cargo_company', [UserType::Admin]);
    }
}
