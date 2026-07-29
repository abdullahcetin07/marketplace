<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Filament\Resources\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * An account's login history — the forensic timeline behind every "was this
 * really them?" ticket.
 *
 * FAILURES ARE THE POINT (LoginAttempt's own rule). A list of successful logins
 * answers almost nothing; a run of failures from a dozen addresses is what
 * credential stuffing looks like, and it is the earliest signal an operator
 * gets. Both are shown, newest first.
 *
 * STRICTLY READ-ONLY. Login history is append-only evidence — there is no
 * create, no edit, no delete, and no bulk action that could quietly rewrite it.
 * Access is the `user.view_login_history` permission via
 * `UserPolicy::viewLoginHistory`, which Support holds and a content Editor does
 * not.
 *
 * @see App\Modules\Identity\Presentation\Controllers\Api\Admin\UserController::loginHistory()
 */
final class LoginHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'loginAttempts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('users.login_history.title');
    }

    /**
     * The same policy ability the API's login-history endpoint authorises on,
     * so a role that cannot read the history there cannot read it here either.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('viewLoginHistory', $ownerRecord) === true;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('users.login_history.at'))
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\IconColumn::make('successful')
                    ->label(__('users.login_history.result'))
                    ->boolean(),

                Tables\Columns\TextColumn::make('failure_reason')
                    ->label(__('users.login_history.failure_reason'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label(__('users.login_history.ip'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('browser')
                    ->label(__('users.login_history.browser'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('platform')
                    ->label(__('users.login_history.platform'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('location')
                    ->label(__('users.login_history.location'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('successful')
                    ->label(__('users.login_history.result')),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-clock')
            ->emptyStateHeading(__('users.login_history.empty'))
            // The one table on this panel that can hold thousands of rows for a
            // single account — paginate tightly and sort newest first.
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(25);
    }
}
