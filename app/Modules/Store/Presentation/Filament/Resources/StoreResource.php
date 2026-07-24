<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Filament\Resources;

use App\Modules\Store\Application\Actions\ArchiveStoreAction;
use App\Modules\Store\Application\Actions\ReinstateStoreAction;
use App\Modules\Store\Application\Actions\SuspendStoreAction;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use App\Modules\Store\Presentation\Filament\Resources\StoreResource\Pages;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The admin store resource — SUPERVISORY ONLY.
 *
 * STRICTLY PRESENTATION. Admins view every store and enforce policy (suspend /
 * reinstate / archive); they do NOT manage a store's content — that is the
 * seller's surface, in the seller panel. Every action delegates to the module
 * Action (which owns the transition, guard, event and audit) and is gated by
 * `StorePolicy` via `auth()->user()->can(...)` — no capability check is
 * duplicated here. Listing itself is authorised by `store.view_any` through the
 * policy (Filament's default), so a limited admin without it never sees this.
 */
final class StoreResource extends Resource
{
    protected static ?string $model = Store::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.sellers');
    }

    public static function getPluralModelLabel(): string
    {
        return __('store.plural');
    }

    public static function getModelLabel(): string
    {
        return __('store.singular');
    }

    /**
     * Supervisory only — an admin never creates a store (ADR-028: creation is
     * event-driven from an approved request).
     */
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
                Tables\Columns\TextColumn::make('activated_at')->label(__('store.activated_at'))->dateTime()->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(
                    collect(StoreStatus::cases())->mapWithKeys(fn (StoreStatus $s): array => [$s->value => $s->value])->all(),
                ),
            ])
            ->actions([
                Tables\Actions\Action::make('suspend')
                    ->label(__('store.action.suspend'))
                    ->icon('heroicon-o-no-symbol')->color('danger')
                    ->requiresConfirmation()
                    ->form([Forms\Components\Textarea::make('reason')->label(__('store.reason'))->required()->maxLength(1000)])
                    ->visible(fn (Store $record): bool => auth()->user()?->can('suspend', $record) === true
                        && $record->status->isSellerMutable())
                    ->action(function (Store $record, array $data): void {
                        app(SuspendStoreAction::class)->run($record, auth()->user(), $data['reason']);
                        Notification::make()->title(__('store.notify.suspended'))->success()->send();
                    }),

                Tables\Actions\Action::make('reinstate')
                    ->label(__('store.action.reinstate'))
                    ->icon('heroicon-o-arrow-uturn-left')->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Store $record): bool => auth()->user()?->can('reinstate', $record) === true
                        && $record->status === StoreStatus::Suspended)
                    ->action(function (Store $record): void {
                        app(ReinstateStoreAction::class)->run($record, auth()->user());
                        Notification::make()->title(__('store.notify.reinstated'))->success()->send();
                    }),

                Tables\Actions\Action::make('archive')
                    ->label(__('store.action.archive'))
                    ->icon('heroicon-o-archive-box')->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (Store $record): bool => auth()->user()?->can('archive', $record) === true
                        && in_array($record->status, [StoreStatus::Closed, StoreStatus::Suspended], true))
                    ->action(function (Store $record): void {
                        app(ArchiveStoreAction::class)->run($record);
                        Notification::make()->title(__('store.notify.archived'))->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    /**
     * @return Builder<Store>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
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
