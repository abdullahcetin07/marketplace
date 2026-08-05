<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Filament\Resources;

use App\Modules\Payment\Application\Actions\SettlePayoutAction;
use App\Modules\Payment\Domain\Enums\PayoutStatus;
use App\Modules\Payment\Domain\Models\Payout;
use App\Modules\Payment\Domain\Support\SellerBalance;
use App\Modules\Payment\Presentation\Filament\Resources\PayoutResource\Pages;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The finance team's payout screen (ADR-062, Payment.md §8).
 *
 * **THE SOFTWARE MOVES NO MONEY.** This screen records that an admin decided to
 * send a seller their balance, and later what the bank did about it. Nothing here
 * calls a bank, and v1 deliberately has no banking integration behind it — the
 * platform is a single merchant and settles with its sellers by its own means
 * (ADR-060 §2).
 *
 * TWO ACTIONS, TWO JOBS. Creating a payout is a decision; settling it is a
 * confirmation, and in a real finance process those are two people. They are
 * separate abilities for that reason, so an operator can grant them separately.
 *
 * THE BALANCE IS SHOWN BEFORE THE AMOUNT IS TYPED, because the alternative is an
 * admin guessing and being refused. It is read live in the form rather than
 * carried on the row: a balance is a `SUM()` of the ledger (ADR-062) and there is
 * no column to display.
 *
 * SINCE S3 IT SHOWS TWO NUMBERS (ADR-064): what is PAYABLE and what is still on
 * HOLD because the parcel has not been delivered long enough. A seller must not be
 * paid for goods the buyer can still send back, and an admin who saw only the
 * total would type it and be refused.
 *
 * NO EDIT PAGE AND NO DELETE. The money fields of a payout are immutable — the
 * ledger already debited them — and the only permitted write is the settlement,
 * which is a table action with its own confirmation. A mistaken payout is marked
 * FAILED, which reverses the debit and leaves both facts on the trail; deleting it
 * would erase a transfer somebody may actually have made.
 *
 * @see docs/modules/Payment.md §8
 */
final class PayoutResource extends Resource
{
    protected static ?string $model = Payout::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 64;

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function getNavigationGroup(): string
    {
        return __('nav.finance');
    }

    public static function getModelLabel(): string
    {
        return __('payment.payout.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payment.payout.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Payout::class) === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', Payout::class) === true;
    }

    /**
     * No edit page. The settlement is a table action; everything else is frozen.
     */
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
        return $form->schema([
            Forms\Components\TextInput::make('seller_org_uuid')
                ->label(__('payment.payout.seller'))
                ->helperText(__('payment.payout.seller_hint'))
                ->uuid()
                ->required()
                ->live(onBlur: true)
                /*
                | THE BALANCE, READ LIVE, AND SPLIT IN TWO SINCE S3 (ADR-064). A
                | `SUM()` of the ledger — there is no column to bind to (ADR-062)
                | — minus what is still on hold because the parcel has not been
                | delivered long enough. The admin sees what they may actually
                | send, and how much more is coming, before typing an amount that
                | would be refused.
                */
                ->hint(function (?string $state): ?string {
                    if ($state === null || $state === '') {
                        return null;
                    }

                    $balance = SellerBalance::for($state);

                    $hint = __('payment.payout.available').': '
                        .number_format($balance->payableMinor / 100, 2, ',', '.').' ₺';

                    if ($balance->onHoldMinor > 0) {
                        $hint .= ' · '.__('payment.payout.on_hold').': '
                            .number_format($balance->onHoldMinor / 100, 2, ',', '.').' ₺';
                    }

                    return $hint;
                }),

            /*
            | ENTERED IN LIRA, STORED IN KURUŞ — the same boundary conversion the
            | rate fields do, in one place for both directions. Asking a finance
            | admin to type 984000 for 9 840,00 TL is how a payout eventually goes
            | out at a hundred times its intended size.
            */
            Forms\Components\TextInput::make('amount_minor')
                ->label(__('payment.payout.amount'))
                ->helperText(__('payment.payout.amount_hint'))
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->prefix('₺')
                ->formatStateUsing(fn (?int $state): ?string => $state === null
                    ? null
                    : number_format($state / 100, 2, '.', ''))
                ->dehydrateStateUsing(fn (mixed $state): int => (int) round(((float) $state) * 100)),

            Forms\Components\TextInput::make('note')
                ->label(__('payment.payout.note'))
                ->helperText(__('payment.payout.note_hint'))
                ->maxLength(255),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('seller_org_uuid')
                    ->label(__('payment.payout.seller'))
                    ->searchable()
                    ->limit(13),

                Tables\Columns\TextColumn::make('amount_minor')
                    ->label(__('payment.payout.amount'))
                    ->state(fn (Payout $record): string => number_format(
                        $record->amount_minor / 100, 2, ',', '.',
                    ).' '.$record->currency->code)
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('payment.payout.status_label'))
                    ->badge()
                    ->state(fn (Payout $record): string => $record->status->label())
                    ->color(fn (Payout $record): string => $record->status->color()),

                Tables\Columns\TextColumn::make('external_reference')
                    ->label(__('payment.payout.reference'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('payment.payout.created_at'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('payment.payout.status_label'))
                    ->options(fn (): array => collect(PayoutStatus::cases())
                        ->mapWithKeys(fn (PayoutStatus $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->actions([
                /*
                | THE ONE WRITE A PAYOUT PERMITS. Only offered while pending —
                | `Payout::isSettling()` refuses it out of any other state, and
                | showing a button that would be refused is worse than showing
                | none.
                */
                Tables\Actions\Action::make('settle')
                    ->label(__('payment.payout.settle'))
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (Payout $record): bool => $record->status->isOpen()
                        && auth()->user()?->can('update', $record) === true)
                    ->form([
                        Forms\Components\Radio::make('outcome')
                            ->label(__('payment.payout.outcome'))
                            ->options([
                                PayoutStatus::Paid->value => __('payment.payout.outcome_paid'),
                                PayoutStatus::Failed->value => __('payment.payout.outcome_failed'),
                            ])
                            ->required()
                            ->live(),

                        Forms\Components\TextInput::make('detail')
                            ->label(fn (Forms\Get $get): string => $get('outcome') === PayoutStatus::Failed->value
                                ? __('payment.payout.failure_reason')
                                : __('payment.payout.reference'))
                            ->helperText(__('payment.payout.detail_hint'))
                            ->maxLength(255),
                    ])
                    ->action(function (Payout $record, array $data): void {
                        app(SettlePayoutAction::class)->run(
                            $record,
                            PayoutStatus::from((string) $data['outcome']),
                            (int) auth()->id(),
                            $data['detail'] ?? null,
                        );

                        Notification::make()
                            ->success()
                            ->title(__('payment.payout.settled'))
                            ->send();
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('id', 'desc');
    }

    /**
     * Eager-load what the table reads (CLAUDE.md: on the query, never at the call
     * site).
     *
     * THE SAME BUG AS `PaymentAdminResource`, FOUND BY LOOKING RATHER THAN BY
     * CRASHING. The amount column renders `$record->currency->code`, and strict
     * mode turns that into a `LazyLoadingViolationException` on every render — this
     * screen had simply not been opened since it shipped, because P4's tests
     * exercised the API and the actions, never the panel.
     *
     * @return Builder<Payout>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Payout> $query */
        $query = parent::getEloquentQuery();

        return $query->with('currency');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayouts::route('/'),
            'create' => Pages\CreatePayout::route('/create'),
        ];
    }
}
