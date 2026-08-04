<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources;

use App\Modules\Catalog\Application\Actions\AttachBrandLogoAction;
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

            /*
            | The list has always RENDERED a logo; nothing could ever set one.
            |
            | A form field rather than the header action the product gallery
            | uses, because a logo is one file that belongs to the brand's
            | identity — an operator creating "Beko" expects to give it its mark
            | in the same breath, not to save and then find a second button.
            |
            | `storeFiles(false)` is deliberate: the component would otherwise
            | persist the upload itself and hand the page a path, when the
            | action's contract is the UploadedFile that the media library moves
            | to the public disk. Livewire's temporary upload already IS one, so
            | letting Filament store it first would write the logo to a second,
            | wrong location and leave it there.
            |
            | The 2 MB cap is the HTTP-layer one; `HasMedia::maxUploadSize()` is
            | the storage-layer backstop behind it. A logo that needs more than
            | 2 MB is a photograph filed as a mark.
            */
            Forms\Components\FileUpload::make('logo')
                ->label(__('catalog.brand.logo'))
                ->helperText(__('catalog.brand.logo_hint'))
                ->image()
                ->storeFiles(false)
                ->maxSize(2048)
                ->imagePreviewHeight('80'),
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
     * @param array<string, mixed> $data
     */
    public static function createFromForm(array $data): Brand
    {
        $brand = app(CreateBrandAction::class)->run(new CreateBrandDTO(
            name: (string) $data['name'],
            isActive: (bool) ($data['is_active'] ?? true),
        ));

        self::attachLogo($brand, $data);

        return $brand;
    }

    /**
     * Move an uploaded logo onto the brand, if one was supplied.
     *
     * Shared by create and edit because both forms carry the same field, and
     * because the two ways a file can arrive — a Livewire `UploadedFile` or a
     * staged path — are the action's problem, not each page's.
     *
     * @param array<string, mixed> $data
     */
    public static function attachLogo(Brand $brand, array $data): void
    {
        $logo = $data['logo'] ?? null;

        // Filament hands a multiple-less FileUpload either the value or a
        // one-element array, depending on how the state was hydrated.
        if (is_array($logo)) {
            $logo = reset($logo) ?: null;
        }

        if ($logo === null || $logo === '') {
            return;
        }

        app(AttachBrandLogoAction::class)->run(
            $brand,
            $logo,
            (string) config('filament.default_filesystem_disk'),
        );
    }
}
