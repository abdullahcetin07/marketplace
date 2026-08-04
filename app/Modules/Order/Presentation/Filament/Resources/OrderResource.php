<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Filament\Resources;

use App\Core\Presentation\Support\MoneyString;
use App\Modules\Order\Application\Actions\CancelOrderAction;
use App\Modules\Order\Domain\DTOs\CancelOrderDTO;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Presentation\Filament\RelationManagers\LinesRelationManager;
use App\Modules\Order\Presentation\Filament\Resources\OrderResource\Pages;
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
 * Admin order oversight — read, and one lever (§4, §7).
 *
 * THE SURFACE WHERE A PURCHASE IS VISIBLE AS A PURCHASE. Everywhere else the
 * split (ADR-052) is a feature: a seller sees only their own half, a customer sees
 * their own rows. Here somebody has to be able to see all N orders of one checkout
 * group at once, because "the customer says they paid for three things and only
 * two arrived" is a question about the GROUP — so it is searchable and filterable
 * by `checkout_group_uuid`, which no other surface offers.
 *
 * CANCEL IS THE ONLY WRITE, gated on `order.cancel` and held back from Support:
 * cancelling releases or strands somebody's stock and creates a refund obligation
 * once Payment exists. Reading is the helpdesk's job; undoing a sale is not. The
 * Offer-suspension shape (ADR-044) applied one module along.
 *
 * NO EDIT, NO CREATE, NO DELETE. The lines are immutable and the totals were
 * written once (ADR-053) — an operator adjusting an order's money would be
 * rewriting a financial record with the audit entry naming the wrong party.
 *
 * @see App\Modules\Order\Presentation\Filament\Seller\Resources\OrderResource the seller twin
 * @see docs/modules/Order.md §4, §7
 */
final class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'order_number';

    public static function getNavigationGroup(): string
    {
        return __('nav.sales');
    }

    public static function getModelLabel(): string
    {
        return __('order.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('order.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Order::class) === true;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) === true;
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

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('order.section.summary'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('order_number')
                        ->label(__('order.field.number'))
                        ->copyable(),
                    Infolists\Components\TextEntry::make('status')
                        ->label(__('order.field.status'))
                        ->badge()
                        ->color(fn (OrderStatus $state): string => $state->color())
                        ->formatStateUsing(fn (OrderStatus $state): string => $state->label()),
                    Infolists\Components\TextEntry::make('created_at')
                        ->label(__('order.field.placed_at'))
                        ->dateTime(),

                    /*
                    | THE HANDLE THAT REASSEMBLES A PURCHASE (ADR-052). Copyable
                    | because pasting it into the search box is how an operator
                    | pulls up the customer's other orders from the same checkout —
                    | the question the split makes harder and this surface exists
                    | to answer.
                    */
                    Infolists\Components\TextEntry::make('checkout_group_uuid')
                        ->label(__('order.field.checkout_group'))
                        ->helperText(__('order.field.checkout_group_hint'))
                        ->columnSpan(2)
                        ->copyable(),
                    Infolists\Components\TextEntry::make('customer_uuid')
                        ->label(__('order.field.customer'))
                        ->copyable(),

                    Infolists\Components\TextEntry::make('selling_org_uuid')
                        ->label(__('order.field.seller'))
                        ->copyable(),
                    Infolists\Components\TextEntry::make('items_total_minor')
                        ->label(__('order.field.items_total'))
                        ->state(fn (Order $record): string => self::money($record, $record->items_total_minor)),
                    Infolists\Components\TextEntry::make('tax_total_minor')
                        ->label(__('order.field.tax_total'))
                        ->helperText(__('order.field.tax_total_hint'))
                        ->state(fn (Order $record): string => self::money($record, $record->tax_total_minor)),
                ]),

            Infolists\Components\Section::make(__('order.section.shipping'))
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('shipping_address')
                        ->hiddenLabel()
                        ->state(fn (Order $record): string => self::formatAddress($record->shipping_address)),
                    Infolists\Components\TextEntry::make('billing_address')
                        ->label(__('order.field.billing_address'))
                        ->state(fn (Order $record): string => self::formatAddress($record->billing_address)),
                ]),

            Infolists\Components\Section::make(__('order.section.cancellation'))
                ->visible(fn (Order $record): bool => $record->status === OrderStatus::Cancelled)
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('cancelled_at')
                        ->label(__('order.field.cancelled_at'))
                        ->dateTime(),
                    Infolists\Components\TextEntry::make('cancelled_by')
                        ->label(__('order.field.cancelled_by'))
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => $state === null
                            ? '—'
                            : __("order.cancelled_by.{$state}")),
                    Infolists\Components\TextEntry::make('cancellation_reason')
                        ->label(__('order.field.reason'))
                        ->columnSpanFull()
                        ->placeholder('—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label(__('order.field.number'))
                    ->searchable()
                    ->copyable(),

                // Searchable, so pasting a group uuid pulls up every order of one
                // purchase — the whole reason this surface exists.
                Tables\Columns\TextColumn::make('checkout_group_uuid')
                    ->label(__('order.field.checkout_group'))
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('selling_org_uuid')
                    ->label(__('order.field.seller'))
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('customer_uuid')
                    ->label(__('order.field.customer'))
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('order.field.placed_at'))
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('grand_total_minor')
                    ->label(__('order.field.grand_total'))
                    ->state(fn (Order $record): string => self::money($record, $record->grand_total_minor))
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('order.field.status'))
                    ->badge()
                    ->color(fn (OrderStatus $state): string => $state->color())
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('order.field.status'))
                    ->options(fn (): array => collect(OrderStatus::cases())
                        ->mapWithKeys(fn (OrderStatus $status): array => [$status->value => $status->label()])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::cancelAction(),
            ])
            ->bulkActions([])
            ->defaultSort('id', 'desc');
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }

    /**
     * Cross-org by construction: this is a platform power gated on `order.*`
     * permissions, not on any organization membership. Currency is eager loaded
     * because every row renders money.
     *
     * @return Builder<Order>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Order> $query */
        $query = parent::getEloquentQuery();

        return $query->with('currency');
    }

    /**
     * The admin's lever — release by default, zero only when told (ADR-057).
     *
     * THE DEFAULT IS THE CONSERVATIVE ONE, deliberately. An oversight or dispute
     * cancellation is not a claim about anybody's stock: the units go back on sale
     * and the seller carries on. Zeroing every admin cancellation would take a
     * merchant's whole variant off the platform because somebody upstream was
     * arbitrating a payment dispute.
     *
     * THE TOGGLE IS THE SELLER-FAULT CASE — "they never had it" — and it does
     * exactly what the seller's own cancellation does, through the same event. It
     * is opt-in and it says what it will do, because an operator taking a
     * merchant's listing down should have to mean it.
     */
    private static function cancelAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('cancel')
            ->label(__('order.action.cancel'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('order.action.cancel_confirm'))
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('order.field.reason'))
                    ->required()
                    ->maxLength(500),

                Forms\Components\Toggle::make('zero_seller_stock')
                    ->label(__('order.action.zero_seller_stock'))
                    ->helperText(__('order.action.zero_seller_stock_hint'))
                    ->default(false),
            ])
            ->visible(fn (Order $record): bool => auth()->user()?->can('cancel', $record) === true)
            ->action(function (Order $record, array $data): void {
                app(CancelOrderAction::class)->run($record, new CancelOrderDTO(
                    cancelledBy: CancelOrderDTO::BY_ADMIN,
                    reason: (string) $data['reason'],
                    zeroSellerStock: (bool) ($data['zero_seller_stock'] ?? false),
                ));

                Notification::make()->title(__('order.notice.cancelled'))->success()->send();
            });
    }

    /**
     * @param array<string, string|null>|null $address
     */
    private static function formatAddress(?array $address): string
    {
        if ($address === null) {
            return '—';
        }

        return implode("\n", array_filter([
            $address['recipient_name'] ?? null,
            $address['phone'] ?? null,
            $address['line1'] ?? null,
            $address['line2'] ?? null,
            // Mahalle on its own line, above ilçe/il — the order a Turkish
            // address is read on a parcel. Absent for a pre-2026-08 order and
            // for every non-TR one, and `array_filter` drops the empty line.
            $address['neighborhood'] ?? null,
            trim(($address['district'] ?? '').' '.($address['city'] ?? '')),
            trim(($address['postal_code'] ?? '').' '.($address['country_code'] ?? '')),
        ]));
    }

    private static function money(Order $order, int $minor): string
    {
        return MoneyString::from($minor, $order->currency->decimal_places)
            .' '.$order->currency->code;
    }
}
