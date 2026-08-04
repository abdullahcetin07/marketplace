<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Filament\Seller\Resources;

use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Modules\Inventory\Application\Actions\SetLowStockThresholdAction;
use App\Modules\Inventory\Domain\DTOs\SetLowStockThresholdDTO;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Inventory\Presentation\Filament\RelationManagers\MovementsRelationManager;
use App\Modules\Inventory\Presentation\Filament\Seller\Resources\StockResource\Pages;
use App\Modules\Inventory\Presentation\Support\CatalogLabels;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "Stoğum" — what the seller actually has, and what they can actually sell.
 *
 * READ-ONLY BY DESIGN, and the one thing sellers will ask about first: the
 * quantity is entered on the OFFER form (owner decision, ADR-048) and Inventory
 * mirrors it. Putting an editable field here too would give the same number two
 * front doors and guarantee they disagree — one of them writing through an event
 * and the other directly, with no way to say which won.
 *
 * SO WHAT THIS PAGE IS FOR is the number the Offer form cannot show: AVAILABLE.
 * "I have ten and can sell seven" is only sayable once reservations exist, and it
 * is exactly the sentence a seller needs when the storefront says sold out and
 * their shelf does not.
 *
 * The one write is the low-stock threshold — a preference, not a count, which is
 * why it records no movement.
 *
 * MEMBERSHIP-SCOPED (ADR-030): the query is confined to the organizations the
 * acting user actively belongs to, and `InventoryPolicy` re-checks per row.
 *
 * IT IMPORTS NO MODULE. Titles come from `CatalogBrowseContract` through
 * `CatalogLabels`; tenancy from `OrganizationAuthorizationContract`.
 *
 * @see docs/modules/Inventory.md §4
 */
final class StockResource extends Resource
{
    protected static ?string $model = StockItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function getNavigationGroup(): string
    {
        return __('nav.offers');
    }

    public static function getModelLabel(): string
    {
        return __('inventory.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('inventory.plural');
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | The listing is open to any seller reaching the panel — the query below is
    | the tenancy wall — and every per-record decision is `InventoryPolicy`. Same
    | reasoning as the seller Offer and Store resources.
    |
    | Nothing is created here: a pool exists because a seller listed something.
    | Nothing is edited here: the quantity belongs to the Offer form. Nothing is
    | deleted here: the ledger is evidence.
    */

    public static function canViewAny(): bool
    {
        return true;
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
     * One pool, read. The three numbers are shown TOGETHER and in that order
     * because the seller's question is always the subtraction: ten on the shelf,
     * three promised, seven for sale.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('inventory.section.pool'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('product_uuid')
                        ->label(__('inventory.field.product'))
                        ->columnSpan(2)
                        ->state(fn (StockItem $record): string => app(CatalogLabels::class)->productTitle($record->product_uuid)),
                    Infolists\Components\TextEntry::make('variant_uuid')
                        ->label(__('inventory.field.variant'))
                        ->state(fn (StockItem $record): string => app(CatalogLabels::class)->variantLabel($record->variant_uuid)),

                    Infolists\Components\TextEntry::make('on_hand')
                        ->label(__('inventory.field.on_hand'))
                        ->helperText(__('inventory.field.on_hand_hint')),
                    Infolists\Components\TextEntry::make('reserved')
                        ->label(__('inventory.field.reserved'))
                        ->helperText(__('inventory.field.reserved_hint')),
                    Infolists\Components\TextEntry::make('available')
                        ->label(__('inventory.field.available'))
                        ->helperText(__('inventory.field.available_hint'))
                        ->badge()
                        ->state(fn (StockItem $record): int => $record->available()),

                    Infolists\Components\TextEntry::make('low_stock_threshold')
                        ->label(__('inventory.field.low_stock_threshold'))
                        ->placeholder(__('inventory.field.no_threshold')),
                    Infolists\Components\TextEntry::make('updated_at')
                        ->label(__('inventory.field.updated_at'))
                        ->dateTime(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // `product_uuid` is what the row stores; the title is fetched,
                // never copied onto it (ADR-037).
                Tables\Columns\TextColumn::make('product_uuid')
                    ->label(__('inventory.field.product'))
                    ->state(fn (StockItem $record): string => app(CatalogLabels::class)->productTitle($record->product_uuid))
                    ->description(fn (StockItem $record): string => app(CatalogLabels::class)->variantLabel($record->variant_uuid))
                    ->wrap(),

                Tables\Columns\TextColumn::make('on_hand')
                    ->label(__('inventory.field.on_hand'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                // The number that only exists because reservations do. Warned
                // rather than plain when anything is held, because a seller
                // seeing "3 reserved" is the explanation they came for.
                Tables\Columns\TextColumn::make('reserved')
                    ->label(__('inventory.field.reserved'))
                    ->badge()
                    ->color(fn (StockItem $record): string => $record->reserved > 0 ? 'warning' : 'gray')
                    ->sortable(),

                /*
                | THE POINT OF THIS PAGE. Computed, never stored (ADR-048) — and
                | not sortable for that reason: sorting would mean computing it
                | for the whole result set and ordering in PHP.
                */
                Tables\Columns\TextColumn::make('available')
                    ->label(__('inventory.field.available'))
                    ->badge()
                    ->state(fn (StockItem $record): int => $record->available())
                    ->color(fn (StockItem $record): string => match (true) {
                        $record->available() === 0 => 'danger',
                        $record->isLowStock() => 'warning',
                        default => 'success',
                    }),

                Tables\Columns\TextColumn::make('low_stock_threshold')
                    ->label(__('inventory.field.low_stock_threshold'))
                    ->placeholder(__('inventory.field.no_threshold'))
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('low_stock')
                    ->label(__('inventory.filter.low_stock'))
                    // Availability is computed, so the filter compares the two
                    // stored columns against the threshold in SQL rather than
                    // reading a column that does not exist.
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('low_stock_threshold')
                        ->whereRaw('(on_hand - reserved) <= low_stock_threshold')),

                Tables\Filters\Filter::make('out_of_stock')
                    ->label(__('inventory.filter.out_of_stock'))
                    ->query(fn (Builder $query): Builder => $query->whereRaw('(on_hand - reserved) <= 0')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::setThresholdAction(),
            ])
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-archive-box')
            ->emptyStateHeading(__('inventory.empty.heading'))
            ->emptyStateDescription(__('inventory.empty.description'))
            ->defaultSort('id', 'desc');
    }

    /**
     * THE TENANCY WALL (ADR-030).
     *
     * @return Builder<StockItem>
     */
    public static function getEloquentQuery(): Builder
    {
        $ids = app(OrganizationAuthorizationContract::class)
            ->organizationIdsForUser((int) auth()->id());

        /** @var Builder<StockItem> $query */
        $query = parent::getEloquentQuery();

        return $query->whereIn('selling_org_id', $ids);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStock::route('/'),
            'view' => Pages\ViewStock::route('/{record}'),
        ];
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            MovementsRelationManager::class,
        ];
    }

    /**
     * The seller's warning line — the one write on this surface.
     *
     * A PREFERENCE, NOT A COUNT, which is why the action records no movement: the
     * ledger explains where stock went, and "tell me at five" is not stock going
     * anywhere.
     */
    private static function setThresholdAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('set_threshold')
            ->label(__('inventory.action.set_threshold'))
            ->icon('heroicon-o-bell-alert')
            ->form([
                Forms\Components\TextInput::make('threshold')
                    ->label(__('inventory.field.low_stock_threshold'))
                    ->helperText(__('inventory.field.low_stock_threshold_hint'))
                    ->numeric()
                    ->minValue(0)
                    ->default(fn (StockItem $record): ?int => $record->low_stock_threshold),
            ])
            ->visible(fn (StockItem $record): bool => auth()->user()?->can('setLowStockThreshold', $record) === true)
            ->action(function (StockItem $record, array $data): void {
                $threshold = $data['threshold'] ?? null;

                app(SetLowStockThresholdAction::class)->run($record, new SetLowStockThresholdDTO(
                    // Empty means "stop telling me" — distinct from 0, which
                    // means "tell me when I actually run out".
                    threshold: $threshold === null || $threshold === '' ? null : (int) $threshold,
                ));

                Notification::make()->title(__('inventory.notice.threshold_set'))->success()->send();
            });
    }
}
