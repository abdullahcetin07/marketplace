<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources;

use App\Modules\Catalog\Application\Actions\CreateAttributeAction;
use App\Modules\Catalog\Domain\DTOs\CreateAttributeDTO;
use App\Modules\Catalog\Domain\Enums\AttributeType;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Presentation\Filament\Resources\AttributeResource\Pages;
use App\Modules\Catalog\Presentation\Filament\Resources\AttributeResource\RelationManagers;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * Attribute definitions, ADMIN panel only (ADR-038).
 *
 * `code` is immutable after creation and the form says so: it is the handle a
 * search facet, an importer or a later migration keys on, and re-pointing those
 * is not something a rename should quietly do. Labels are freely editable, which
 * is the whole reason the two are separate fields.
 *
 * Authorised by CategoryPolicy — one permission covers the whole taxonomy,
 * because categories, attributes, values and brands are one editorial job.
 */
final class AttributeResource extends Resource
{
    protected static ?string $model = Attribute::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationGroup(): string
    {
        return __('nav.catalogue');
    }

    public static function getModelLabel(): string
    {
        return __('catalog.attribute.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('catalog.attribute.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', \App\Modules\Catalog\Domain\Models\Category::class) === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', \App\Modules\Catalog\Domain\Models\Category::class) === true;
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('code')
                    ->label(__('catalog.attribute.code'))
                    ->helperText(__('catalog.attribute.code_hint'))
                    ->required()
                    ->maxLength(255)
                    ->rule('alpha_dash')
                    // Immutable: facets and imports key on it.
                    ->disabledOn('edit'),

                Forms\Components\Select::make('type')
                    ->label(__('catalog.attribute.type'))
                    ->options(AttributeType::options())
                    ->default(AttributeType::Select->value)
                    ->required()
                    ->native(false)
                    ->live()
                    ->disabledOn('edit'),

                Forms\Components\TextInput::make('name_tr')
                    ->label(__('catalog.attribute.name').' (TR)')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('name_en')
                    ->label(__('catalog.attribute.name').' (EN)')
                    ->maxLength(255),

                Forms\Components\Toggle::make('is_variant_defining')
                    ->label(__('catalog.attribute.is_variant_defining'))
                    ->helperText(__('catalog.attribute.is_variant_defining_hint'))
                    // Only an enumerable type can be an axis (ADR-039). The
                    // action refuses it anyway; disabling the toggle means a
                    // Category Manager is never offered the impossible choice.
                    ->disabled(fn (Forms\Get $get): bool => AttributeType::tryFrom((string) $get('type'))?->canDefineVariants() !== true)
                    ->default(false),

                Forms\Components\Toggle::make('is_filterable')
                    ->label(__('catalog.attribute.is_filterable'))
                    ->default(true),

                Forms\Components\Toggle::make('is_active')
                    ->label(__('catalog.attribute.is_active'))
                    ->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label(__('catalog.attribute.code'))
                    ->searchable(),

                Tables\Columns\TextColumn::make('name_tr')
                    ->label(__('catalog.attribute.name'))
                    ->searchable(['name_tr', 'name_en'])
                    ->formatStateUsing(fn (Attribute $record): string => $record->localized('name')),

                Tables\Columns\TextColumn::make('type')
                    ->label(__('catalog.attribute.type'))
                    ->badge()
                    ->formatStateUsing(fn (Attribute $record): string => $record->type->label()),

                Tables\Columns\IconColumn::make('is_variant_defining')
                    ->label(__('catalog.attribute.is_variant_defining'))
                    ->boolean(),

                Tables\Columns\TextColumn::make('values_count')
                    ->label(__('catalog.attribute.values_count'))
                    ->counts('values'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('catalog.attribute.is_active'))
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->options(AttributeType::options()),
                Tables\Filters\TernaryFilter::make('is_variant_defining')
                    ->label(__('catalog.attribute.is_variant_defining')),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-tag')
            ->emptyStateHeading(__('catalog.attribute.empty.heading'))
            ->emptyStateDescription(__('catalog.attribute.empty.description'))
            ->defaultSort('position');
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\ValuesRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAttributes::route('/'),
            'create' => Pages\CreateAttribute::route('/create'),
            'edit' => Pages\EditAttribute::route('/{record}/edit'),
        ];
    }

    /**
     * Creation goes through the action so the ADR-039 type rule is enforced in
     * one place, whatever the form allowed.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createFromForm(array $data): Attribute
    {
        try {
            return app(CreateAttributeAction::class)->run(new CreateAttributeDTO(
                code: (string) $data['code'],
                name: ['tr' => (string) $data['name_tr'], 'en' => $data['name_en'] ?? null],
                type: AttributeType::from((string) $data['type']),
                isVariantDefining: (bool) ($data['is_variant_defining'] ?? false),
                isFilterable: (bool) ($data['is_filterable'] ?? true),
                isActive: (bool) ($data['is_active'] ?? true),
            ));
        } catch (CatalogException $exception) {
            throw ValidationException::withMessages([
                'data.is_variant_defining' => $exception->getMessage(),
            ]);
        }
    }
}
