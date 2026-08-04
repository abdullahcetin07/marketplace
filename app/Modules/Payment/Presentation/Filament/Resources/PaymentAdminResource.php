<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Filament\Resources;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Payment\Application\Actions\RefundPaymentAction;
use App\Modules\Payment\Domain\DTOs\RefundRequestDTO;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Presentation\Filament\Resources\PaymentAdminResource\Pages;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * What was collected, and the one screen that can send it back (Payment.md §8).
 *
 * READ-ONLY EXCEPT FOR ONE ACTION, and that action is the only place in this
 * application where a click moves real money OUT. A payout screen records a
 * transfer a human already made; the callback records what a buyer already did.
 * Here, pressing the button makes PayTR return money, debits the seller's ledger
 * and puts units back on a shelf.
 *
 * SO IT ASKS TWICE. The action is behind a confirmation that says in plain
 * Turkish what is about to happen and that it cannot be undone — the same
 * treatment the destructive actions elsewhere on this platform get, for a
 * consequence that is larger than any of them.
 *
 * IT REFUNDS ORDERS, NOT AN AMOUNT. The form lists the orders in the basket and
 * the operator picks which came back; leaving it empty refunds all of them. There
 * is deliberately no lira field: an arbitrary amount could not say which seller it
 * came out of, which commission to give back, or which units to restock.
 *
 * NO CREATE, NO EDIT, NO DELETE. A payment is a record of what a bank did.
 *
 * NAMED `PaymentAdminResource` because `PaymentResource` is already the API
 * resource in `Presentation/Resources` — two classes with one name in one module
 * is the kind of thing that is only ever confusing.
 *
 * @see docs/modules/Payment.md §8
 */
final class PaymentAdminResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?int $navigationSort = 63;

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function getNavigationGroup(): string
    {
        return __('nav.finance');
    }

    public static function getModelLabel(): string
    {
        return __('payment.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payment.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Payment::class) === true;
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

    public static function form(Forms\Form $form): Forms\Form
    {
        // Nothing about a payment is editable. The resource has no create or edit
        // page; this exists because Filament requires the method.
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('uuid')
                    ->label(__('payment.reference'))
                    ->searchable()
                    ->limit(13)
                    ->copyable(),

                Tables\Columns\TextColumn::make('amount_minor')
                    ->label(__('payment.amount'))
                    ->state(fn (Payment $record): string => number_format(
                        $record->amount_minor / 100, 2, ',', '.',
                    ).' '.$record->currency->code)
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('payment.status_label'))
                    ->badge()
                    ->state(fn (Payment $record): string => $record->status->label())
                    ->color(fn (Payment $record): string => $record->status->color()),

                Tables\Columns\TextColumn::make('paid_at')
                    ->label(__('payment.paid_at'))
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('payment.status_label'))
                    ->options(fn (): array => collect(PaymentStatus::cases())
                        ->mapWithKeys(fn (PaymentStatus $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->actions([
                /*
                | THE ONLY BUTTON ON THIS PLATFORM THAT SENDS MONEY OUT. Offered
                | only where there is money to send — a pending or failed payment
                | collected nothing, and a fully refunded one has nothing left.
                */
                Tables\Actions\Action::make('refund')
                    ->label(__('payment.refund.action'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('payment.refund.confirm'))
                    ->visible(fn (Payment $record): bool => $record->status->isSettled()
                        && $record->status !== PaymentStatus::Refunded
                        && auth()->user()?->can('refund', $record) === true)
                    ->form([
                        Forms\Components\CheckboxList::make('order_ids')
                            ->label(__('payment.refund.orders'))
                            ->helperText(__('payment.refund.orders_hint'))
                            // THROUGH THE CORE READ PORT. Payment imports no
                            // module, not even to fill in a form.
                            ->options(fn (Payment $record): array => collect(
                                app(OrderQueryContract::class)->ordersForCheckoutGroup($record->checkout_group_uuid),
                            )->mapWithKeys(fn (string $uuid): array => [$uuid => $uuid])->all()),

                        Forms\Components\Textarea::make('reason')
                            ->label(__('payment.refund.reason'))
                            ->helperText(__('payment.refund.reason_hint'))
                            ->maxLength(500),
                    ])
                    ->action(function (Payment $record, array $data): void {
                        /** @var array<int, string> $orders */
                        $orders = $data['order_ids'] ?? [];

                        app(RefundPaymentAction::class)->run(new RefundRequestDTO(
                            paymentUuid: $record->uuid,
                            orderUuids: array_values($orders),
                            reason: $data['reason'] ?? null,
                            actorId: (int) auth()->id(),
                        ));

                        Notification::make()
                            ->success()
                            ->title(__('payment.refund.done'))
                            ->send();
                    }),
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
            'index' => Pages\ListPayments::route('/'),
        ];
    }
}
