<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Filament\Resources;

use App\Models\Customer;
use App\Models\User;
use App\Modules\Identity\Presentation\Filament\Resources\CustomerResource\Pages;
use App\Shared\Enums\UserType;
use Illuminate\Database\Eloquent\Model;

/**
 * Müşteriler — shopper accounts, for OVERSIGHT AND SUPPORT ONLY.
 *
 * The same shape as the seller area and for the same reason: read, suspend,
 * reinstate, and the account-recovery levers. No roles — a customer holds one
 * role, assigned at registration, and there is nothing to choose. No team, no
 * create; customers register themselves.
 *
 * Gated on `user.oversee_customers`; per-record decisions stay in UserPolicy.
 *
 * @see App\Modules\Identity\Presentation\Filament\Resources\SellerResource
 */
final class CustomerResource extends AccountResource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 30;

    public static function actorType(): UserType
    {
        return UserType::Customer;
    }

    public static function getModelLabel(): string
    {
        return __('users.customer.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('users.customer.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('overseeCustomers', User::class) === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

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
            'index' => Pages\ListCustomers::route('/'),
            'view' => Pages\ViewCustomer::route('/{record}'),
        ];
    }
}
