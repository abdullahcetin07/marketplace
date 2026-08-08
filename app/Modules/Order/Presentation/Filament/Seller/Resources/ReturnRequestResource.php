<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Filament\Seller\Resources;

use App\Core\Domain\Contracts\ShipmentQueryContract;
use App\Modules\Order\Application\Actions\ApproveReturnAction;
use App\Modules\Order\Application\Actions\CompleteReturnRequestAction;
use App\Modules\Order\Application\Actions\RejectReturnAction;
use App\Modules\Order\Domain\Enums\ReturnRequestStatus;
use App\Modules\Order\Domain\Models\ReturnRequest;
use App\Modules\Order\Presentation\Filament\Seller\Resources\ReturnRequestResource\Pages;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * "İade talepleri" — what buyers want to send back, and what this seller does
 * about it (ADR-073).
 *
 * **THREE BUTTONS WHERE THE CANCELLATION INBOX HAS TWO**, and the third is the
 * whole amendment. A cancellation is settled when the seller answers, because the
 * goods never left. A return is not: approving only tells the buyer HOW to send it
 * back, and **"İadeyi tamamla" is the one that moves money** — pressed when the
 * parcel is physically on the shelf.
 *
 * **AN INBOX, NOT A LIST.** It opens on `requested`, and the badge counts
 * everything still OPEN — approved returns included, because a parcel in transit
 * is work this seller has not finished. A buyer waiting on an unanswered return is
 * waiting on their own money.
 *
 * NO CREATE, NO EDIT, NO DELETE — the same three refusals the cancellation inbox
 * makes, for the same reasons: a seller inventing a buyer's return would be
 * refunding on their behalf, an answer is a decision rather than a draft, and a
 * rejection somebody wants to erase is exactly the row a dispute needs.
 *
 * **COMPLETING CAN FAIL, AND THE SELLER MUST BE TOLD.** The PSP can refuse, the
 * window can have closed while the parcel travelled, a line can have been refunded
 * by an admin meanwhile. Every one of those throws behind the Core port, and this
 * surface catches it into a warning notification — a 500 on the button that
 * returns somebody's money is the worst place on the platform for a stack trace.
 *
 * MEMBERSHIP-SCOPED THROUGH THE ORDER'S STORE, reusing `OrderResource`'s tenancy
 * wall rather than re-deriving it.
 *
 * @see docs/modules/Order.md §3.6
 */
final class ReturnRequestResource extends Resource
{
    protected static ?string $model = ReturnRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?int $navigationSort = 12;

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function getNavigationGroup(): string
    {
        return __('nav.orders');
    }

    public static function getModelLabel(): string
    {
        return __('order.return.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('order.return.plural');
    }

    /**
     * What is still OPEN — requested AND approved.
     *
     * **NOT JUST THE UNANSWERED ONES**, which is where this differs from the
     * cancellation badge. An approved return is a parcel on its way to this
     * seller; it needs completing when it lands, and a badge that dropped to zero
     * on approval would let it be forgotten with the buyer's money still taken.
     */
    public static function getNavigationBadge(): ?string
    {
        $open = self::getEloquentQuery()->open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order.order_number')
                    ->label(__('order.field.number'))
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('order.return.requested_at'))
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('line_quantities')
                    ->label(__('order.return.units'))
                    // A COUNT, NOT THE PAYLOAD. Which lines is the order screen's
                    // job; what this queue needs is "how big is it".
                    ->state(fn (ReturnRequest $record): int => $record->totalQuantity()),

                Tables\Columns\TextColumn::make('reason')
                    ->label(__('order.return.buyer_reason'))
                    ->placeholder('—')
                    ->wrap()
                    ->limit(80),

                Tables\Columns\TextColumn::make('return_code')
                    ->label(__('order.return.code'))
                    ->placeholder('—')
                    ->copyable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('order.field.status'))
                    ->badge()
                    ->color(fn (ReturnRequestStatus $state): string => $state->color())
                    ->formatStateUsing(fn (ReturnRequestStatus $state): string => $state->label()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('order.field.status'))
                    ->options(fn (): array => collect(ReturnRequestStatus::cases())
                        ->mapWithKeys(fn (ReturnRequestStatus $s): array => [$s->value => $s->label()])
                        ->all())
                    ->default(ReturnRequestStatus::Requested->value),
            ])
            ->actions([
                self::approveAction(),
                self::rejectAction(),
                self::completeAction(),
            ])
            // Completing sends real money back and rejecting owes somebody a
            // sentence. Neither belongs on a checkbox.
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-arrow-uturn-left')
            ->emptyStateHeading(__('order.return.empty'))
            ->defaultSort('id', 'desc');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReturnRequests::route('/'),
        ];
    }

    /**
     * THE TENANCY WALL, borrowed from `OrderResource` rather than rebuilt — two
     * spellings of "which orders are mine" is how one of them ends up wider.
     *
     * The eager loads are declared here because strict mode throws on a lazy one
     * (CLAUDE.md) and every column below reads through `order`.
     *
     * @return Builder<ReturnRequest>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<ReturnRequest> $query */
        $query = parent::getEloquentQuery();

        return $query
            ->with(['order.currency'])
            ->whereHas('order', function (Builder $order): void {
                $order->whereIn('store_uuid', OrderResource::sellerStoreUuids());
            });
    }

    /**
     * "Onayla" — tell the buyer how to send it back. **No money.**
     */
    private static function approveAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('approve')
            ->label(__('order.return.approve'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->modalHeading(__('order.return.approve'))
            ->modalDescription(__('order.return.approve_hint'))
            ->modalSubmitActionLabel(__('order.return.approve_button'))
            ->form([
                Forms\Components\TextInput::make('return_code')
                    ->label(__('order.return.code'))
                    ->helperText(__('order.return.code_hint'))
                    ->required()
                    ->maxLength(100),

                Forms\Components\Select::make('cargo_company_uuid')
                    ->label(__('order.return.cargo'))
                    /*
                    | SHIPPING'S OWN LIST, THROUGH THE CORE PORT — Order may not
                    | import `CargoCompany` (`LayeringTest`), and a second copy
                    | would drift the day an operator disabled a carrier. Active
                    | only, filtered inside the query.
                    */
                    ->options(fn (): array => app(ShipmentQueryContract::class)->activeCargoCompanies())
                    ->searchable()
                    ->required(),
            ])
            ->visible(fn (ReturnRequest $record): bool => $record->status === ReturnRequestStatus::Requested
                && $record->order !== null
                && auth()->user()?->can('decideReturn', $record->order) === true)
            ->action(function (ReturnRequest $record, array $data): void {
                app(ApproveReturnAction::class)->run(
                    $record,
                    (string) $data['return_code'],
                    $data['cargo_company_uuid'] ?? null,
                    (int) auth()->id(),
                );

                Notification::make()
                    ->title(__('order.return.approved_notice'))
                    ->body(__('order.return.approved_body'))
                    ->success()
                    ->send();
            });
    }

    /**
     * "Reddet" — and say why.
     */
    private static function rejectAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reject')
            ->label(__('order.return.reject'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading(__('order.return.reject'))
            ->modalSubmitActionLabel(__('order.return.reject_button'))
            ->form([
                Forms\Components\Textarea::make('decision_reason')
                    ->label(__('order.return.decision_reason'))
                    ->helperText(__('order.return.decision_reason_hint'))
                    ->required()
                    ->maxLength(500),
            ])
            ->visible(fn (ReturnRequest $record): bool => $record->status === ReturnRequestStatus::Requested
                && $record->order !== null
                && auth()->user()?->can('decideReturn', $record->order) === true)
            ->action(function (ReturnRequest $record, array $data): void {
                app(RejectReturnAction::class)->run(
                    $record,
                    (int) auth()->id(),
                    (string) $data['decision_reason'],
                );

                Notification::make()
                    ->title(__('order.return.rejected_notice'))
                    ->success()
                    ->send();
            });
    }

    /**
     * "İadeyi tamamla" — the parcel is on the shelf, so the money goes back.
     *
     * **THE ONLY BUTTON IN THIS INBOX THAT SPENDS ANYTHING**, which is why the
     * confirmation says what happens rather than asking "are you sure": the
     * refund fires, the commission reverses, the units come back to stock and the
     * order becomes `refunded`. None of it is undoable from here.
     *
     * **ONLY WHEN APPROVED.** Completing a request the seller never agreed to
     * would refund a parcel still in the buyer's hallway.
     */
    private static function completeAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('complete')
            ->label(__('order.return.complete'))
            ->icon('heroicon-o-banknotes')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('order.return.complete'))
            ->modalDescription(__('order.return.complete_confirm'))
            ->modalSubmitActionLabel(__('order.return.complete_button'))
            ->visible(fn (ReturnRequest $record): bool => $record->status === ReturnRequestStatus::Approved
                && $record->order !== null
                && auth()->user()?->can('decideReturn', $record->order) === true)
            ->action(function (ReturnRequest $record): void {
                try {
                    app(CompleteReturnRequestAction::class)->run(
                        $record,
                        // THE ORDER'S OWN COLUMN, not the actor's organization:
                        // the Core port re-checks that the two match, so a
                        // mis-scoped panel query becomes a refusal rather than a
                        // refund of somebody else's sale.
                        (string) $record->order?->selling_org_uuid,
                        (int) auth()->id(),
                    );
                } catch (Throwable $exception) {
                    /*
                    | THE PSP REFUSED, OR THE WINDOW CLOSED WHILE THE PARCEL
                    | TRAVELLED, OR AN ADMIN REFUNDED THE LINE MEANWHILE. All of
                    | them are legitimate refusals and none is an incident — the
                    | request stays `Approved` (refund first, stamp second), so
                    | the seller may press it again once the cause is fixed.
                    */
                    Notification::make()
                        ->title(__('order.return.complete_failed'))
                        ->body($exception->getMessage())
                        ->warning()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('order.return.completed_notice'))
                    ->body(__('order.return.completed_body'))
                    ->success()
                    ->send();
            });
    }
}
