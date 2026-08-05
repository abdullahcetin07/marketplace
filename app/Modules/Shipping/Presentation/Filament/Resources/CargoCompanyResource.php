<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Presentation\Filament\Resources;

use App\Modules\Shipping\Domain\Models\CargoCompany;
use App\Modules\Shipping\Presentation\Filament\Resources\CargoCompanyResource\Pages;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The carrier list an operator maintains (ADR-063, Shipping.md §5).
 *
 * THE WHOLE REASON CARRIERS ARE A TABLE. A new contract with a carrier, or a
 * carrier changing its tracking URL — both are afternoon problems for operations
 * and neither should need a release. This screen is what makes that true.
 *
 * THE CODE IS EDITABLE ONCE, ON CREATE, AND FROZEN AFTER. It is the seeder's
 * idempotency key (the `TaxRate.code` precedent): editing it later would make the
 * next deploy re-create the carrier as a duplicate, leaving two rows where every
 * existing shipment points at the old one.
 *
 * NO DELETE. A shipment names its carrier and the FK restricts; withdrawal is
 * `is_active = false`, which keeps a parcel's history readable years later.
 *
 * @see docs/modules/Shipping.md §5
 */
final class CargoCompanyResource extends Resource
{
    protected static ?string $model = CargoCompany::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 70;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return __('nav.shipping');
    }

    public static function getModelLabel(): string
    {
        return __('shipping.cargo.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('shipping.cargo.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', CargoCompany::class) === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', CargoCompany::class) === true;
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
            Forms\Components\TextInput::make('code')
                ->label(__('shipping.cargo.code'))
                ->helperText(__('shipping.cargo.code_hint'))
                ->required()
                ->maxLength(40)
                ->alphaDash()
                ->unique(ignoreRecord: true)
                // FROZEN AFTER CREATION — see the class docblock. Editing it would
                // make the seeder create a duplicate on the next deploy.
                ->disabled(fn (?CargoCompany $record): bool => $record !== null)
                ->dehydrated(fn (?CargoCompany $record): bool => $record === null),

            Forms\Components\TextInput::make('name')
                ->label(__('shipping.cargo.name'))
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('tracking_url_template')
                ->label(__('shipping.cargo.tracking_url_template'))
                ->helperText(__('shipping.cargo.tracking_url_hint'))
                ->url()
                ->maxLength(500)
                /*
                | A TEMPLATE WITHOUT THE TOKEN IS A LINK TO THE CARRIER'S HOME PAGE
                | for every parcel — technically a valid URL and completely
                | useless, which is exactly the kind of mistake a form should
                | catch rather than a buyer.
                */
                ->rule('regex:/'.preg_quote(CargoCompany::TRACKING_TOKEN, '/').'/')
                ->validationMessages([
                    'regex' => __('shipping.cargo.tracking_url_hint'),
                ]),

            Forms\Components\TextInput::make('sort_order')
                ->label(__('shipping.cargo.sort_order'))
                ->numeric()
                ->default(0),

            Forms\Components\Toggle::make('is_active')
                ->label(__('shipping.cargo.is_active'))
                ->default(true),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('shipping.cargo.name'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('code')
                    ->label(__('shipping.cargo.code'))
                    ->badge()
                    ->color('gray'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('shipping.cargo.is_active'))
                    ->boolean(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label(__('shipping.cargo.sort_order'))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('shipping.cargo.is_active')),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([])
            ->defaultSort('sort_order');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCargoCompanies::route('/'),
            'create' => Pages\CreateCargoCompany::route('/create'),
            'edit' => Pages\EditCargoCompany::route('/{record}/edit'),
        ];
    }
}
