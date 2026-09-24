<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Filament\Seller\Resources;

use App\Core\Domain\Contracts\OrderCancellationContract;
use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Core\Domain\Contracts\StoreQueryContract;
use App\Core\Presentation\Support\MoneyString;
use App\Models\Customer;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Presentation\Filament\RelationManagers\LinesRelationManager;
use App\Modules\Order\Presentation\Filament\Seller\Resources\OrderResource\Pages;
use App\Modules\Order\Presentation\Support\OrderPartyLabels;
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
    /**
     * A form field cannot be named by a bare uuid — Filament reads dots and
     * dashes as nesting — so each line's input is prefixed and the prefix is
     * stripped back off when the quantities are collected.
     */
    private const LINE_FIELD_PREFIX = 'line_';

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

                /*
                | WHO BOUGHT IT (2026-09-23). A seller's list had an order number,
                | a date and a total — nothing naming the person the parcel is
                | for, so matching a customer's phone call to a row meant opening
                | them one by one. The BUYER's name, not the recipient on the
                | address: the two differ on a gift, and the person whose account
                | a dispute runs through is the one who placed the order.
                */
                Tables\Columns\TextColumn::make('customer_uuid')
                    ->label(__('order.field.customer'))
                    ->state(fn (Order $record): string => app(OrderPartyLabels::class)
                        ->customerName($record->customer_uuid))
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::applyCustomerSearch($query, $search)),

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
                /*
                | **THE PLAIN CANCEL IS GONE** (2026-09-24). It only ever touched
                | `Pending` and `AwaitingPayment` (`isCancellableWithoutRefund`,
                | ADR-065), and §16 stopped listing those — so the button could
                | not appear on any row this table can show. A paid order is
                | undone by refunding it, which is the action below.
                */
                self::cancelLinesAction(),
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

        /*
        | **ONLY ORDERS THAT BECAME SALES** (2026-09-24, owner's decision). A
        | seller's list was showing baskets that were placed and never paid for
        | and ones the expiry sweep had already ended — rows with nothing to pack,
        | nothing owed and nothing to answer for, mixed in with the real work.
        |
        | THE TENANCY WALL STILL COMES FIRST. This narrows what a seller sees of
        | their OWN orders; it is not what keeps them out of anybody else's.
        */
        return $query
            ->with('currency')
            ->whereIn('store_uuid', self::sellerStoreUuids())
            ->whereNotIn('status', array_map(
                static fn (OrderStatus $status): string => $status->value,
                array_filter(
                    OrderStatus::cases(),
                    static fn (OrderStatus $status): bool => $status->moneyNeverArrived(),
                ),
            ));
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
     * Narrow the list to orders whose BUYER matches the search box.
     *
     * The column holds a uuid, so Filament's own `searchable()` would match a
     * string the seller has never seen. The name lives on the customer's account
     * — a different table, and one this module reaches only through the
     * authentication tier — so the term is resolved to uuids first and the
     * tenancy-scoped query filters on those. An unmatched term empties the table
     * rather than being ignored.
     *
     * @param Builder<Order> $query
     *
     * @return Builder<Order>
     */
    private static function applyCustomerSearch(Builder $query, string $search): Builder
    {
        $uuids = Customer::query()
            ->where(function (Builder $scoped) use ($search): Builder {
                return $scoped
                    ->where('first_name', 'LIKE', '%'.$search.'%')
                    ->orWhere('last_name', 'LIKE', '%'.$search.'%');
            })
            ->limit(200)
            ->pluck('uuid')
            ->all();

        return $query->whereIn('customer_uuid', $uuids);
    }

    /**
     * "Gönderemiyorum" — shedding lines of a PAID, unshipped order (ADR-065, C1).
     *
     * **THE SECOND CANCEL BUTTON ON THIS SCREEN, AND THEY MUST NEVER BOTH SHOW.**
     * The one above releases a HOLD on an unpaid order and zeroes the seller's
     * declared stock; this one sends real money back, reverses the commission and
     * RESTOCKS. `OrderStatus` decides which is which — `isCancellableWithoutRefund()`
     * gates the first, `Paid` gates this — so a seller never sees a choice between
     * two things that look identical and are not.
     *
     * **IT DRIVES A CORE COMMAND PORT, WHICH IS WHY IT CAN EXIST HERE AT ALL.**
     * The seller cancels from the screen where they see their orders, and that
     * screen is Order's; the refund is Payment's. Neither module may import the
     * other, so `OrderCancellationContract` sits between them — and it is a
     * command port rather than an event because the seller has to be TOLD, now,
     * that they asked for three of two or that the parcel already shipped.
     *
     * THE FORM IS BUILT FROM PAYMENT'S ANSWER, not from the order's own lines: a
     * line partly cancelled last week has fewer units left, and only Payment has
     * subtracted them. An order with nothing left — or one whose parcel has gone —
     * yields an empty list, and the button hides itself rather than opening onto a
     * form that can only fail.
     *
     * A REASON IS REQUIRED, as it is on the lever above and for the same reason:
     * the buyer is about to be refunded for something they chose to buy, and
     * "why" is the first thing they will ask.
     */
    private static function cancelLinesAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('cancelLines')
            ->label(__('order.action.cancel_lines'))
            ->icon('heroicon-o-receipt-refund')
            ->color('danger')
            ->modalHeading(__('order.action.cancel_lines'))
            ->modalDescription(__('order.action.cancel_lines_confirm'))
            ->modalSubmitActionLabel(__('order.action.cancel_lines_button'))
            ->visible(fn (Order $record): bool => $record->status === OrderStatus::Paid
                && auth()->user()?->can('cancelLines', $record) === true
                && self::cancellableLines($record) !== [])
            ->form(fn (Order $record): array => self::cancelLinesForm($record))
            ->action(function (Order $record, array $data): void {
                $quantities = [];

                foreach (self::cancellableLines($record) as $lineUuid => $remaining) {
                    $wanted = (int) ($data[self::LINE_FIELD_PREFIX.$lineUuid] ?? 0);

                    if ($wanted > 0) {
                        $quantities[$lineUuid] = $wanted;
                    }
                }

                if ($quantities === []) {
                    Notification::make()
                        ->title(__('order.notice.nothing_cancelled'))
                        ->warning()
                        ->send();

                    return;
                }

                app(OrderCancellationContract::class)->cancelLinesBySeller(
                    orderUuid: $record->uuid,
                    // THE ORDER'S OWN COLUMN, not the actor's organization: the
                    // port re-checks that the two match, so passing the record's
                    // value makes a mis-scoped panel query a refusal rather than a
                    // cancellation of somebody else's sale.
                    sellerOrgUuid: $record->selling_org_uuid,
                    quantities: $quantities,
                    reason: (string) $data['reason'],
                    actorId: (int) auth()->id(),
                );

                Notification::make()
                    ->title(__('order.notice.lines_cancelled'))
                    ->body(__('order.notice.lines_cancelled_body'))
                    ->success()
                    ->send();
            });
    }

    /**
     * One numeric input per line that still has units, capped at what is left.
     *
     * @return array<int, Forms\Components\Component>
     */
    private static function cancelLinesForm(Order $record): array
    {
        $fields = [];
        $remaining = self::cancellableLines($record);

        foreach ($record->lines as $line) {
            if (! isset($remaining[$line->uuid])) {
                continue;
            }

            $fields[] = Forms\Components\TextInput::make(self::LINE_FIELD_PREFIX.$line->uuid)
                ->label($line->product_title)
                // The cap is a HINT, not the guard: `RefundableLines` re-checks it
                // behind the port, because two people can hold this screen open.
                ->helperText(__('order.action.cancel_lines_remaining', ['count' => $remaining[$line->uuid]]))
                ->numeric()
                ->minValue(0)
                ->maxValue($remaining[$line->uuid])
                ->default(0)
                ->required();
        }

        $fields[] = Forms\Components\Textarea::make('reason')
            ->label(__('order.field.reason'))
            ->helperText(__('order.action.cancel_lines_reason_hint'))
            ->required()
            ->maxLength(500);

        return $fields;
    }

    /**
     * What Payment says may still be cancelled — line uuid => units.
     *
     * @return array<string, int>
     */
    private static function cancellableLines(Order $record): array
    {
        return app(OrderCancellationContract::class)
            ->cancellableQuantities($record->uuid, $record->selling_org_uuid);
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
