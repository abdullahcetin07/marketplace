<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Filament\Resources;

use App\Core\Domain\Context\AuditContext;
use App\Models\User;
use App\Modules\Identity\Application\Actions\AdminUpdateUserAction;
use App\Modules\Identity\Application\Actions\RequestPasswordResetAction;
use App\Modules\Identity\Application\Services\TwoFactorService;
use App\Modules\Identity\Domain\DTOs\AdminUpdateUserDTO;
use App\Modules\Identity\Domain\DTOs\PasswordResetRequestDTO;
use App\Shared\Enums\Status;
use App\Shared\Enums\UserType;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * What every admin account surface has in common — the base of Personel,
 * Satıcılar and Müşteriler.
 *
 * WHY THREE RESOURCES AND NOT ONE FILTER. The single all-users list treated an
 * administrator, a merchant and a shopper as the same object with a different
 * `type`, so every control it offered had to be offered to all three. Granting
 * a staff role is only ever meaningful against staff; a seller's team is the
 * SELLER's to manage, not an operator's. Splitting the list by actor type is
 * what makes each area's control set honest — the seller and customer areas
 * carry no role assignment because there is nothing there to assign, not
 * because a callback hid it.
 *
 * STRICTLY PRESENTATION, exactly as the resource it replaces: every write goes
 * through `AdminUpdateUserAction`, `RequestPasswordResetAction` or
 * `TwoFactorService`, and every authorisation decision is `UserPolicy` — which
 * is also where the privilege-escalation guard against super-admins lives. This
 * class owns no business rule.
 *
 * @see App\Modules\Identity\Presentation\Policies\UserPolicy
 * @see App\Modules\Identity\Presentation\Controllers\Api\Admin\UserController
 */
abstract class AccountResource extends Resource
{
    protected static ?string $recordTitleAttribute = 'email';

    /**
     * The one actor type this resource may ever show. The scope, the labels
     * and the navigation all derive from it.
     */
    abstract public static function actorType(): UserType;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.users');
    }

    /**
     * Type-scoped, twice over.
     *
     * `getModel()` is the concrete subclass (Admin/Seller/Customer), whose
     * global scope already confines the query to its own `users.type`. The
     * explicit `where` is stated anyway: it is the guarantee this class exists
     * to make, and it must not depend on a reader knowing that a model three
     * files away carries a global scope.
     *
     * The locale relations are eager loaded because strict mode makes a lazy
     * load throw and the row renders them.
     *
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', static::actorType()->value)
            ->with(['language', 'country', 'currency', 'timezone']);
    }

    /**
     * The read surface, shared by all three areas.
     *
     * Deliberately no locale block and no personal data beyond what an
     * operator needs to answer a ticket: who this is, whether the account is
     * usable, and its security posture.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema(static::infolistSections());
    }

    /**
     * Split out from infolist() so a subclass can APPEND a section without
     * having to rebuild the shared ones — the same reason baseColumns() exists.
     *
     * @return array<int, Infolists\Components\Component>
     */
    protected static function infolistSections(): array
    {
        return [
            Infolists\Components\Section::make(__('users.section.profile'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('display_name')->label(__('users.name')),
                    Infolists\Components\TextEntry::make('email')->label(__('users.email'))->copyable(),
                    Infolists\Components\TextEntry::make('phone')->label(__('users.phone'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')
                        ->label(__('users.status'))
                        ->badge()
                        ->color(fn (Status $state): string => $state->color())
                        ->formatStateUsing(fn (Status $state): string => __("users.status_{$state->value}")),
                    Infolists\Components\TextEntry::make('created_at')->label(__('users.registered'))->dateTime(),
                    Infolists\Components\TextEntry::make('email_verified_at')
                        ->label(__('users.email_verified'))
                        ->dateTime()
                        ->placeholder(__('users.email_unverified')),
                ]),

            Infolists\Components\Section::make(__('users.section.security'))
                ->columns(3)
                ->schema([
                    Infolists\Components\IconEntry::make('two_factor')
                        ->label(__('users.two_factor'))
                        ->boolean()
                        ->state(fn (User $record): bool => $record->hasTwoFactorEnabled()),
                    Infolists\Components\TextEntry::make('last_login_at')
                        ->label(__('users.last_login'))
                        ->dateTime()
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('last_login_ip')
                        ->label(__('users.last_login_ip'))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('login_count')
                        ->label(__('users.login_count')),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(static::baseColumns())
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('users.status'))
                    ->options([
                        Status::Active->value => __('users.status_active'),
                        Status::Suspended->value => __('users.status_suspended'),
                    ]),
            ])
            ->actions(static::rowActions())
            // No bulk delete: deactivating an account is a status change with a
            // reason, not a mass delete. Bulk security actions do not belong on
            // a checkbox.
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * The forensic timeline every area needs: who signed in, from where, and
     * every failure in between. Read-only, and gated on `viewLoginHistory`.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\LoginHistoryRelationManager::class,
        ];
    }

    /**
     * @return array<int, Tables\Columns\Column>
     */
    protected static function baseColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('display_name')
                ->label(__('users.name'))
                ->searchable(['first_name', 'last_name'])
                ->sortable(['first_name']),

            Tables\Columns\TextColumn::make('email')
                ->label(__('users.email'))
                ->searchable()
                ->copyable(),

            Tables\Columns\TextColumn::make('status')
                ->label(__('users.status'))
                ->badge()
                ->color(fn (Status $state): string => $state->color())
                ->formatStateUsing(fn (Status $state): string => __("users.status_{$state->value}")),

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
        ];
    }

    /**
     * The row actions every area gets. Subclasses prepend their own.
     *
     * @return array<int, Tables\Actions\Action>
     */
    protected static function rowActions(): array
    {
        return [
            Tables\Actions\ViewAction::make(),
            static::suspendAction(),
            static::reinstateAction(),
            static::resetPasswordAction(),
            static::disableTwoFactorAction(),
        ];
    }

    /**
     * Suspend an account — a status change with a reason, through the same
     * action the admin API's PATCH uses, so the audit entry is identical.
     */
    protected static function suspendAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('suspend')
            ->label(__('users.action.suspend'))
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('users.action.suspend_confirm'))
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('users.reason'))
                    ->helperText(__('users.reason_help'))
                    ->maxLength(500),
            ])
            ->visible(fn (User $record): bool => $record->status !== Status::Suspended
                && auth()->user()?->can('update', $record) === true)
            ->action(fn (User $record, array $data) => static::changeStatus($record, Status::Suspended, $data));
    }

    protected static function reinstateAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reinstate')
            ->label(__('users.action.reinstate'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('users.reason'))
                    ->helperText(__('users.reason_help'))
                    ->maxLength(500),
            ])
            ->visible(fn (User $record): bool => $record->status === Status::Suspended
                && auth()->user()?->can('update', $record) === true)
            ->action(fn (User $record, array $data) => static::changeStatus($record, Status::Active, $data));
    }

    /**
     * Trigger a password reset — the same reset flow the API uses. Gated on the
     * same policy ability, with a reason for the forensic trail.
     */
    protected static function resetPasswordAction(): Tables\Actions\Action
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
                $action = app(RequestPasswordResetAction::class);

                AuditContext::withReasonFor(
                    $data['reason'] ?? null,
                    fn () => $action->run(new PasswordResetRequestDTO($record->email, $record->type)),
                );

                Notification::make()->title(__('auth.admin_reset_sent'))->success()->send();
            });
    }

    /**
     * Clear a user's 2FA — TwoFactorService, byAdministrator, wrapped so the
     * reason reaches the forensic entry. Shown only when 2FA is on and the
     * operator holds the dedicated permission.
     */
    protected static function disableTwoFactorAction(): Tables\Actions\Action
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
     * ONE write, through the action, so the reason and the before/after land in
     * a single audit entry — never a raw `$record->update()`.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function changeStatus(User $record, Status $status, array $data): void
    {
        $reason = $data['reason'] ?? null;

        app(AdminUpdateUserAction::class)->run($record, new AdminUpdateUserDTO(
            status: $status->value,
            reason: is_string($reason) && $reason !== '' ? $reason : null,
            present: ['status'],
        ));

        Notification::make()
            ->title($status === Status::Suspended
                ? __('users.action.suspended_notice')
                : __('users.action.reinstated_notice'))
            ->success()
            ->send();
    }
}
