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
            /*
            | Our own page, not Filament's. The stock one posts a single `name`
            | field, which this platform's user model does not have and will not
            | be given (ADR-012). @see App\Filament\Seller\Auth\Register
            */
            ->registration(\App\Filament\Seller\Auth\Register::class)
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
                \App\Modules\Organization\Presentation\Filament\Seller\Resources\StoreOpeningRequestResource::class,

                /*
                | Ekip — the seller's OWN team. A merchant's team is theirs to
                | manage; the admin panel's seller area deliberately offers no
                | team controls at all. Both resources are membership-scoped
                | (ADR-030) and grant ORG roles only — never platform staff
                | roles, which exist solely under Personel in the admin panel.
                */
                \App\Modules\Organization\Presentation\Filament\Seller\Resources\TeamMemberResource::class,
                \App\Modules\Organization\Presentation\Filament\Seller\Resources\TeamInvitationResource::class,

                \App\Modules\Store\Presentation\Filament\Seller\Resources\StoreResource::class,

                // "Ürün aç" (Catalog §5). The seller-panel product resource is
                // a DIFFERENT class from the admin moderation queue — sharing
                // one would put a cross-org admin surface a registration
                // mistake away from a seller.
                \App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource::class,

                /*
                | "Tekliflerim" — what the seller actually sells. A DIFFERENT
                | class from the admin oversight resource for the same reason
                | the product ones are split: sharing one would put a cross-org
                | surface a registration mistake away from a seller.
                */
                \App\Modules\Offer\Presentation\Filament\Seller\Resources\OfferResource::class,

                /*
                | "Stoğum" (ADR-048). Sits beside the offers because that is where
                | the seller types the quantity — this is the page that tells them
                | what happened to it afterwards, and the one number the offer
                | form cannot show: available = on hand − reserved.
                */
                \App\Modules\Inventory\Presentation\Filament\Seller\Resources\StockResource::class,

                /*
                | "Siparişlerim" (ADR-052) — one seller's half of each purchase,
                | and no part of what the customer bought from anybody else. A
                | DIFFERENT class from the admin resource for the reason the offer
                | and product ones are split: sharing one would put a cross-seller
                | surface a registration mistake away from a merchant.
                */
                \App\Modules\Order\Presentation\Filament\Seller\Resources\OrderResource::class,

                /*
                | "İptal talepleri" (ADR-065, C2) — an INBOX, beside the orders it
                | is about. A buyer cannot cancel a paid order unilaterally
                | because the seller may already be packing it, so they ASK, and
                | this is where the seller answers. The badge counts what is
                | WAITING: an unanswered request costs a buyer real money for as
                | long as it sits.
                */
                \App\Modules\Order\Presentation\Filament\Seller\Resources\CancellationRequestResource::class,

                /*
                | KARGOLARIM (ADR-063). Beside the orders because it is the other
                | half of one job: what was sold, and what still has to be put in
                | a box. The seller's ONE lever is here — and the one they can
                | never reach is `delivered`, which the buyer or the transit
                | window sets (ADR-064).
                */
                \App\Modules\Shipping\Presentation\Filament\Seller\Resources\ShipmentResource::class,

                /*
                | "SATICIYA SOR" (ADR-071) — the questions shoppers aimed at this
                | merchant, and the one seller-panel surface whose action puts
                | text on a PUBLIC page. There is no draft and no moderator
                | afterwards: answering is publishing.
                |
                | Scoped by the `store_uuid` the question was snapshotted with,
                | so a merchant sees only what was asked of their own shops.
                */
                \App\Modules\Questions\Presentation\Filament\Seller\Resources\QuestionResource::class,
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
                NavigationGroup::make()->label(__('nav.offers')),
                NavigationGroup::make()->label(__('nav.orders')),
                NavigationGroup::make()->label(__('nav.store')),
                NavigationGroup::make()->label(__('nav.team')),
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
