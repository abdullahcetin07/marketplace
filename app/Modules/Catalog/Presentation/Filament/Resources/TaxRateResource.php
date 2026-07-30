<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources;

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Catalog\Presentation\Filament\Resources\TaxRateResource\Pages;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * KDV brackets, ADMIN panel only (ADR-056, Catalog.md §2.4).
 *
 * A TABLE AND NOT AN ENUM, so this resource has to exist: brackets change by
 * government decision, not by release. Turkey moved %8 → %10 and %18 → %20 in
 * July 2023 with days of notice — a platform that needed a deploy for that would
 * have collected the wrong tax in the interval.
 *
 * GATED ON THE TAXONOMY CAPABILITY (`catalog.taxonomy.manage`, via
 * `CategoryPolicy`), the same key the Category Manager owns the tree and the
 * brands with. A bracket is a classification of goods, which is exactly what that
 * role curates; giving it its own permission would mean a role that can restructure
 * the whole taxonomy but not name a tax bracket.
 *
 * NO DELETE, deliberately. A repealed bracket is deactivated: products reference
 * it, the FK restricts, and an order line snapshots its rate anyway (ADR-053), so
 * deletion would buy nothing and could orphan a product mid-review. Deactivating
 * hides it from the authoring picker while it keeps answering for goods already
 * filed under it.
 *
 * THE RATE IS EDITABLE, and that is the point of the whole table — but note what
 * editing it does NOT do: placed orders keep the rate they froze, because an order
 * is a financial record (ADR-053). A rate change applies to what is sold next.
 *
 * @see docs/modules/Catalog.md §2.4
 */
final class TaxRateResource extends Resource
{
    protected static ?string $model = TaxRate::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?int $navigationSort = 35;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return __('nav.catalogue');
    }

    public static function getModelLabel(): string
    {
        return __('catalog.tax_rate.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('catalog.tax_rate.plural');
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization — the taxonomy capability, borrowed deliberately
    |--------------------------------------------------------------------------
    |
    | Asked of `Category` rather than of `TaxRate`, exactly as `BrandResource`
    | does: `catalog.taxonomy.manage` is one capability covering everything the
    | Category Manager curates, and `CategoryPolicy` is where it is answered.
    | A separate TaxRatePolicy would be a second copy of one rule.
    */

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Category::class) === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', Category::class) === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('create', Category::class) === true;
    }

    public static function canDelete(Model $record): bool
    {
        // Deactivate, never delete. @see the class docblock.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            /*
            | The seeder's idempotency key, so it is fixed once written: renaming
            | a code would make the next deploy re-insert the bracket it no longer
            | recognises, leaving two rows for one rate.
            */
            Forms\Components\TextInput::make('code')
                ->label(__('catalog.tax_rate.code'))
                ->helperText(__('catalog.tax_rate.code_hint'))
                ->required()
                ->maxLength(40)
                ->alphaDash()
                ->unique(ignoreRecord: true)
                ->disabledOn('edit'),

            Forms\Components\TextInput::make('name')
                ->label(__('catalog.tax_rate.name'))
                ->helperText(__('catalog.tax_rate.name_hint'))
                ->required()
                ->maxLength(255),

            /*
            | ENTERED AS A PERCENTAGE, STORED AS A RATIO. An operator types 20 and
            | the column holds 0.2000 — asking a human for "0.2" to mean %20 is
            | how a bracket eventually gets entered as 20.0000 and multiplies
            | every total by twenty-one.
            |
            | The conversion is done here, at the form boundary, in the same place
            | for both directions, so the pair cannot drift. `number_format`
            | rather than plain division so what is saved is exactly the column's
            | scale.
            */
            Forms\Components\TextInput::make('rate')
                ->label(__('catalog.tax_rate.rate'))
                ->helperText(__('catalog.tax_rate.rate_hint'))
                ->numeric()
                ->required()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%')
                ->formatStateUsing(fn (?string $state): ?string => $state === null
                    ? null
                    : rtrim(rtrim(number_format(((float) $state) * 100, 2, '.', ''), '0'), '.'))
                ->dehydrateStateUsing(fn (mixed $state): string => number_format(((float) $state) / 100, 4, '.', '')),

            Forms\Components\Toggle::make('is_active')
                ->label(__('catalog.tax_rate.is_active'))
                ->helperText(__('catalog.tax_rate.is_active_hint'))
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('catalog.tax_rate.name'))
                    ->searchable()
                    ->sortable(),

                // The percentage, derived — never a second stored column that
                // could disagree with the ratio.
                Tables\Columns\TextColumn::make('rate')
                    ->label(__('catalog.tax_rate.rate'))
                    ->badge()
                    ->state(fn (TaxRate $record): string => $record->percentLabel())
                    ->sortable(),

                Tables\Columns\TextColumn::make('code')
                    ->label(__('catalog.tax_rate.code'))
                    ->toggleable(isToggledHiddenByDefault: true),

                // How much of the catalog a rate change would affect — the number
                // an operator wants before editing one.
                Tables\Columns\TextColumn::make('products_count')
                    ->label(__('catalog.tax_rate.products_count'))
                    ->counts('products'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('catalog.tax_rate.is_active'))
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('catalog.tax_rate.is_active')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('rate', 'desc');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaxRates::route('/'),
            'create' => Pages\CreateTaxRate::route('/create'),
            'edit' => Pages\EditTaxRate::route('/{record}/edit'),
        ];
    }
}
