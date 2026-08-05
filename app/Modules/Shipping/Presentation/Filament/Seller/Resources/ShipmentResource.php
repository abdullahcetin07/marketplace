<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Presentation\Filament\Seller\Resources;

use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Modules\Shipping\Application\Actions\MarkShippedAction;
use App\Modules\Shipping\Domain\DTOs\MarkShippedDTO;
use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Modules\Shipping\Domain\Models\CargoCompany;
use App\Modules\Shipping\Domain\Models\Shipment;
use App\Modules\Shipping\Presentation\Filament\Seller\Resources\ShipmentResource\Pages;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "Kargolarım" — what this seller has to send (ADR-063, Shipping.md §6).
 *
 * ONE LEVER, AND ONE THE SELLER CAN NEVER REACH. "Kargoya ver" is the whole
 * screen: pick a carrier, type the tracking number, done. There is no "teslim
 * edildi" button and there will not be one — the seller is paid on delivery, so a
 * seller asserting it would be asserting their own payday (ADR-064). Delivery is
 * the buyer's confirmation or the transit sweep, both S2's.
 *
 * MEMBERSHIP-SCOPED THROUGH THE ORGANIZATION UUID. A shipment carries
 * `seller_org_uuid` while `OrganizationAuthorizationContract` answers memberships
 * in internal IDS, so the actor's org ids are mapped to uuids and the query
 * filters on those — the same indirection the seller's Order screen pays through
 * `StoreQueryContract`, for the same reason: this module imports nothing.
 *
 * AN EMPTY SCOPE YIELDS NOTHING, never everyone's parcels. `whereIn` on an empty
 * array is what makes that true rather than a special case somebody forgets.
 *
 * NO CREATE, NO EDIT, NO DELETE. A shipment appears when an order is paid and
 * changes only through its one action; a mis-typed tracking number is a support
 * job, because silently overwriting it would leave the buyer with a link to
 * somebody else's parcel and no record of the swap.
 *
 * @see docs/modules/Shipping.md §6
 */
final class ShipmentResource extends Resource
{
    protected static ?string $model = Shipment::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 12;

    protected static ?string $recordTitleAttribute = 'order_number';

    public static function getNavigationGroup(): string
    {
        return __('nav.orders');
    }

    public static function getModelLabel(): string
    {
        return __('shipping.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('shipping.plural');
    }

    /**
     * Open to any seller reaching the panel — the query below is the tenancy
     * wall, and `ShipmentPolicy` owns every per-record decision.
     */
    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        // A shipment appears when an order is paid. A seller creating one would
        // be inventing an order.
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
        // Nothing here is edited as a record; the one write is the table action
        // below. This exists because Filament requires the method.
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label(__('shipping.shipment.order_number'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('shipping.shipment.status_label'))
                    ->badge()
                    ->state(fn (Shipment $record): string => $record->status->label())
                    ->color(fn (Shipment $record): string => $record->status->color()),

                Tables\Columns\TextColumn::make('cargoCompany.name')
                    ->label(__('shipping.shipment.carrier'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('tracking_number')
                    ->label(__('shipping.shipment.tracking_number'))
                    ->placeholder('—')
                    ->copyable()
                    // The link the buyer follows, built from the carrier's own
                    // template — which is why the template is a column (§5).
                    ->url(fn (Shipment $record): ?string => $record->trackingUrl(), shouldOpenInNewTab: true),

                Tables\Columns\TextColumn::make('shipped_at')
                    ->label(__('shipping.shipment.shipped_at'))
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('delivered_at')
                    ->label(__('shipping.shipment.delivered_at'))
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('shipping.shipment.status_label'))
                    ->options(fn (): array => collect(ShipmentStatus::cases())
                        ->mapWithKeys(fn (ShipmentStatus $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->actions([
                /*
                | THE SELLER'S ONE LEVER. Offered only while the parcel is still
                | theirs to hand over — `MarkShippedAction` refuses any other
                | state, and showing a button that would be refused is worse than
                | showing none.
                */
                Tables\Actions\Action::make('ship')
                    ->label(__('shipping.shipment.ship'))
                    ->icon('heroicon-o-paper-airplane')
                    ->modalDescription(__('shipping.shipment.ship_confirm'))
                    ->visible(fn (Shipment $record): bool => $record->awaitsHandover()
                        && auth()->user()?->can('ship', $record) === true)
                    ->form([
                        Forms\Components\Select::make('cargo_company_uuid')
                            ->label(__('shipping.shipment.carrier'))
                            ->required()
                            // ACTIVE ONLY, in the operator's own order. The action
                            // re-checks: a page left open while operations retires
                            // a carrier must not put a dead link on a real parcel.
                            ->options(fn (): array => CargoCompany::query()
                                ->active()
                                ->ordered()
                                ->pluck('name', 'uuid')
                                ->all()),

                        Forms\Components\TextInput::make('tracking_number')
                            ->label(__('shipping.shipment.tracking_number'))
                            ->helperText(__('shipping.shipment.tracking_number_hint'))
                            ->required()
                            ->maxLength(100),
                    ])
                    ->action(function (Shipment $record, array $data): void {
                        app(MarkShippedAction::class)->run(new MarkShippedDTO(
                            shipmentUuid: $record->uuid,
                            cargoCompanyUuid: (string) $data['cargo_company_uuid'],
                            trackingNumber: (string) $data['tracking_number'],
                        ));

                        Notification::make()
                            ->success()
                            ->title(__('shipping.shipment.shipped'))
                            ->send();
                    }),
            ])
            ->bulkActions([])
            ->emptyStateHeading(__('shipping.shipment.empty'))
            ->emptyStateDescription(__('shipping.shipment.empty_hint'))
            ->defaultSort('id', 'desc');
    }

    /**
     * The tenancy wall, plus the eager load the carrier column needs.
     *
     * `with('cargoCompany')` IS FOR THE CLOSURE, NOT FOR THE COLUMN. Filament
     * eager-loads a DOTTED column's relationship itself
     * (`InteractsWithTableQuery::applyEagerLoading()`), so `cargoCompany.name`
     * would be safe on its own. What the framework cannot see is the tracking-URL
     * closure, which reaches the same relation through a model method — and under
     * strict mode an unloaded relation there is a `LazyLoadingViolationException`
     * and a page nobody can open, not a slow query (CLAUDE.md).
     *
     * So it is declared rather than relied upon: delete the carrier-name column
     * tomorrow and the closure would be the only reader left.
     *
     * @return Builder<Shipment>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Shipment> $query */
        $query = parent::getEloquentQuery();

        return $query->with('cargoCompany')->forSellers(self::sellerOrgUuids());
    }

    /**
     * Every organization uuid the acting user is a member of.
     *
     * @return array<int, string>
     */
    public static function sellerOrgUuids(): array
    {
        $authz = app(OrganizationAuthorizationContract::class);
        $uuids = [];

        foreach ($authz->organizationIdsForUser((int) auth()->id()) as $organizationId) {
            $uuid = $authz->organizationUuidFor($organizationId);

            if ($uuid !== null) {
                $uuids[] = $uuid;
            }
        }

        return $uuids;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShipments::route('/'),
        ];
    }
}
