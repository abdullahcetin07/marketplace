<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Filament\Resources;

use App\Models\Admin;
use App\Models\User;
use App\Modules\Identity\Presentation\Filament\Resources\StaffResource\Pages;
use App\Shared\Enums\Status;
use App\Shared\Enums\UserType;
use App\Shared\Rules\StrongPassword;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/**
 * Personel — the platform's own team.
 *
 * THE ONE AREA THAT GRANTS STAFF ROLES. This is "my team": an operator creates
 * a colleague, gives them the roles their job needs, and suspends the account
 * when they leave. Role granting is scoped here on purpose — a staff role means
 * nothing on a seller or a customer, and offering it against them was the
 * clutter the split removes.
 *
 * THE ESCALATION GUARD IS ENFORCED TWICE. `UserPolicy` already refuses to let a
 * non-super-admin act on a super-admin, which covers every edit. Creation has no
 * target for that guard to inspect, so the same rule is applied to the ROLE
 * being granted: Super Admin is absent from the options unless the actor holds
 * it, and `assertRolesGrantable()` refuses the write even if the payload is
 * forged. Hiding an option is a UI courtesy; the assertion is the control.
 *
 * Creation mirrors `marketplace:create-admin` — the same columns, the same
 * locale defaults, the same staff password policy, the same
 * `email_verified_at` stamp. An operator provisioned from the panel and one
 * provisioned from the CLI are the same account.
 *
 * @see App\Console\Commands\CreateAdminCommand
 * @see App\Modules\Identity\Presentation\Policies\UserPolicy
 */
final class StaffResource extends AccountResource
{
    protected static ?string $model = Admin::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?int $navigationSort = 10;

    public static function actorType(): UserType
    {
        return UserType::Admin;
    }

    public static function getModelLabel(): string
    {
        return __('users.staff.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('users.staff.plural');
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | The AREA is gated on `user.manage_staff` — held by Super Admin and Admin,
    | and deliberately not by Support, whose job is answering tickets, not
    | provisioning colleagues. Per-record decisions stay in UserPolicy, which is
    | where the super-admin guard lives.
    */

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manageStaff', User::class) === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('manageStaff', User::class) === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update', $record) === true;
    }

    /**
     * An account is suspended, never deleted — the audit trail and everything
     * a departed operator touched depend on the row surviving.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Create asks for the credential; edit asks for the roles and the status.
     *
     * The email is create-only: it is half the identity key, and changing it is
     * a separate verified workflow rather than an inline edit.
     */
    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('first_name')
                ->label(__('users.first_name'))
                ->required()
                ->maxLength(255),

            // Optional by decision (ADR-012).
            Forms\Components\TextInput::make('last_name')
                ->label(__('users.last_name'))
                ->maxLength(255),

            Forms\Components\TextInput::make('phone')
                ->label(__('users.phone'))
                ->maxLength(32),

            Forms\Components\TextInput::make('email')
                ->label(__('users.email'))
                ->email()
                ->required()
                ->maxLength(255)
                /*
                | Uniqueness is scoped to (type, email), not to the whole table:
                | one human is routinely both an operator and a customer here.
                | Soft-deleted rows are excluded — a closed account does not
                | reserve its address forever. @see App\Filament\Seller\Auth\Register
                */
                ->unique(
                    table: 'users',
                    column: 'email',
                    ignoreRecord: true,
                    modifyRuleUsing: static fn (Unique $rule): Unique => $rule
                        ->where('type', UserType::Admin->value)
                        ->whereNull('deleted_at'),
                )
                // A disabled field is not dehydrated, so the edit form cannot
                // submit an email change even if the input is re-enabled.
                ->disabledOn('edit'),

            /*
            | The staff password policy — 14 characters, mixed case, digits,
            | symbols, checked against Have I Been Pwned. Routed through for()
            | rather than staff() so the suite gets the relaxed rule: the strict
            | tier makes an HTTP call the tests block outright. Production is
            | unaffected — for(Admin) IS staff(). @see StrongPassword
            */
            Forms\Components\TextInput::make('password')
                ->label(__('users.password'))
                ->password()
                ->revealable()
                ->required()
                ->rule(StrongPassword::for(UserType::Admin))
                ->same('password_confirmation')
                ->helperText(__('users.password_help'))
                ->visibleOn('create'),

            Forms\Components\TextInput::make('password_confirmation')
                ->label(__('users.password_confirmation'))
                ->password()
                ->revealable()
                ->required()
                ->dehydrated(false)
                ->visibleOn('create'),

            /*
            | Roles by NAME, from config('marketplace.roles.*'). Never an id —
            | ids differ per environment and mean nothing as an identifier.
            */
            Forms\Components\Select::make('roles')
                ->label(__('users.roles'))
                ->multiple()
                ->options(fn (): array => self::staffRoleOptions())
                ->helperText(__('users.roles_help'))
                ->columnSpanFull(),

            Forms\Components\Select::make('status')
                ->label(__('users.status'))
                ->options([
                    Status::Active->value => __('users.status_active'),
                    Status::Suspended->value => __('users.status_suspended'),
                ])
                ->required()
                ->visibleOn('edit'),

            // Not a column. It stays in the form payload so the Edit page can
            // hand it to the action, and lands in the audit entry only.
            Forms\Components\Textarea::make('reason')
                ->label(__('users.reason'))
                ->helperText(__('users.reason_help'))
                ->maxLength(500)
                ->columnSpanFull()
                ->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return parent::table($table)
            ->columns([
                ...self::baseColumns(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label(__('users.roles'))
                    ->badge()
                    ->placeholder(__('users.roles_none')),
            ]);
    }

    /**
     * The staff roles an operator may grant, keyed by role NAME.
     *
     * Super Admin is offered only to a Super Admin: an Admin may provision up
     * to their own level and no further. @see assertRolesGrantable()
     *
     * @return array<string, string>
     */
    public static function staffRoleOptions(): array
    {
        $keys = ['super_admin', 'admin', 'editor', 'category_manager', 'support', 'finance'];

        if (auth()->user()?->isSuperAdmin() !== true) {
            $keys = array_values(array_diff($keys, ['super_admin']));
        }

        $options = [];

        foreach ($keys as $key) {
            $name = config("marketplace.roles.{$key}");

            if (is_string($name) && $name !== '') {
                $options[$name] = $name;
            }
        }

        return $options;
    }

    /**
     * The control behind the option list.
     *
     * A hidden `<option>` is a courtesy to the operator; a forged payload is
     * what an escalation attempt actually looks like. Anything outside the
     * granting actor's own level is refused here, before the write.
     *
     * @param array<int, string> $roles
     *
     * @throws AuthorizationException
     */
    public static function assertRolesGrantable(array $roles): void
    {
        $grantable = array_keys(self::staffRoleOptions());

        foreach ($roles as $role) {
            if (! in_array($role, $grantable, true)) {
                throw new AuthorizationException(__('errors.cannot_grant_role', ['role' => $role]));
            }
        }
    }

    /**
     * Roles are rendered on the list and the detail — eager loaded so strict
     * mode does not throw on the first badge.
     *
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('roles');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaff::route('/'),
            'create' => Pages\CreateStaff::route('/create'),
            'view' => Pages\ViewStaff::route('/{record}'),
            'edit' => Pages\EditStaff::route('/{record}/edit'),
        ];
    }

    /**
     * The shared sections plus the one thing only staff have: platform roles.
     *
     * @return array<int, Infolists\Components\Component>
     */
    protected static function infolistSections(): array
    {
        return [
            ...parent::infolistSections(),

            Infolists\Components\Section::make(__('users.roles'))->schema([
                Infolists\Components\TextEntry::make('roles.name')
                    ->label(__('users.roles'))
                    ->badge()
                    ->placeholder(__('users.roles_none')),
            ]),
        ];
    }

    /**
     * Editing is the staff area's job, so the row carries it; everything else
     * is the shared set.
     *
     * @return array<int, Tables\Actions\Action>
     */
    protected static function rowActions(): array
    {
        return [
            Tables\Actions\EditAction::make(),
            ...parent::rowActions(),
        ];
    }
}
