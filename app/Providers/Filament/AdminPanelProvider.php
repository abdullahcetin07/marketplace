<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Modules\Catalog\Presentation\Filament\Resources\AttributeResource;
use App\Modules\Catalog\Presentation\Filament\Resources\BrandResource;
use App\Modules\Catalog\Presentation\Filament\Resources\CategoryResource;
use App\Modules\Catalog\Presentation\Filament\Resources\ProductModerationResource;
use App\Modules\Identity\Presentation\Filament\Resources\UserResource;
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
                UserResource::class,
                OrganizationResource::class,
                StoreOpeningRequestResource::class,
                StoreResource::class,

                // Catalog (ADR-037–041). The moderation queue first: it is the
                // Category Manager's daily surface, the taxonomy is maintenance.
                ProductModerationResource::class,
                CategoryResource::class,
                AttributeResource::class,
                BrandResource::class,
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
