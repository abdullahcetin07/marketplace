<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Filament\Widgets;

use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Presentation\Support\OrderPartyLabels;
use App\Shared\Support\PublicKey;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What this shopper has ordered, on their account page (Order.md §17).
 *
 * **IT LIVES IN ORDER AND READS ORDER'S OWN TABLES.** Identity renders it
 * through `AccountPanelRegistry` without knowing what it is; there is no Core
 * query method for it and none is needed, because the module that owns the data
 * owns the panel. @see App\Modules\Order\Presentation\Accounts\CustomerOrdersPanel
 *
 * **MATCHED BY `customer_uuid`, NOT A RELATION.** An order holds the shopper as
 * a bare uuid (ADR-040) exactly so these two modules need not share a foreign
 * key, so the query is a `where` on the record's uuid rather than a relation
 * Filament could manage for us.
 *
 * **EVERY STATUS, UNLIKE THE SELLER'S LIST.** A seller sees only what became a
 * sale (§16) because the rest is not their work; an admin on this page is
 * answering a question about a person, and "they tried four times and it never
 * went through" is very often the answer.
 */
final class CustomerOrdersWidget extends TableWidget
{
    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Siparişler';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->ordersQuery())
            ->defaultSort('id', 'desc')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label(__('order.field.number'))
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('store_uuid')
                    ->label(__('order.field.seller'))
                    ->state(fn (Order $record): string => app(OrderPartyLabels::class)
                        ->storeName($record->store_uuid)),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('order.field.placed_at'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('grand_total_minor')
                    ->label(__('order.field.grand_total'))
                    ->state(fn (Order $record): string => money(
                        (int) $record->grand_total_minor,
                        $record->currency,
                    )),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('order.field.status'))
                    ->badge()
                    ->color(fn (OrderStatus $state): string => $state->color())
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label()),
            ])
            ->emptyStateHeading(__('order.account.no_orders'));
    }

    /**
     * This shopper's orders, newest first.
     *
     * **`currency` IS EAGER-LOADED.** Strict mode throws on a lazy load and
     * Laravel only arms that guard above one row, so a one-order customer would
     * render happily and a two-order one would 500 (CLAUDE.md).
     *
     * @return Builder<Order>
     */
    private function ordersQuery(): Builder
    {
        /** @var Builder<Order> $query */
        $query = Order::query()->with('currency');

        $uuid = $this->record?->getAttribute('uuid');

        /*
        | NO RECORD, NO ORDERS — never every order on the platform. The shape is
        | checked as well as the presence (ADR-059): `orders.customer_uuid` is a
        | PostgreSQL `uuid` column, and reaching it with anything else is
        | SQLSTATE[22P02] rather than an empty result. The value here comes off a
        | loaded model and is a uuid by construction, but this is a Presentation
        | class and `UuidLookupGuardTest` holds the whole layer to the same rule —
        | an allow-list that grows one defensible entry at a time is how the rule
        | stops being read.
        */
        if (! is_string($uuid) || ! PublicKey::looksLikeUuid($uuid)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('customer_uuid', $uuid);
    }
}
