<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Filament\Seller\Resources;

use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Modules\Store\Application\Actions\ActivateStoreAction;
use App\Modules\Store\Application\Actions\CloseStoreAction;
use App\Modules\Store\Application\Actions\PauseStoreAction;
use App\Modules\Store\Application\Actions\ResumeStoreAction;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use App\Modules\Store\Presentation\Filament\Seller\Resources\StoreResource\Pages;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The seller-panel view of the stores the operator can manage.
 *
 * MEMBERSHIP-SCOPED (ADR-030/033): the query is confined to the orgs the actor
 * actively belongs to, via the Core `OrganizationAuthorizationContract` — so one
 * seller never sees another's store in the panel. Each operational action
 * delegates to the module Action and is gated by `StorePolicy`
 * (`auth()->user()->can(...)`) — the same authorization as the API, never
 * re-implemented here. Rich editing (branding/SEO/contact/localization) is driven
 * through the Next.js dashboard on the API; this panel exposes state operations.
 */
final class StoreResource extends Resource
{
    protected static ?string $model = Store::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getPluralModelLabel(): string
    {
        return __('store.plural');
    }

    public static function getModelLabel(): string
    {
        return __('store.singular');
    }

    /**
     * The seller panel is seller-guarded and the query is membership-scoped, so
     * the list is available to any panel user; per-record authorization stays in
     * StorePolicy (via the actions' `visible` gates). `StorePolicy::viewAny` is
     * admin-semantics (the admin API), which is why it is not used here.
     */
    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('store.name'))->searchable(),
                Tables\Columns\TextColumn::make('slug')->label(__('store.slug'))->toggleable(),
                Tables\Columns\TextColumn::make('store_number')->label(__('store.number'))->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('store.status'))
                    ->badge()
                    ->formatStateUsing(fn (StoreStatus $state): string => $state->value),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Tables\Actions\Action::make('activate')
                    ->label(__('store.action.activate'))
                    ->icon('heroicon-o-play')->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Store $record): bool => auth()->user()?->can('activate', $record) === true
                        && in_array($record->status, [StoreStatus::Draft, StoreStatus::Closed], true))
                    ->action(function (Store $record): void {
                        app(ActivateStoreAction::class)->run($record);
                        Notification::make()->title(__('store.notify.activated'))->success()->send();
                    }),

                Tables\Actions\Action::make('pause')
                    ->label(__('store.action.pause'))
                    ->icon('heroicon-o-pause')->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Store $record): bool => auth()->user()?->can('pause', $record) === true
                        && $record->status === StoreStatus::Active)
                    ->action(function (Store $record): void {
                        app(PauseStoreAction::class)->run($record);
                        Notification::make()->title(__('store.notify.paused'))->success()->send();
                    }),

                Tables\Actions\Action::make('resume')
                    ->label(__('store.action.resume'))
                    ->icon('heroicon-o-play')->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Store $record): bool => auth()->user()?->can('resume', $record) === true
                        && $record->status === StoreStatus::Paused)
                    ->action(function (Store $record): void {
                        app(ResumeStoreAction::class)->run($record);
                        Notification::make()->title(__('store.notify.resumed'))->success()->send();
                    }),

                Tables\Actions\Action::make('close')
                    ->label(__('store.action.close'))
                    ->icon('heroicon-o-x-mark')->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Store $record): bool => auth()->user()?->can('close', $record) === true
                        && in_array($record->status, [StoreStatus::Active, StoreStatus::Paused], true))
                    ->action(function (Store $record): void {
                        app(CloseStoreAction::class)->run($record);
                        Notification::make()->title(__('store.notify.closed'))->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    /**
     * Confined to the acting seller's active memberships — the tenancy wall,
     * resolved through the Core authorization contract (never importing an
     * Organization model, ADR-033).
     *
     * @return Builder<Store>
     */
    public static function getEloquentQuery(): Builder
    {
        $organizationIds = app(OrganizationAuthorizationContract::class)
            ->organizationIdsForUser((int) auth()->id());

        return parent::getEloquentQuery()->whereIn('organization_id', $organizationIds);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStores::route('/'),
        ];
    }
}
