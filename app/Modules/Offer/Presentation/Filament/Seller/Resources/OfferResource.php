<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Filament\Seller\Resources;

use App\Core\Domain\Contracts\CatalogBrowseContract;
use App\Core\Domain\Contracts\InventoryQueryContract;
use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Core\Domain\Contracts\StoreQueryContract;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Offer\Application\Actions\PauseOfferAction;
use App\Modules\Offer\Application\Actions\ResumeOfferAction;
use App\Modules\Offer\Application\Actions\WithdrawOfferAction;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Offer\Presentation\Filament\Seller\Resources\OfferResource\Pages;
use App\Modules\Offer\Presentation\Support\BuyBoxStanding;
use App\Modules\Offer\Presentation\Support\CatalogLabels;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "Tekliflerim" — the seller's own listings.
 *
 * MEMBERSHIP-SCOPED (ADR-030): the query is confined to the organizations the
 * acting user actively belongs to, so one seller never sees another's offers.
 * That is the wall; `OfferPolicy` then re-checks the capability per row, so a
 * Viewer in the same company can read the roster and change nothing.
 *
 * THE PRODUCT IS NOT EDITABLE HERE, and that is the module boundary made
 * visible. An offer prices a catalog variant it does not own (ADR-037): a
 * seller changes their price and their stock, and to sell something else they
 * make a different offer. There is no product field on the edit form at all.
 *
 * IT IMPORTS NO MODULE. Titles come from `CatalogBrowseContract` through
 * `CatalogLabels`, tenancy from `OrganizationAuthorizationContract`, the
 * storefront from `StoreQueryContract`. Every write delegates to the module
 * Actions, so the events and the audit trail match the API's exactly.
 *
 * @see App\Modules\Offer\Presentation\Filament\Resources\OfferResource the admin twin
 * @see docs/modules/Offer.md §4
 */
final class OfferResource extends Resource
{
    protected static ?string $model = Offer::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function getNavigationGroup(): string
    {
        return __('nav.offers');
    }

    public static function getModelLabel(): string
    {
        return __('offer.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('offer.plural');
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | The listing is open to any seller reaching the panel — the query below is
    | the tenancy wall, and `OfferPolicy` owns every per-record decision. Same
    | reasoning as the seller Organization and Store resources.
    */

    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', Offer::class) === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update', $record) === true;
    }

    /**
     * An offer is WITHDRAWN, never deleted: a future order line references the
     * offer it was bought from.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Price and stock. Nothing else.
     *
     * The variant and the store are create-only — chosen once, on the "select
     * from the catalog" page, and never editable afterwards. Re-pointing an
     * offer at a different product would silently move a listing (and any
     * history attached to it) to something a buyer did not look at.
     */
    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Placeholder::make('product')
                ->label(__('offer.field.product'))
                ->content(fn (?Offer $record): string => $record === null
                    ? '—'
                    : app(CatalogLabels::class)->productTitle($record->product_uuid)),

            Forms\Components\Placeholder::make('variant')
                ->label(__('offer.field.variant'))
                ->content(fn (?Offer $record): string => $record === null
                    ? '—'
                    : app(CatalogLabels::class)->variantLabel($record->variant_uuid)),

            /*
            | MAJOR UNITS AT THE BOUNDARY, minor units everywhere behind it. A
            | seller types 129,90; the page converts through the Currency model
            | before the DTO is built (non-negotiable #6). This is the only
            | place in the module that knows about decimal money.
            */
            Forms\Components\TextInput::make('price')
                ->label(__('offer.field.price'))
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->step(0.01)
                ->helperText(__('offer.field.price_hint')),

            Forms\Components\TextInput::make('list_price')
                ->label(__('offer.field.list_price'))
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->helperText(__('offer.field.list_price_hint')),

            Forms\Components\TextInput::make('stock_quantity')
                ->label(__('offer.field.stock'))
                ->numeric()
                ->required()
                ->minValue(0)
                ->step(1)
                /*
                | **WHAT YOU HAVE, BEFORE YOU OVERWRITE IT.** The mirror is
                | ABSOLUTE (ADR-048): typing 5 here sets Inventory's `on_hand` to
                | 5 — it does not add 5. A seller who sold three of five and comes
                | here to "top up" would otherwise be silently restoring stock
                | they no longer have, and the first they would hear of it is an
                | order they cannot fill.
                |
                | ON CREATE THERE IS NO RECORD YET, so it falls back to the plain
                | hint rather than claiming a live figure for a variant nobody has
                | stocked.
                */
                ->helperText(function (?Offer $record): string {
                    if ($record === null) {
                        return __('offer.field.stock_hint');
                    }

                    return __('offer.field.stock_hint_live', [
                        'available' => app(InventoryQueryContract::class)
                            ->availableFor($record->variant_uuid, $record->selling_org_uuid),
                    ]);
                }),

            Forms\Components\Textarea::make('reason')
                ->label(__('offer.field.reason'))
                ->helperText(__('offer.field.reason_hint'))
                ->maxLength(500)
                ->columnSpanFull(),
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

                Tables\Columns\TextColumn::make('price_minor')
                    ->label(__('offer.field.price'))
                    ->state(fn (Offer $record): string => money($record->price_minor, $record->currency))
                    ->sortable(),

                /*
                | **THE NUMBER THAT IS ACTUALLY TRUE, AND IT IS NOT THE ONE BESIDE
                | IT.** `stock_quantity` is what the seller TYPED; it never
                | decrements, because selling moves Inventory's `on_hand` and
                | nothing writes back (ADR-048). So an offer that sold all five
                | units read "Stok 5" here while the buy box had already dropped
                | it for `available = 0` — the seller's own screen disagreeing
                | with the shop, and only the shop being right.
                |
                | COMPUTED PER ROW THROUGH THE CORE PORT, never joined: Inventory
                | is a different bounded context and `available = on_hand −
                | reserved` is computed on read by design (ADR-048), so there is
                | no column to eager-load. The same shape `CatalogLabels` uses on
                | the row above.
                |
                | NOT SORTABLE, for the reason the Inventory page states: sorting
                | would mean computing it for the whole result set and ordering in
                | PHP.
                */
                Tables\Columns\TextColumn::make('available')
                    ->label(__('offer.field.available'))
                    ->badge()
                    ->getStateUsing(fn (Offer $record): int => app(InventoryQueryContract::class)
                        ->availableFor($record->variant_uuid, $record->selling_org_uuid))
                    ->formatStateUsing(fn (int $state): string => $state === 0
                        ? __('offer.stock.sold_out')
                        : (string) $state)
                    ->color(fn (int $state): string => match (true) {
                        // DANGER at zero, where the old column said "warning":
                        // this one is not a preference the seller expressed, it
                        // is a sale they are no longer making.
                        $state === 0 => 'danger',
                        $state <= 5 => 'warning',
                        default => 'success',
                    }),

                /*
                | KEPT, RELABELLED, AND MUTED. Removing it would hide the thing
                | the seller can actually change — the live number is read-only
                | and this is the input behind it — and seeing both is what makes
                | the gap legible: "I said 5, nothing is sellable, so restock".
                | The colour signal moved to the column above; this one is a fact
                | about a form field, not a warning.
                */
                Tables\Columns\TextColumn::make('stock_quantity')
                    ->label(__('offer.field.declared'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('offer.field.status'))
                    ->badge()
                    ->color(fn (OfferStatus $state): string => $state->color())
                    ->formatStateUsing(fn (OfferStatus $state): string => __("enums.OfferStatus.{$state->value}")),

                /*
                | THE TWO NUMBERS A SELLER OPENS THIS PAGE FOR: am I winning,
                | and what do I have to beat? Both COMPUTED on every read
                | (ADR-045) — storing a rank would be the cache-coherency
                | problem that decision exists to avoid, and it would go stale
                | the moment any competitor re-priced.
                |
                | Not sortable, deliberately. Sorting by rank would mean
                | computing it for every row in the result set and ordering in
                | PHP, which is a different and much more expensive query than
                | the one this page runs.
                */
                Tables\Columns\TextColumn::make('buy_box_rank')
                    ->label(__('offer.field.buy_box_rank'))
                    ->badge()
                    ->color(fn (Offer $record): string => self::standing()->rank($record) === 1 ? 'success' : 'gray')
                    ->state(function (Offer $record): string {
                        $rank = static::standing()->rank($record);

                        // "—" for an offer that is not competing at all.
                        // "You are 7th" and "you are not in the running" are
                        // different facts; showing the first for the second
                        // tells a seller to cut their price when what they
                        // actually need is to restock.
                        if ($rank === null) {
                            return '—';
                        }

                        return trans_choice('offer.buy_box.rank_of', 1, [
                            'rank' => $rank,
                            'total' => static::standing()->competitorCount($record->variant_uuid),
                        ]);
                    }),

                Tables\Columns\TextColumn::make('buy_box_price')
                    ->label(__('offer.field.buy_box_price'))
                    ->state(function (Offer $record): string {
                        $winning = static::standing()->winningPriceMinor($record->variant_uuid);

                        return $winning === null
                            ? '—'
                            : money($winning, $record->currency);
                    })
                    ->description(fn (Offer $record): ?string => self::standing()->rank($record) === 1
                        ? __('offer.buy_box.you_are_winning')
                        : null),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('offer.field.listed_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                Tables\Actions\EditAction::make(),
                self::pauseAction(),
                self::resumeAction(),
                self::withdrawAction(),
            ])
            // Pausing four listings is four decisions, each with its own audit
            // reason. Nothing here belongs on a checkbox.
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-tag')
            ->emptyStateHeading(__('offer.empty.heading'))
            ->emptyStateDescription(__('offer.empty.description'))
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()->label(__('offer.action.create')),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * The storefronts this actor may list under, `uuid => name`.
     *
     * THE SELLER PICKS A STORE, NOT A COMPANY, and the selling organization is
     * derived from it. Both are required on an offer (§3.4), but a store is the
     * thing a seller recognises — and asking for the company as well would mean
     * two pickers whose valid combinations are a subset of their cross product.
     *
     * Scoped twice: to their active memberships, then to the organizations
     * where they hold the management capability. A Viewer sees an empty picker
     * rather than a form that fails on submit, and a company with no live store
     * is told "open a store first" by absence rather than by a refusal after
     * the whole form is filled. The policy and the action remain the controls.
     *
     * @return array<string, string>
     */
    public static function sellableStores(): array
    {
        $userId = (int) auth()->id();
        $authz = app(OrganizationAuthorizationContract::class);
        $stores = app(StoreQueryContract::class);

        $options = [];

        foreach ($authz->organizationIdsForUser($userId) as $organizationId) {
            if (! $authz->canManageOrganization($userId, $organizationId)) {
                continue;
            }

            $options += $stores->liveStoresForOrganization($organizationId);
        }

        return $options;
    }

    /**
     * THE TENANCY WALL (ADR-030). Currency is eager loaded because every row
     * renders money and strict mode makes a lazy load throw.
     *
     * @return Builder<Offer>
     */
    public static function getEloquentQuery(): Builder
    {
        $ids = app(OrganizationAuthorizationContract::class)
            ->organizationIdsForUser((int) auth()->id());

        /** @var Builder<Offer> $query */
        $query = parent::getEloquentQuery();

        return $query->with('currency')->whereIn('selling_org_id', $ids);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOffers::route('/'),
            'create' => Pages\CreateOffer::route('/create'),
            'edit' => Pages\EditOffer::route('/{record}/edit'),
        ];
    }

    /**
     * Resolved here so the create page and the browse step share one instance
     * and one memo.
     */
    public static function catalog(): CatalogBrowseContract
    {
        return app(CatalogBrowseContract::class);
    }

    /**
     * The platform's default currency — the only one an offer is priced in this
     * sprint (§13.1), and what the major/minor conversion is done against.
     */
    public static function defaultCurrencyId(): int
    {
        return (int) app(CurrencyRepositoryContract::class)->default()->getKey();
    }

    private static function pauseAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('pause')
            ->label(__('offer.action.pause'))
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(__('offer.action.pause_confirm'))
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('offer.field.reason'))
                    ->maxLength(500),
            ])
            ->visible(fn (Offer $record): bool => $record->status === OfferStatus::Active
                && auth()->user()?->can('pause', $record) === true)
            ->action(function (Offer $record, array $data): void {
                app(PauseOfferAction::class)->run($record, static::reason($data));

                Notification::make()->title(__('offer.notice.paused'))->success()->send();
            });
    }

    private static function resumeAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('resume')
            ->label(__('offer.action.resume'))
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->requiresConfirmation()
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('offer.field.reason'))
                    ->maxLength(500),
            ])
            ->visible(fn (Offer $record): bool => $record->status === OfferStatus::Paused
                && auth()->user()?->can('resume', $record) === true)
            ->action(function (Offer $record, array $data): void {
                app(ResumeOfferAction::class)->run($record, static::reason($data));

                Notification::make()->title(__('offer.notice.resumed'))->success()->send();
            });
    }

    private static function withdrawAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('withdraw')
            ->label(__('offer.action.withdraw'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('offer.action.withdraw_confirm'))
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('offer.field.reason'))
                    ->maxLength(500),
            ])
            ->visible(fn (Offer $record): bool => $record->status->canSellerTransitionTo(OfferStatus::Withdrawn)
                && auth()->user()?->can('withdraw', $record) === true)
            ->action(function (Offer $record, array $data): void {
                app(WithdrawOfferAction::class)->run($record, static::reason($data));

                Notification::make()->title(__('offer.notice.withdrawn'))->success()->send();
            });
    }

    /**
     * The buy-box reader, resolved once so its per-variant memo is shared by
     * every column and every row of one render.
     *
     * Resolved from the container rather than held in a static: a static would
     * outlive the request in a long-running worker and start answering with
     * yesterday's prices.
     */
    private static function standing(): BuyBoxStanding
    {
        return app(BuyBoxStanding::class);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function reason(array $data): ?string
    {
        $reason = $data['reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
