<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Filament\Seller\Resources;

use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Core\Domain\Contracts\StoreQueryContract;
use App\Core\Presentation\Support\MoneyString;
use App\Modules\Order\Application\Actions\CancelOrderAction;
use App\Modules\Order\Domain\DTOs\CancelOrderDTO;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Presentation\Filament\RelationManagers\LinesRelationManager;
use App\Modules\Order\Presentation\Filament\Seller\Resources\OrderResource\Pages;
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
 * "Siparişlerim" — the orders placed WITH this seller (§4).
 *
 * ONE SELLER'S HALF OF A PURCHASE, and that is what the split bought (ADR-052).
 * A merchant sees their own order, their own lines, their own total — and no part
 * of what the customer bought from anybody else. There is no cross-seller row
 * anywhere for a mis-scoped query to leak.
 *
 * MEMBERSHIP-SCOPED THROUGH THE STORE (ADR-030/040). The order carries the seller
 * as a UUID; `OrganizationAuthorizationContract` answers memberships in internal
 * IDS. `StoreQueryContract` is the bridge — it turns each of the actor's
 * organization ids into that organization's live store uuids, and the query
 * filters on those. That indirection is the price of Order importing no module,
 * and it is paid once here rather than per row.
 *
 * TWO THINGS ARE ABSENT AND BOTH ARE DELIBERATE:
 *
 *   NO EDIT. The lines are immutable and the totals were written once (ADR-053).
 *   A seller who needs a different order cancels this one and the customer places
 *   another — which leaves both facts on the record, unlike an edit.
 *
 *   NO "CONFIRM". Order.md §4 mentions confirm/cancel, and confirm has nothing to
 *   do this sprint: an order is `AwaitingPayment` and the next real transition
 *   belongs to Payment (ADR-054/055). A button that only moved a status nothing
 *   else reads would be a lie about what the platform can do — recorded as an
 *   open follow-up instead.
 *
 * SO CANCEL IS THE ONE LEVER, and it needs a reason: refusing somebody's order
 * without saying why is a support ticket waiting to happen.
 *
 * @see App\Modules\Order\Presentation\Filament\Resources\OrderResource the admin twin
 * @see docs/modules/Order.md §4
 */
final class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'order_number';

    public static function getNavigationGroup(): string
    {
        return __('nav.orders');
    }

    public static function getModelLabel(): string
    {
        return __('order.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('order.plural');
    }

    /**
     * The listing is open to any seller reaching the panel — the query below is
     * the tenancy wall — and `OrderPolicy` owns every per-record decision.
     */
    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        // Orders are made by customers. A seller creating one would be buying on
        // their behalf.
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

                    Infolists\Components\TextEntry::make('items_total_minor')
                        ->label(__('order.field.items_total'))
                        ->state(fn (Order $record): string => self::money($record, $record->items_total_minor)),
                    Infolists\Components\TextEntry::make('tax_total_minor')
                        ->label(__('order.field.tax_total'))
                        // KDV-INCLUDED (ADR-042): this is part of the total above,
                        // not added to it — said in the hint because a seller
                        // reading two numbers will otherwise sum them.
                        ->helperText(__('order.field.tax_total_hint'))
                        ->state(fn (Order $record): string => self::money($record, $record->tax_total_minor)),
                    Infolists\Components\TextEntry::make('grand_total_minor')
                        ->label(__('order.field.grand_total'))
                        ->weight('bold')
                        ->state(fn (Order $record): string => self::money($record, $record->grand_total_minor)),
                ]),

            /*
            | THE SNAPSHOT, NOT THE CUSTOMER'S CURRENT ADDRESS (ADR-053/056). What
            | a seller ships to is what was agreed at placement — a customer who
            | has since moved does not silently redirect a parcel already being
            | packed.
            */
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
                    Infolists\Components\TextEntry::make('cancellation_reason')
                        ->label(__('order.field.reason'))
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

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('order.field.placed_at'))
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('lines_count')
                    ->label(__('order.field.line_count'))
                    ->counts('lines'),

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
                static::cancelAction(),
            ])
            // Refusing somebody's order is a decision with a reason attached.
            // There is no version of it that belongs on a checkbox.
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-shopping-bag')
            ->emptyStateHeading(__('order.empty.heading'))
            ->emptyStateDescription(__('order.empty.description'))
            ->defaultSort('id', 'desc');
    }

    /**
     * The seller's one lever (§3.3, ADR-057).
     *
     * A REASON IS REQUIRED, unlike on the customer's own cancellation: "changed my
     * mind" needs no explanation, but a merchant refusing an order somebody placed
     * does — and the customer will be shown it.
     *
     * AND THE SELLER IS TOLD WHAT ELSE THIS DOES. Cancelling because they cannot
     * fulfil zeroes their declared stock for that variant (anti-oversell, ADR-057)
     * — a real consequence that would be a nasty surprise discovered afterwards, so
     * the confirmation says it in as many words before they commit.
     */
    private static function cancelAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('cancel')
            ->label(__('order.action.cancel'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('order.action.cancel'))
            // The warning, not a generic "are you sure": their stock goes to zero.
            ->modalDescription(__('order.action.cancel_confirm_seller'))
            ->modalSubmitActionLabel(__('order.action.cancel_confirm_button'))
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('order.field.reason'))
                    ->helperText(__('order.action.cancel_reason_hint'))
                    ->required()
                    ->maxLength(500),
            ])
            ->visible(fn (Order $record): bool => auth()->user()?->can('cancel', $record) === true)
            ->action(function (Order $record, array $data): void {
                app(CancelOrderAction::class)->run($record, new CancelOrderDTO(
                    // The DTO forces the zero for this actor whatever a surface
                    // passes — a screen that forgot the flag must not silently
                    // re-list goods the seller has just said they do not have.
                    cancelledBy: CancelOrderDTO::BY_SELLER,
                    reason: (string) $data['reason'],
                ));

                Notification::make()
                    ->title(__('order.notice.cancelled'))
                    ->body(__('order.notice.stock_zeroed'))
                    ->success()
                    ->send();
            });
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
     * THE TENANCY WALL (ADR-030/040), via the store.
     *
     * The order holds the seller as a uuid; the Core authorization contract speaks
     * internal ids. `StoreQueryContract::liveStoresForOrganization()` is the one
     * bridge between them — resolved once per request rather than per row, and
     * with no Organization or Store model imported.
     *
     * A user who belongs to nothing gets an empty table, never everyone's orders.
     *
     * @return Builder<Order>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Order> $query */
        $query = parent::getEloquentQuery();

        return $query->with('currency')->whereIn('store_uuid', self::sellerStoreUuids());
    }

    /**
     * Every store uuid the acting user's organizations own.
     *
     * @return array<int, string>
     */
    public static function sellerStoreUuids(): array
    {
        $organizationIds = app(OrganizationAuthorizationContract::class)
            ->organizationIdsForUser((int) auth()->id());

        $stores = app(StoreQueryContract::class);
        $uuids = [];

        foreach ($organizationIds as $organizationId) {
            foreach (array_keys($stores->liveStoresForOrganization($organizationId)) as $storeUuid) {
                $uuids[] = (string) $storeUuid;
            }
        }

        return $uuids;
    }

    /**
     * @param  array<string, string|null>|null  $address
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
