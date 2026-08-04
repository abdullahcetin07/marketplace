<?php

declare(strict_types=1);

namespace App\Modules\Payment;

use App\Core\Domain\Contracts\CommissionQueryContract;
use App\Modules\Payment\Application\Listeners\CreditSellerLedger;
use App\Modules\Payment\Domain\Contracts\PaymentGatewayContract;
use App\Modules\Payment\Domain\Events\PaymentSucceeded;
use App\Modules\Payment\Domain\Models\CommissionRule;
use App\Modules\Payment\Infrastructure\Gateways\PayTrGateway;
use App\Modules\Payment\Infrastructure\Queries\CommissionQuery;
use App\Modules\Payment\Presentation\Policies\CommissionRulePolicy;
use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Payment module wiring.
 *
 * THE MODULE THAT TAKES MONEY (ADR-060–062). It turns a placed-but-unpaid checkout
 * group into a collected payment, commits the stock that placement only held
 * (ADR-057 — **this module keeps that promise**), and from P2/P3 splits the money
 * into what the platform keeps and what each seller is owed.
 *
 * IT IMPORTS NO MODULE, the strictest boundary on the platform and the fifth
 * module to keep it. Orders arrive through `OrderQueryContract`; the stock commit
 * goes out through `InventoryReservationContract` — the Core command port Order
 * already drives; the PSP is behind `PaymentGatewayContract`; and Order learns
 * that money arrived by subscribing to `PaymentSucceeded` BY CLASS-STRING, from
 * its own side. `LayeringTest` fails the build on any import, both directions.
 *
 * THE GATEWAY IS THE ONLY BINDING THAT MATTERS HERE, and it is bound to an
 * interface in Core rather than to PayTR anywhere else. A second PSP is a second
 * class in `Infrastructure/Gateways` and one line in this file; the domain does
 * not change, because the domain has never heard of PayTR.
 *
 * NO CARD DATA EXISTS ANYWHERE IN THIS MODULE. The buyer's card and its 3-D Secure
 * step happen inside the provider's iframe; there is no column, no DTO field and
 * no log line that could hold a PAN.
 *
 * WHAT THIS MODULE IS NOT: the checkout (Order owns the split and the
 * reservation), shipping, invoicing, or a licensed payment institution. It
 * collects, records what is owed, and remembers the payouts a human made.
 *
 * @see docs/modules/Payment.md
 */
final class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
        | THE PROVIDER-AGNOSTIC PORT (ADR-060). Resolved from config rather than
        | hard-bound so a sandbox, a second PSP or a fake in tests is a
        | configuration change — but the DEFAULT is the real gateway, because a
        | payment module whose default binding is a stub is one that silently
        | takes no money.
        */
        $this->app->singleton(PaymentGatewayContract::class, function (): PaymentGatewayContract {
            return match ((string) config('payment.gateway', 'paytr')) {
                default => new PayTrGateway,
            };
        });

        /*
        | THE COMMISSION PORT (ADR-061). Payment owns the rules; Order owns the
        | lines the answer is frozen onto, and neither may import the other — so
        | the rules answer through Core and Order writes its own table.
        */
        $this->app->singleton(CommissionQueryContract::class, CommissionQuery::class);

        $this->registerPermissions();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Payment/migrations'));

        Gate::policy(CommissionRule::class, CommissionRulePolicy::class);

        /*
        | THE SELLER LEDGER (ADR-062). Payment's own event, so this is a plain
        | typed listener rather than the class-string subscription a cross-module
        | one needs.
        |
        | REGISTERED HERE, WHICH MEANS AFTER ORDER'S. `OrderServiceProvider` boots
        | first (`bootstrap/providers.php`), so by the time this runs, Order has
        | already frozen the commission onto the lines and the ledger can read it
        | back instead of computing it a second time. The listener does not TRUST
        | that ordering — a null commission makes it skip and log — but it is why
        | the common path works.
        */
        Event::listen(PaymentSucceeded::class, [CreditSellerLedger::class, 'handle']);
    }

    /**
     * Permissions are DERIVED from a registration, never hand-listed.
     *
     * READ-ONLY, AND ADMIN-ONLY, for P1. There is deliberately no
     * `payment.update`: nothing on this platform may edit a payment, because a
     * payment is a record of what a bank did and editing it would make it a
     * record of what somebody typed. The refund path (P5) will add its own
     * ability rather than widening this one.
     *
     * A buyer's authority over their OWN payment is ownership, resolved from the
     * `customer_id` on the row, not a permission — the same shape the address book
     * and the order list use.
     */
    private function registerPermissions(): void
    {
        PermissionRegistry::ability('payment.view_any', [UserType::Admin]);
        PermissionRegistry::ability('payment.view', [UserType::Admin]);

        /*
        | COMMISSION RULES ARE THE ONE WRITABLE THING IN THIS MODULE (ADR-061). A
        | rate is configuration an operator sets without a release, unlike a
        | payment, which is a record of what a bank did. So this resource gets the
        | full verb set while `payment.*` stays read-only.
        */
        PermissionRegistry::resource('commission_rule', [UserType::Admin]);
    }
}
