<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources;

use App\Modules\Catalog\Application\Actions\CreateBrandAction;
use App\Modules\Catalog\Domain\DTOs\CreateBrandDTO;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Presentation\Filament\Resources\BrandResource\Pages;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Brands, ADMIN panel only (§2.2).
 *
 * Platform-owned deliberately: a seller picks a brand and never invents one.
 * Two spellings of "Samsung" split every brand filter and every brand page, and
 * merging them afterwards is manual work on live data.
 *
 * Not localized — a brand name is a proper noun.
 */
final class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static ?string $navigationIcon = 'heroicon-o-bookmark-square';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return __('nav.catalogue');
    }

    public static function getModelLabel(): string
    {
        return __('catalog.brand.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('catalog.brand.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Category::class) === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', Category::class) === true;
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label(__('catalog.brand.name'))
                ->required()
                ->maxLength(255),

            Forms\Components\Toggle::make('is_active')
                ->label(__('catalog.brand.is_active'))
                ->default(true),

        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo')
                    ->label(__('catalog.brand.logo'))
                    ->getStateUsing(fn (Brand $record): ?string => $record->logoUrl()),

                Tables\Columns\TextColumn::make('name')
                    ->label(__('catalog.brand.name'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('slug')
                    ->label(__('catalog.brand.slug'))
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('catalog.brand.is_active'))
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label(__('catalog.brand.is_active')),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-bookmark-square')
            ->emptyStateHeading(__('catalog.brand.empty.heading'))
            ->emptyStateDescription(__('catalog.brand.empty.description'))
            ->defaultSort('name');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBrands::route('/'),
            'create' => Pages\CreateBrand::route('/create'),
            'edit' => Pages\EditBrand::route('/{record}/edit'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createFromForm(array $data): Brand
    {
        return app(CreateBrandAction::class)->run(new CreateBrandDTO(
            name: (string) $data['name'],
            isActive: (bool) ($data['is_active'] ?? true),
        ));
    }
}
