<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Filament\Resources;

use App\Modules\Payment\Domain\Models\CommissionRule;
use App\Modules\Payment\Presentation\Filament\Resources\CommissionRuleResource\Pages;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Commission rates, ADMIN panel only (ADR-061, Payment.md §6).
 *
 * THE ONE WRITABLE SURFACE IN THIS MODULE. A payment is a record of what a bank
 * did and nothing may edit it; a rate is configuration, and the whole reason
 * `commission_rules` is a table rather than a constant is that an operator sets
 * one without a release.
 *
 * THE SCOPES ARE ENTERED AS UUIDs, and that is a stated compromise rather than a
 * good UI. Payment imports no module, so it cannot offer a picker of sellers,
 * products, brands or categories — the pickers live in the modules that own those
 * aggregates. An admin copies a uuid from the relevant panel. Making this
 * friendlier means either a Core read port per dimension or a Presentation-layer
 * seam, and both are deliberate decisions rather than something to smuggle in
 * here; recorded as a follow-up in Payment.md.
 *
 * SPECIFICITY IS SHOWN, NOT SET. The column is computed from how many scopes a
 * row fills, so an operator can see at a glance which rule beats which — the
 * question this table exists to answer. It is not editable because it is not a
 * field; a rule that "should win" gets a scope, not a number.
 *
 * NO DELETE. A rule that has priced real sales is evidence: deactivate it. The
 * commission itself is frozen on the order line either way (ADR-061), so deleting
 * the row would not move a settled figure — it would only remove the explanation
 * for one.
 *
 * @see docs/modules/Payment.md §6
 */
final class CommissionRuleResource extends Resource
{
    protected static ?string $model = CommissionRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-scissors';

    protected static ?int $navigationSort = 62;

    protected static ?string $recordTitleAttribute = 'label';

    public static function getNavigationGroup(): string
    {
        return __('nav.finance');
    }

    public static function getModelLabel(): string
    {
        return __('payment.commission.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payment.commission.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', CommissionRule::class) === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', CommissionRule::class) === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update', $record) === true;
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
            Forms\Components\TextInput::make('label')
                ->label(__('payment.commission.label'))
                ->helperText(__('payment.commission.label_hint'))
                ->maxLength(255),

            /*
            | ENTERED AS A PERCENTAGE, STORED AS A RATIO — the same boundary
            | conversion `TaxRateResource` does, in the same place for both
            | directions so the pair cannot drift. Asking a human for "0.15" to
            | mean 15% is how a rate eventually gets entered as 15.0000 and takes
            | fifteen times the sale.
            */
            Forms\Components\TextInput::make('rate')
                ->label(__('payment.commission.rate'))
                ->helperText(__('payment.commission.rate_hint'))
                ->numeric()
                ->required()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%')
                ->formatStateUsing(fn (?string $state): ?string => $state === null
                    ? null
                    : rtrim(rtrim(number_format(((float) $state) * 100, 2, '.', ''), '0'), '.'))
                ->dehydrateStateUsing(fn (mixed $state): string => number_format(((float) $state) / 100, 4, '.', '')),

            Forms\Components\Section::make(__('payment.commission.scopes'))
                ->description(__('payment.commission.scopes_hint'))
                ->schema([
                    // Left blank = "any". All four blank = the platform default.
                    Forms\Components\TextInput::make('seller_org_uuid')
                        ->label(__('payment.commission.seller'))
                        ->uuid(),

                    Forms\Components\TextInput::make('category_uuid')
                        ->label(__('payment.commission.category'))
                        ->helperText(__('payment.commission.category_hint'))
                        ->uuid(),

                    Forms\Components\TextInput::make('brand_uuid')
                        ->label(__('payment.commission.brand'))
                        ->uuid(),

                    Forms\Components\TextInput::make('product_uuid')
                        ->label(__('payment.commission.product'))
                        ->uuid(),
                ])->columns(2),

            Forms\Components\TextInput::make('priority')
                ->label(__('payment.commission.priority'))
                ->helperText(__('payment.commission.priority_hint'))
                ->numeric()
                ->default(0)
                ->required(),

            Forms\Components\Toggle::make('is_active')
                ->label(__('payment.commission.is_active'))
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label')
                    ->label(__('payment.commission.label'))
                    ->searchable()
                    ->description(fn (CommissionRule $record): ?string => $record->isPlatformDefault()
                        ? __('payment.commission.is_default')
                        : null),

                Tables\Columns\TextColumn::make('rate')
                    ->label(__('payment.commission.rate'))
                    ->badge()
                    ->state(fn (CommissionRule $record): string => rtrim(rtrim(
                        number_format(((float) $record->rate) * 100, 2, ',', '.'), '0',
                    ), ',').'%')
                    ->sortable(),

                /*
                | WHICH RULE BEATS WHICH, at a glance — the question an operator
                | opens this page to answer. Computed, never stored: a second
                | column saying the same thing is a second thing to disagree.
                */
                Tables\Columns\TextColumn::make('specificity')
                    ->label(__('payment.commission.specificity'))
                    ->badge()
                    ->state(fn (CommissionRule $record): int => $record->specificity())
                    ->color(fn (int $state): string => $state === 0 ? 'gray' : 'info'),

                Tables\Columns\TextColumn::make('priority')
                    ->label(__('payment.commission.priority'))
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('payment.commission.is_active'))
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('payment.commission.is_active')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([])
            // Most specific first — the order resolution reads them in.
            ->defaultSort('priority', 'desc');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommissionRules::route('/'),
            'create' => Pages\CreateCommissionRule::route('/create'),
            'edit' => Pages\EditCommissionRule::route('/{record}/edit'),
        ];
    }
}
