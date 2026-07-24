<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Filament\Resources;

use App\Core\Domain\Context\AuditContext;
use App\Models\User;
use App\Modules\Identity\Application\Services\TwoFactorService;
use App\Modules\Identity\Presentation\Filament\Resources\UserResource\Pages;
use App\Shared\Enums\Status;
use App\Shared\Enums\UserType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The admin panel's window onto every account — the UI twin of the admin API.
 *
 * STRICTLY PRESENTATION. It owns no business rule: every write goes through the
 * same Actions and Services the API uses (AdminUpdateUserAction, the reset
 * action, TwoFactorService), and every authorisation decision is the same
 * UserPolicy — Filament resolves it automatically for viewAny/view/update, and
 * the custom row actions check it explicitly. There is nothing here to keep in
 * sync with the API because there is no logic here to diverge.
 *
 * @see App\Modules\Identity\Presentation\Controllers\Api\Admin\UserController
 * @see App\Modules\Identity\Presentation\Policies\UserPolicy
 */
final class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'email';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.system');
    }

    public static function getModelLabel(): string
    {
        return __('users.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('users.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('first_name')
                ->label(__('users.first_name'))
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('last_name')
                ->label(__('users.last_name'))
                ->maxLength(255),

            Forms\Components\TextInput::make('phone')
                ->label(__('users.phone'))
                ->maxLength(32),

            // Read-only: the email is half the identity key; changing it is a
            // separate verified workflow, not an inline edit.
            Forms\Components\TextInput::make('email')
                ->label(__('users.email'))
                ->disabled()
                ->dehydrated(false),

            Forms\Components\Select::make('status')
                ->label(__('users.status'))
                ->options([
                    Status::Active->value => __('users.status_active'),
                    Status::Suspended->value => __('users.status_suspended'),
                ])
                ->required(),

            // Not a column. It stays in the form payload (so handleRecordUpdate
            // can read it) but never reaches the model — the Edit page routes
            // the save through AdminUpdateUserAction, not a raw update, so the
            // reason lands in the audit entry and nowhere else.
            Forms\Components\Textarea::make('reason')
                ->label(__('users.reason'))
                ->helperText(__('users.reason_help'))
                ->maxLength(500)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('display_name')
                    ->label(__('users.name'))
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name']),

                Tables\Columns\TextColumn::make('email')
                    ->label(__('users.email'))
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('type')
                    ->label(__('users.type'))
                    ->badge()
                    ->formatStateUsing(fn (UserType $state): string => $state->value),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('users.status'))
                    ->badge()
                    ->color(fn (Status $state): string => $state === Status::Active ? 'success' : 'danger')
                    ->formatStateUsing(fn (Status $state): string => $state->value),

                Tables\Columns\IconColumn::make('two_factor')
                    ->label(__('users.two_factor'))
                    ->boolean()
                    ->state(fn (User $record): bool => $record->hasTwoFactorEnabled()),

                Tables\Columns\TextColumn::make('last_login_at')
                    ->label(__('users.last_login'))
                    ->dateTime()
                    ->since()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('users.registered'))
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('users.type'))
                    ->options(fn (): array => collect(UserType::cases())
                        ->mapWithKeys(fn (UserType $t): array => [$t->value => $t->value])
                        ->all()),

                Tables\Filters\SelectFilter::make('status')
                    ->label(__('users.status'))
                    ->options([
                        Status::Active->value => __('users.status_active'),
                        Status::Suspended->value => __('users.status_suspended'),
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                self::resetPasswordAction(),
                self::disableTwoFactorAction(),
            ])
            // No bulk delete: deactivating an account is a status change with a
            // reason, not a mass delete. Bulk security actions do not belong on
            // a checkbox.
            ->bulkActions([]);
    }

    /**
     * Trigger a password reset — the same reset flow the API uses. Gated on the
     * same policy ability, with a reason for the forensic trail.
     */
    private static function resetPasswordAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('resetPassword')
            ->label(__('users.reset_password'))
            ->icon('heroicon-o-key')
            ->requiresConfirmation()
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('users.reason'))
                    ->maxLength(500),
            ])
            ->visible(fn (User $record): bool => auth()->user()?->can('resetPassword', $record) === true)
            ->action(function (User $record, array $data): void {
                $action = app(\App\Modules\Identity\Application\Actions\RequestPasswordResetAction::class);

                AuditContext::withReasonFor(
                    $data['reason'] ?? null,
                    fn () => $action->run(
                        new \App\Modules\Identity\Domain\DTOs\PasswordResetRequestDTO($record->email, $record->type),
                    ),
                );

                Notification::make()->title(__('auth.admin_reset_sent'))->success()->send();
            });
    }

    /**
     * Clear a user's 2FA — TwoFactorService, byAdministrator, wrapped so the
     * reason reaches the forensic entry. Shown only when 2FA is on and the
     * operator holds the dedicated permission.
     */
    private static function disableTwoFactorAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('disableTwoFactor')
            ->label(__('users.disable_two_factor'))
            ->icon('heroicon-o-shield-exclamation')
            ->color('danger')
            ->requiresConfirmation()
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('users.reason'))
                    ->maxLength(500),
            ])
            ->visible(fn (User $record): bool => $record->hasTwoFactorEnabled()
                && auth()->user()?->can('disableTwoFactor', $record) === true)
            ->action(function (User $record, array $data): void {
                AuditContext::withReasonFor(
                    $data['reason'] ?? null,
                    fn () => app(TwoFactorService::class)->disable($record, byAdministrator: true),
                );

                Notification::make()->title(__('auth.admin_two_factor_disabled'))->success()->send();
            });
    }

    /**
     * Strict mode makes lazy loading throw, and the table/columns read the
     * locale relations — eager load them for the listing.
     *
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['language', 'country', 'currency', 'timezone']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
