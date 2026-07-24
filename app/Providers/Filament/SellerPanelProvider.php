<?php

declare(strict_types=1);

namespace App\Providers\Filament;

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
 * Merchant panel at /seller.
 *
 * Completely separate authentication from the admin panel: its own guard
 * (`seller`), its own password broker, its own registration flow. See
 * AdminPanelProvider for why the isolation holds at the query level rather
 * than relying on a middleware check.
 *
 * Registration is ENABLED here and disabled on the admin panel — sellers
 * onboard themselves and are then approved; admins are provisioned internally
 * and a self-service admin signup would be a critical vulnerability.
 *
 * @see App\Providers\Filament\AdminPanelProvider
 * @see docs/authentication.md
 */
final class SellerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('seller')
            ->path('seller')
            ->authGuard(UserType::Seller->guard())
            ->authPasswordBroker('sellers')
            ->login()
            ->registration()
            ->passwordReset()
            ->emailVerification()
            ->profile(isSimple: false)

            /*
            | Amber, deliberately unlike the admin panel's red. @see
            | AdminPanelProvider.
            */
            ->colors([
                'primary' => Color::Amber,
            ])
            ->brandName(config('app.name').' — Satıcı Paneli')
            ->favicon(asset('favicon.ico'))
            ->darkMode()

            /*
            | Seller-panel resources are registered here explicitly, never
            | discovered from a shared root, so this panel can never inherit an
            | admin resource. Membership scoping inside each resource is the
            | tenancy wall (ADR-030).
            */
            ->resources([
                \App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource::class,
                \App\Modules\Store\Presentation\Filament\Seller\Resources\StoreResource::class,
            ])
            ->discoverPages(in: app_path('Filament/Seller/Pages'), for: 'App\\Filament\\Seller\\Pages')
            ->discoverWidgets(in: app_path('Filament/Seller/Widgets'), for: 'App\\Filament\\Seller\\Widgets')

            ->pages([
                Pages\Dashboard::class,
            ])
            ->widgets([
                Widgets\AccountWidget::class,
            ])

            ->navigationGroups([
                NavigationGroup::make()->label(__('nav.catalogue')),
                NavigationGroup::make()->label(__('nav.orders')),
                NavigationGroup::make()->label(__('nav.store')),
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
