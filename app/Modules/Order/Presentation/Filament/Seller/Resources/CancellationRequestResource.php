<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Filament\Seller\Resources;

use App\Core\Presentation\Support\MoneyString;
use App\Modules\Order\Application\Actions\ApproveCancellationAction;
use App\Modules\Order\Application\Actions\RejectCancellationAction;
use App\Modules\Order\Domain\Enums\CancellationRequestStatus;
use App\Modules\Order\Domain\Models\CancellationRequest;
use App\Modules\Order\Presentation\Filament\Seller\Resources\CancellationRequestResource\Pages;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "İptal talepleri" — what buyers have asked for, and what this seller says
 * (ADR-065, C2).
 *
 * **AN INBOX, NOT A LIST.** The only rows that matter are `pending` ones, so that
 * is the default filter and the navigation badge counts them: a screen that opens
 * on six months of answered requests is a screen nobody checks, and an
 * unanswered request costs a buyer real money for as long as it sits.
 *
 * **TWO BUTTONS AND NOTHING ELSE.** No create — a seller inventing a buyer's
 * request would be cancelling on their behalf. No edit — an answer is a decision,
 * not a draft. No delete — a rejection somebody wants to erase is exactly the row
 * a dispute needs.
 *
 * **APPROVING SENDS REAL MONEY BACK**, which is why its confirmation says so in
 * as many words rather than asking "are you sure": the whole order is refunded,
 * the commission comes back, the stock returns and the parcel is closed. The
 * seller cannot undo it, and the modal is the last place to say so.
 *
 * **REJECTING REQUIRES A SENTENCE.** The column is nullable and this surface is
 * not — refusing somebody's cancellation without a word is the support ticket the
 * field exists to prevent. The buyer is shown it.
 *
 * MEMBERSHIP-SCOPED THROUGH THE ORDER'S STORE, reusing `OrderResource`'s own
 * tenancy wall rather than re-deriving it: two spellings of "which orders are
 * mine" is how one of them ends up wider than the other.
 *
 * @see docs/modules/Order.md §3.3
 */
final class CancellationRequestResource extends Resource
{
    protected static ?string $model = CancellationRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?int $navigationSort = 11;

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function getNavigationGroup(): string
    {
        return __('nav.orders');
    }

    public static function getModelLabel(): string
    {
        return __('order.cancellation.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('order.cancellation.plural');
    }

    /**
     * The count of what is WAITING, not of everything.
     *
     * A badge showing every request ever made would settle at a number that never
     * changes and that nobody reads. This one is zero when the seller is done.
     */
    public static function getNavigationBadge(): ?string
    {
        $pending = self::getEloquentQuery()->open()->count();

        return $pending > 0 ? (string) $pending : null;
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
        // A seller inventing a buyer's request is a seller cancelling on their
        // behalf. The buyer's own surface is the storefront.
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
                    ->label(__('order.cancellation.requested_at'))
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('order.grand_total_minor')
                    ->label(__('order.field.grand_total'))
                    ->state(fn (CancellationRequest $record): string => $record->order === null
                        ? '—'
                        : MoneyString::from(
                            $record->order->grand_total_minor,
                            $record->order->currency->decimal_places,
                        )),

                Tables\Columns\TextColumn::make('reason')
                    ->label(__('order.cancellation.buyer_reason'))
                    ->placeholder('—')
                    ->wrap()
                    ->limit(80),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('order.field.status'))
                    ->badge()
                    ->color(fn (CancellationRequestStatus $state): string => $state->color())
                    ->formatStateUsing(fn (CancellationRequestStatus $state): string => $state->label()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('order.field.status'))
                    ->options(fn (): array => collect(CancellationRequestStatus::cases())
                        ->mapWithKeys(fn (CancellationRequestStatus $s): array => [$s->value => $s->label()])
                        ->all())
                    // AN INBOX OPENS ON WHAT IS WAITING. Answered requests are one
                    // click away and out of the way until then.
                    ->default(CancellationRequestStatus::Pending->value),
            ])
            ->actions([
                self::approveAction(),
                self::rejectAction(),
            ])
            // Approving is a refund and rejecting owes somebody a sentence.
            // Neither belongs on a checkbox.
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-hand-raised')
            ->emptyStateHeading(__('order.cancellation.empty'))
            ->defaultSort('id', 'desc');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCancellationRequests::route('/'),
        ];
    }

    /**
     * THE TENANCY WALL, borrowed from `OrderResource` rather than rebuilt.
     *
     * The request carries only the order's uuid, so the scope goes through the
     * order's store — the same list of store uuids the seller's order screen
     * filters on. `whereHas` rather than a join keeps it one expression, and the
     * eager loads are declared here because strict mode throws on a lazy one
     * (CLAUDE.md) and every column below reads through `order`.
     *
     * @return Builder<CancellationRequest>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<CancellationRequest> $query */
        $query = parent::getEloquentQuery();

        return $query
            ->with(['order.currency'])
            ->whereHas('order', function (Builder $order): void {
                $order->whereIn('store_uuid', OrderResource::sellerStoreUuids());
            });
    }

    /**
     * "Onayla" — the whole order goes back.
     */
    private static function approveAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('approve')
            ->label(__('order.cancellation.approve'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('order.cancellation.approve'))
            // NOT "are you sure": what actually happens, before it happens.
            ->modalDescription(__('order.cancellation.approve_confirm'))
            ->modalSubmitActionLabel(__('order.cancellation.approve_button'))
            ->visible(fn (CancellationRequest $record): bool => $record->isOpen()
                && $record->order !== null
                && auth()->user()?->can('decideCancellation', $record->order) === true)
            ->action(function (CancellationRequest $record): void {
                app(ApproveCancellationAction::class)->run(
                    $record,
                    // THE ORDER'S OWN COLUMN, not the actor's organization: the
                    // Core port re-checks that the two match, so a mis-scoped
                    // panel query becomes a refusal rather than a refund of
                    // somebody else's sale.
                    (string) $record->order?->selling_org_uuid,
                    (int) auth()->id(),
                );

                Notification::make()
                    ->title(__('order.cancellation.approved_notice'))
                    ->body(__('order.cancellation.approved_body'))
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
            ->label(__('order.cancellation.reject'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading(__('order.cancellation.reject'))
            ->modalSubmitActionLabel(__('order.cancellation.reject_button'))
            ->form([
                Forms\Components\Textarea::make('decision_reason')
                    ->label(__('order.cancellation.decision_reason'))
                    ->helperText(__('order.cancellation.decision_reason_hint'))
                    ->required()
                    ->maxLength(500),
            ])
            ->visible(fn (CancellationRequest $record): bool => $record->isOpen()
                && $record->order !== null
                && auth()->user()?->can('decideCancellation', $record->order) === true)
            ->action(function (CancellationRequest $record, array $data): void {
                app(RejectCancellationAction::class)->run(
                    $record,
                    (int) auth()->id(),
                    (string) $data['decision_reason'],
                );

                Notification::make()
                    ->title(__('order.cancellation.rejected_notice'))
                    ->success()
                    ->send();
            });
    }
}
