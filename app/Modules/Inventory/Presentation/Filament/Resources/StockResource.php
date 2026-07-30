<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Filament\Resources;

use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Inventory\Presentation\Filament\RelationManagers\MovementsRelationManager;
use App\Modules\Inventory\Presentation\Filament\Resources\StockResource\Pages;
use App\Modules\Inventory\Presentation\Support\CatalogLabels;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin stock oversight — READ, AND ONLY READ.
 *
 * THE SHORTEST RESOURCE IN THE PLATFORM, and that is the point. Every other admin
 * oversight surface has at least one lever: Store can suspend, Offer can pull,
 * Product can reject. This one has none, because there is no operator action on a
 * merchant's stock that is defensible. Editing someone's count is not oversight,
 * it is trading on their behalf, and the audit entry would name the wrong party
 * (§7). `InventoryPolicy::update()` refuses it and this resource offers no way to
 * try — no create page, no edit page, no bulk action, not even a threshold form.
 *
 * SO WHAT IS IT FOR: answering "the site says sold out and I have ten". That
 * needs on-hand, reserved, available and the MOVEMENT HISTORY across every
 * seller, which no seller-facing page can show. Support holds
 * `inventory.view_any` for exactly this ticket.
 *
 * Cross-org by construction — a platform power gated on `inventory.*` Spatie
 * permissions, never on an organization capability.
 *
 * @see App\Modules\Inventory\Presentation\Filament\Seller\Resources\StockResource the seller twin
 * @see docs/modules/Inventory.md §4, §7
 */
final class StockResource extends Resource
{
    protected static ?string $model = StockItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function getNavigationGroup(): string
    {
        return __('nav.catalogue');
    }

    public static function getModelLabel(): string
    {
        return __('inventory.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('inventory.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', StockItem::class) === true;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) === true;
    }

    /*
    |--------------------------------------------------------------------------
    | Every write verb, refused
    |--------------------------------------------------------------------------
    |
    | Spelled out rather than left to the policy so the refusal is visible where
    | someone would go looking to add a form. @see the class docblock.
    */

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

                    // The seller, as a uuid. Copyable rather than resolved to a
                    // legal name: Inventory imports no Organization (ADR-040),
                    // and a support agent pastes it into the seller area.
                    Infolists\Components\TextEntry::make('selling_org_uuid')
                        ->label(__('inventory.field.seller'))
                        ->columnSpanFull()
                        ->copyable(),

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
                Tables\Columns\TextColumn::make('product_uuid')
                    ->label(__('inventory.field.product'))
                    ->state(fn (StockItem $record): string => app(CatalogLabels::class)->productTitle($record->product_uuid))
                    ->description(fn (StockItem $record): string => app(CatalogLabels::class)->variantLabel($record->variant_uuid))
                    ->wrap(),

                // Searchable because this is how a ticket starts: an agent has a
                // seller and needs their pools.
                Tables\Columns\TextColumn::make('selling_org_uuid')
                    ->label(__('inventory.field.seller'))
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('on_hand')
                    ->label(__('inventory.field.on_hand'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('reserved')
                    ->label(__('inventory.field.reserved'))
                    ->sortable(),

                // The subtraction the ticket is about. Computed, so not sortable
                // (ADR-048) — same reason as on the seller twin.
                Tables\Columns\TextColumn::make('available')
                    ->label(__('inventory.field.available'))
                    ->badge()
                    ->state(fn (StockItem $record): int => $record->available())
                    ->color(fn (StockItem $record): string => $record->available() === 0 ? 'danger' : 'success'),
            ])
            ->filters([
                Tables\Filters\Filter::make('reserved')
                    ->label(__('inventory.filter.has_reservations'))
                    ->query(fn (Builder $query): Builder => $query->where('reserved', '>', 0)),

                Tables\Filters\Filter::make('out_of_stock')
                    ->label(__('inventory.filter.out_of_stock'))
                    ->query(fn (Builder $query): Builder => $query->whereRaw('(on_hand - reserved) <= 0')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('id', 'desc');
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
     * The movement history, shared with the seller twin — the same ledger read by
     * the same read-only manager, because there is no admin-only column in it and
     * a second copy would drift.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            MovementsRelationManager::class,
        ];
    }
}
