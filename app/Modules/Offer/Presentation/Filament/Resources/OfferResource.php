<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Filament\Resources;

use App\Core\Domain\Context\AuditContext;
use App\Models\User;
use App\Modules\Offer\Application\Actions\ReinstateOfferAction;
use App\Modules\Offer\Application\Actions\SuspendOfferAction;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Offer\Presentation\Filament\Resources\OfferResource\Pages;
use App\Modules\Offer\Presentation\Support\CatalogLabels;
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
 * Admin offer oversight — the whole of it.
 *
 * OFFERS ARE NOT MODERATED (ADR-044). Nothing here is a queue and nothing here
 * approves anything: an offer went live the moment its seller saved it, and this
 * surface exists so an abusive price can be pulled AFTERWARDS. That is the
 * counterweight the decision was accepted with.
 *
 * SO THERE IS NO EDIT FORM. An admin may suspend and reinstate; they may not
 * re-price. Editing a merchant's price is not oversight, it is trading on their
 * behalf, and the audit entry would name the wrong party — `OfferPolicy` refuses
 * it and this resource offers no way to try.
 *
 * Cross-org by construction: this is a platform power gated on `offer.*` Spatie
 * permissions, not on any organization capability. Support reads; Admin pulls.
 *
 * @see App\Modules\Offer\Presentation\Filament\Seller\Resources\OfferResource the seller twin
 * @see docs/modules/Offer.md §9
 */
final class OfferResource extends Resource
{
    protected static ?string $model = Offer::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function getNavigationGroup(): string
    {
        return __('nav.catalogue');
    }

    public static function getModelLabel(): string
    {
        return __('offer.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('offer.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Offer::class) === true;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) === true;
    }

    /**
     * A platform operator does not sell, does not edit a merchant's terms, and
     * does not delete a commercial record.
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
            Infolists\Components\Section::make(__('offer.section.listing'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('product_uuid')
                        ->label(__('offer.field.product'))
                        ->state(fn (Offer $record): string => app(CatalogLabels::class)->productTitle($record->product_uuid)),
                    Infolists\Components\TextEntry::make('variant_uuid')
                        ->label(__('offer.field.variant'))
                        ->state(fn (Offer $record): string => app(CatalogLabels::class)->variantLabel($record->variant_uuid)),
                    Infolists\Components\TextEntry::make('selling_org_uuid')
                        ->label(__('offer.field.seller'))
                        ->copyable(),
                    Infolists\Components\TextEntry::make('price_minor')
                        ->label(__('offer.field.price'))
                        ->state(fn (Offer $record): string => money($record->price_minor, $record->currency)),
                    Infolists\Components\TextEntry::make('list_price_minor')
                        ->label(__('offer.field.list_price'))
                        ->state(fn (Offer $record): ?string => $record->list_price_minor === null
                            ? null
                            : money($record->list_price_minor, $record->currency))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('stock_quantity')
                        ->label(__('offer.field.stock')),
                    Infolists\Components\TextEntry::make('status')
                        ->label(__('offer.field.status'))
                        ->badge()
                        ->color(fn (OfferStatus $state): string => $state->color())
                        ->formatStateUsing(fn (OfferStatus $state): string => __("enums.OfferStatus.{$state->value}")),
                    Infolists\Components\TextEntry::make('created_at')
                        ->label(__('offer.field.listed_at'))
                        ->dateTime(),
                ]),

            /*
            | Visible only while suspended. A cleared block on every other offer
            | would be noise; here it is the record of a decision somebody has
            | to be able to answer for.
            */
            Infolists\Components\Section::make(__('offer.section.suspension'))
                ->visible(fn (Offer $record): bool => $record->status === OfferStatus::Suspended)
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('suspended_at')
                        ->label(__('offer.field.suspended_at'))
                        ->dateTime(),
                    Infolists\Components\TextEntry::make('status_before_suspension')
                        ->label(__('offer.field.status_before'))
                        ->formatStateUsing(fn (?OfferStatus $state): string => $state === null
                            ? '—'
                            : __("enums.OfferStatus.{$state->value}")),
                    Infolists\Components\TextEntry::make('suspension_reason')
                        ->label(__('offer.field.reason'))
                        ->columnSpanFull()
                        ->placeholder('—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product_uuid')
                    ->label(__('offer.field.product'))
                    ->state(fn (Offer $record): string => app(CatalogLabels::class)->productTitle($record->product_uuid))
                    ->description(fn (Offer $record): string => app(CatalogLabels::class)->variantLabel($record->variant_uuid))
                    ->wrap(),

                Tables\Columns\TextColumn::make('selling_org_uuid')
                    ->label(__('offer.field.seller'))
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('price_minor')
                    ->label(__('offer.field.price'))
                    ->state(fn (Offer $record): string => money($record->price_minor, $record->currency))
                    ->sortable(),

                Tables\Columns\TextColumn::make('stock_quantity')
                    ->label(__('offer.field.stock'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('offer.field.status'))
                    ->badge()
                    ->color(fn (OfferStatus $state): string => $state->color())
                    ->formatStateUsing(fn (OfferStatus $state): string => __("enums.OfferStatus.{$state->value}")),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('offer.field.status'))
                    ->options(fn (): array => collect(OfferStatus::cases())
                        ->mapWithKeys(fn (OfferStatus $status): array => [
                            $status->value => __("enums.OfferStatus.{$status->value}"),
                        ])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::suspendAction(),
                self::reinstateAction(),
            ])
            // Pulling a merchant's listing is a decision with a reason attached.
            // There is no version of it that belongs on a checkbox.
            ->bulkActions([])
            ->defaultSort('id', 'desc');
    }

    /**
     * Cross-org, unlike the seller twin: an admin sees every offer. Currency is
     * eager loaded because every row renders money.
     *
     * @return Builder<Offer>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Offer> $query */
        $query = parent::getEloquentQuery();

        return $query->with('currency');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOffers::route('/'),
            'view' => Pages\ViewOffer::route('/{record}'),
        ];
    }

    private static function suspendAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('suspend')
            ->label(__('offer.action.suspend'))
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('offer.action.suspend_confirm'))
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('offer.field.reason'))
                    ->helperText(__('offer.field.suspend_reason_hint'))
                    ->required()
                    ->maxLength(500),
            ])
            ->visible(fn (Offer $record): bool => $record->status !== OfferStatus::Suspended
                && auth()->user()?->can('suspend', $record) === true)
            ->action(function (Offer $record, array $data): void {
                /** @var User $admin */
                $admin = auth()->user();

                app(SuspendOfferAction::class)->run($record, $admin, (string) $data['reason']);

                Notification::make()->title(__('offer.notice.suspended'))->success()->send();
            });
    }

    private static function reinstateAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reinstate')
            ->label(__('offer.action.reinstate'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('offer.action.reinstate_confirm'))
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('offer.field.reason'))
                    ->maxLength(500),
            ])
            ->visible(fn (Offer $record): bool => $record->status === OfferStatus::Suspended
                && auth()->user()?->can('reinstate', $record) === true)
            ->action(function (Offer $record, array $data): void {
                /** @var User $admin */
                $admin = auth()->user();
                $reason = $data['reason'] ?? null;

                AuditContext::withReasonFor(
                    is_string($reason) && $reason !== '' ? $reason : null,
                    fn () => app(ReinstateOfferAction::class)->run($record, $admin),
                );

                Notification::make()->title(__('offer.notice.reinstated'))->success()->send();
            });
    }
}
