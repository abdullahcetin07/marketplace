<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Filament\Resources;

use App\Models\Seller;
use App\Models\User;
use App\Modules\Identity\Presentation\Filament\Resources\SellerResource\Pages;
use App\Shared\Enums\UserType;
use Illuminate\Database\Eloquent\Model;

/**
 * Satıcılar — merchant accounts, for OVERSIGHT AND SUPPORT ONLY.
 *
 * WHAT IS DELIBERATELY ABSENT, AND WHY. There is no role assignment and no team
 * management here. A seller's team is the SELLER's to manage — it lives in
 * their own panel, scoped to their own organization (ADR-030), and it grants
 * ORG roles from the Organization module's own matrix. Staff roles are a
 * platform-guard concept that means nothing on a merchant account, so offering
 * them against one would be an escalation surface with no legitimate use.
 * There is no create either: merchants self-register (see the seller panel's
 * Register page) and are then approved at the Organization level.
 *
 * WHAT REMAINS is what a support ticket actually needs: read the account, read
 * its forensic login history, suspend it when it is abusing the platform,
 * reinstate it when the report was wrong, and the two account-recovery levers —
 * a password reset and clearing 2FA — each behind its own permission, neither
 * of which Support holds by default for the second.
 *
 * The AREA is gated on `user.oversee_sellers`; every per-record decision is
 * UserPolicy, unchanged.
 *
 * @see App\Modules\Organization\Presentation\Filament\Seller\Resources\TeamMemberResource
 *      the seller's own team surface — the counterpart this resource refuses to be
 */
final class SellerResource extends AccountResource
{
    protected static ?string $model = Seller::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 20;

    public static function actorType(): UserType
    {
        return UserType::Seller;
    }

    public static function getModelLabel(): string
    {
        return __('users.seller.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('users.seller.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('overseeSellers', User::class) === true;
    }

    /**
     * Merchants self-register. An operator conjuring one would create an
     * account with no owner and no consent trail.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * No edit form at all — the two changes an operator may make (suspend and
     * reinstate) are explicit, reasoned row actions, not a free-form save.
     */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSellers::route('/'),
            'view' => Pages\ViewSeller::route('/{record}'),
        ];
    }
}
