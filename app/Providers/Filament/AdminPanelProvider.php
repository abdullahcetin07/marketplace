<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Modules\Catalog\Presentation\Filament\Resources\AttributeResource;
use App\Modules\Catalog\Presentation\Filament\Resources\BrandResource;
use App\Modules\Catalog\Presentation\Filament\Resources\CategoryResource;
use App\Modules\Catalog\Presentation\Filament\Resources\ProductModerationResource;
use App\Modules\Catalog\Presentation\Filament\Resources\TaxRateResource;
use App\Modules\Identity\Presentation\Filament\Resources\CustomerResource;
use App\Modules\Inventory\Presentation\Filament\Resources\StockResource;
use App\Modules\Order\Presentation\Filament\Resources\OrderResource;
use App\Modules\Offer\Presentation\Filament\Resources\OfferResource;
use App\Modules\Identity\Presentation\Filament\Resources\SellerResource;
use App\Modules\Identity\Presentation\Filament\Resources\StaffResource;
use App\Modules\Organization\Presentation\Filament\Resources\OrganizationResource;
use App\Modules\Organization\Presentation\Filament\Resources\StoreOpeningRequestResource;
use App\Modules\Store\Presentation\Filament\Resources\StoreResource;
use App\Shared\Enums\UserType;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Back-office panel at /admin.
 *
 * SEPARATE AUTHENTICATION: this panel binds to the `admin` guard, which
 * resolves App\Models\Admin, which carries a global scope of
 * `users.type = 'admin'`. A seller holding a valid seller session therefore
 * cannot reach any route in this panel — not because a middleware checks a
 * flag, but because the guard's user provider cannot find their row.
 *
 * The two panels also use different session cookies (see $panel->authGuard()
 * with Laravel's guard-scoped session keys), so being logged into one has no
 * bearing on the other.
 *
 * @see App\Providers\Filament\SellerPanelProvider
 * @see docs/authentication.md
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->authGuard(UserType::Admin->guard())
            ->authPasswordBroker('admins')
            ->login()
            ->passwordReset()
            ->emailVerification()
            ->profile(isSimple: false)

            /*
            | Red identifies the admin panel at a glance. The visual difference
            | from the seller panel is a real safeguard: it stops an operator
            | with both accounts from acting in the wrong context.
            */
            ->colors([
                'primary' => Color::Rose,
            ])
            ->brandName(config('app.name').' — Admin')
            ->favicon(asset('favicon.ico'))
            ->darkMode()

            /*
            | Resources are registered EXPLICITLY per panel, not auto-discovered
            | from a shared root. A resource built for the admin panel must never
            | surface on the seller panel — recursive discovery from app/Modules
            | could not guarantee that, and leaning on policies to hide a
            | mis-registered resource is a weaker guarantee than not registering
            | it at all. Each new module adds its admin resources to this list.
            */
            ->resources([
                /*
                | Accounts, split by ACTOR TYPE rather than filtered in one
                | list. Each area offers only the controls that mean something
                | for its type — staff roles are granted under Personel and
                | nowhere else, and a seller's team is managed in the seller
                | panel, not here.
                */
                StaffResource::class,
                SellerResource::class,
                CustomerResource::class,

                OrganizationResource::class,
                StoreOpeningRequestResource::class,
                StoreResource::class,

                // Catalog (ADR-037–041). The moderation queue first: it is the
                // Category Manager's daily surface, the taxonomy is maintenance.
                ProductModerationResource::class,
                CategoryResource::class,
                AttributeResource::class,
                BrandResource::class,

                /*
                | KDV brackets (ADR-056). Beside the brands because it is the same
                | curation job — a bracket classifies goods, which is what the
                | Category Manager does — and a lookup table rather than an enum
                | because brackets change by government decision, not by release.
                */
                TaxRateResource::class,

                // Offer oversight (ADR-044). Not a queue: offers go live
                // unmoderated, and this is the reactive lever that pulls one.
                OfferResource::class,

                /*
                | Stock oversight (ADR-048). READ ONLY, and the only oversight
                | resource with no lever at all — an operator answers "the site
                | says sold out and I have ten" here and changes nothing, because
                | editing a merchant's count is trading on their behalf
                | (Inventory §7).
                */
                StockResource::class,

                /*
                | Order oversight (ADR-052). The ONE surface where a purchase is
                | visible as a purchase: everywhere else the per-seller split is a
                | feature, and somebody still has to be able to pull up all N
                | orders of one checkout group when a customer asks where the rest
                | of their parcel is.
                */
                OrderResource::class,
            ])
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\\Filament\\Admin\\Pages')
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\\Filament\\Admin\\Widgets')

            ->pages([
                Pages\Dashboard::class,
            ])
            ->widgets([
                Widgets\AccountWidget::class,
            ])

            /*
            | Navigation groups are declared here so modules land in a stable
            | order as they are added, rather than in registration order.
            */
            ->navigationGroups([
                NavigationGroup::make()->label(__('nav.users')),
                NavigationGroup::make()->label(__('nav.catalogue')),
                NavigationGroup::make()->label(__('nav.sales')),
                NavigationGroup::make()->label(__('nav.sellers')),
                NavigationGroup::make()->label(__('nav.customers')),
                NavigationGroup::make()->label(__('nav.system'))->collapsed(),
            ])

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                'throttle:panel',
            ])
            ->authMiddleware([
                Authenticate::class,
            ])

            ->spa()
            ->maxContentWidth('full');
    }
}
