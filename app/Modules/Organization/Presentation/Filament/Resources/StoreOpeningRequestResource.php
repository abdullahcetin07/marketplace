<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Resources;

use App\Modules\Organization\Application\Actions\ApproveStoreOpeningRequestAction;
use App\Modules\Organization\Application\Actions\RejectStoreOpeningRequestAction;
use App\Modules\Organization\Domain\Enums\StoreOpeningRequestStatus;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;
use App\Modules\Organization\Presentation\Filament\Resources\StoreOpeningRequestResource\Pages;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The admin Store Opening Request queue (ADR-028).
 *
 * STRICTLY PRESENTATION. Approve/Reject delegate to the module actions — which
 * enforce the authoritative store-limit re-check and fire `StoreOpeningApproved`.
 * NO store is created here; the Store module consumes the event.
 */
final class StoreOpeningRequestResource extends Resource
{
    protected static ?string $model = StoreOpeningRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'store_name';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.sellers');
    }

    public static function getModelLabel(): string
    {
        return __('organization.store_request.singular');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('store_name')->label(__('organization.store_request.name'))->searchable(),
                Tables\Columns\TextColumn::make('slug')->toggleable(),
                Tables\Columns\TextColumn::make('organization.legal_name')->label(__('organization.singular'))->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (StoreOpeningRequestStatus $state): string => $state->value),
                Tables\Columns\TextColumn::make('submitted_at')->dateTime()->sortable(),
            ])
            ->defaultSort('submitted_at')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(
                    collect(StoreOpeningRequestStatus::cases())->mapWithKeys(fn (StoreOpeningRequestStatus $s): array => [$s->value => $s->value])->all(),
                ),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label(__('organization.action.approve'))
                    ->icon('heroicon-o-check-circle')->color('success')
                    ->requiresConfirmation()
                    ->form([Forms\Components\Textarea::make('notes')->maxLength(1000)])
                    ->visible(fn (StoreOpeningRequest $record): bool => $record->isPending()
                        && auth()->user()?->can('approve', $record) === true)
                    ->action(function (StoreOpeningRequest $record, array $data): void {
                        app(ApproveStoreOpeningRequestAction::class)->run($record, $data['notes'] ?? null, auth()->user());
                        Notification::make()->title(__('organization.store_request_approved'))->success()->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label(__('organization.action.reject'))
                    ->icon('heroicon-o-x-circle')->color('danger')
                    ->requiresConfirmation()
                    ->form([Forms\Components\Textarea::make('notes')->required()->maxLength(1000)])
                    ->visible(fn (StoreOpeningRequest $record): bool => $record->isPending()
                        && auth()->user()?->can('reject', $record) === true)
                    ->action(function (StoreOpeningRequest $record, array $data): void {
                        app(RejectStoreOpeningRequestAction::class)->run($record, $data['notes'], auth()->user());
                        Notification::make()->title(__('organization.store_request_rejected'))->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    /**
     * @return Builder<StoreOpeningRequest>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('organization');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStoreOpeningRequests::route('/'),
        ];
    }
}
