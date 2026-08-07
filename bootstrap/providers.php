<?php

declare(strict_types=1);

/*
| Provider order matters in two places:
|
|  1. Localization must register before anything resolves the translator —
|     it swaps Laravel's FileLoader for the database-backed one.
|  2. Module providers register their permissions in register(), and
|     RolePermissionSeeder reads PermissionRegistry after all of them have run.
|
| Everything else is independent.
*/

return [
    // Framework-level
    App\Providers\AppServiceProvider::class,
    App\Providers\AuthServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Providers\SearchServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,

    // Foundation modules (Sprint 1)
    App\Modules\Localization\LocalizationServiceProvider::class,
    App\Modules\Settings\SettingsServiceProvider::class,
    App\Modules\Identity\IdentityServiceProvider::class,
    App\Modules\Audit\AuditServiceProvider::class,
    // Activity after Identity: it subscribes to Identity's events.
    App\Modules\Activity\ActivityServiceProvider::class,
    App\Modules\Media\MediaServiceProvider::class,
    App\Modules\Notification\NotificationServiceProvider::class,

    // Business modules (Sprint: Organization)
    App\Modules\Organization\OrganizationServiceProvider::class,
    // Store after Organization: it subscribes to StoreOpeningApproved (ADR-032).
    App\Modules\Store\StoreServiceProvider::class,

    // Business modules (Sprint: Catalog)
    // Independent of Organization and Store — it references the proposing
    // company by uuid only and subscribes to nothing (ADR-040).
    App\Modules\Catalog\CatalogServiceProvider::class,

    // Business modules (Sprint: Offer)
    // After Catalog: Offer subscribes to Catalog's product-lifecycle events
    // (ADR-046 §3.5 cascade) and resolves the Core contracts Catalog, Store and
    // Organization bind. It imports none of them.
    App\Modules\Offer\OfferServiceProvider::class,

    // Business modules (Sprint: Inventory)
    // After Offer: Inventory subscribes to Offer's stock events to mirror
    // on-hand (ADR-048). It imports neither Offer nor anything else.
    App\Modules\Inventory\InventoryServiceProvider::class,

    // Business modules (Sprint: Order)
    // After Inventory: Order CALLS Inventory's reservation contract (ADR-054) and
    // reads Offer, Catalog, Store and Organization through their Core contracts.
    // It imports none of them, and nothing imports it — Payment and Shipping will
    // read `OrderQueryContract`.
    App\Modules\Order\OrderServiceProvider::class,

    // Business modules (Sprint: Payment)
    // After Order, because Payment reads a checkout group through
    // `OrderQueryContract` and commits the reservations Order's placement held
    // (ADR-057 — Payment is the caller that decision named). It imports no
    // module; Order learns money arrived by subscribing to `PaymentSucceeded`
    // BY CLASS-STRING from its own provider, which is why the load order here is
    // about contracts being bound, not about events being heard.
    App\Modules\Payment\PaymentServiceProvider::class,

    /*
    | Shipping — LAST, and after Payment for the same reason Payment sits after
    | Order: it reads a paid order through `OrderQueryContract` and learns that
    | money arrived by subscribing to `PaymentSucceeded` BY CLASS-STRING from its
    | own provider. It imports no module (ADR-063), so the load order here is
    | about contracts being bound, not about events being heard — a class-string
    | listener is registered whichever provider boots first.
    */
    App\Modules\Shipping\ShippingServiceProvider::class,

    /*
    | Reviews — after everything it reads, though the order matters less here
    | than anywhere above: it binds no contract another module resolves and
    | subscribes to nothing. It READS Catalog and Order through their Core
    | contracts and asks Store for shop names, so it needs those bindings to
    | exist by the time a request is served, not by the time it registers.
    */
    App\Modules\Reviews\ReviewsServiceProvider::class,

    /*
    | Questions — the sibling of Reviews, and in the same slot for the same
    | reason: it binds no contract another module resolves and subscribes to
    | nothing. It READS Catalog, Offer, Store and Organization through their Core
    | contracts, so it needs those bindings by the time a request is served, not
    | by the time it registers.
    */
    App\Modules\Questions\QuestionsServiceProvider::class,

    // Panels last — they discover resources from the modules above.
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\Filament\SellerPanelProvider::class,
];
