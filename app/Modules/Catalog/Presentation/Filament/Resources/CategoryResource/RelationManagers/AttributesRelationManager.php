<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources\CategoryResource\RelationManagers;

use App\Modules\Catalog\Application\Actions\BindCategoryAttributeAction;
use App\Modules\Catalog\Domain\DTOs\BindCategoryAttributeDTO;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\Category;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * A category's ATTRIBUTE SCHEMA — the per-category binding (§2.3).
 *
 * The flags edited here belong to the binding, not to the attribute, and that
 * is the whole point: Renk is a variant axis in "Giyim" and a plain description
 * in "Mobilya", while both filter on the same shared set of colours.
 *
 * Binding goes through the action, which refuses to make a non-enumerable
 * attribute a variant axis (ADR-039) — a rule no category may override, unlike
 * `is_required`, which is genuinely per-category.
 */
final class AttributesRelationManager extends RelationManager
{
    protected static string $relationship = 'attributes';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('catalog.category.attributes');
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\Select::make('recordId')
                ->label(__('catalog.attribute.singular'))
                ->options(fn (): array => Attribute::query()
                    ->active()
                    ->orderBy('position')
                    ->get()
                    ->mapWithKeys(fn (Attribute $a): array => [$a->getKey() => $a->localized('name').' ('.$a->code.')'])
                    ->all())
                ->searchable()
                ->required()
                ->native(false),

            Forms\Components\Toggle::make('is_required')
                ->label(__('catalog.attribute.is_required'))
                ->helperText(__('catalog.attribute.is_required_hint'))
                ->default(false),

            Forms\Components\Toggle::make('is_variant_defining')
                ->label(__('catalog.attribute.is_variant_defining'))
                ->helperText(__('catalog.attribute.is_variant_defining_hint'))
                ->default(false),

            Forms\Components\Toggle::make('is_filterable')
                ->label(__('catalog.attribute.is_filterable'))
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label(__('catalog.attribute.code')),

                Tables\Columns\TextColumn::make('name_tr')
                    ->label(__('catalog.attribute.name'))
                    ->formatStateUsing(fn (Attribute $record): string => $record->localized('name')),

                Tables\Columns\TextColumn::make('type')
                    ->label(__('catalog.attribute.type'))
                    ->badge()
                    ->formatStateUsing(fn (Attribute $record): string => $record->type->label()),

                Tables\Columns\IconColumn::make('pivot.is_required')
                    ->label(__('catalog.attribute.is_required'))
                    ->boolean()
                    ->getStateUsing(fn (Attribute $record): bool => (bool) $record->getRelation('pivot')->getAttribute('is_required')),

                Tables\Columns\IconColumn::make('pivot.is_variant_defining')
                    ->label(__('catalog.attribute.is_variant_defining'))
                    ->boolean()
                    ->getStateUsing(fn (Attribute $record): bool => (bool) $record->getRelation('pivot')->getAttribute('is_variant_defining')),
            ])
            ->headerActions([
                Tables\Actions\Action::make('bind')
                    ->label(__('catalog.attribute.singular'))
                    ->icon('heroicon-o-plus')
                    ->form(fn (Forms\Form $form): Forms\Form => $this->form($form))
                    ->action(function (array $data): void {
                        /** @var Category $category */
                        $category = $this->getOwnerRecord();
                        $attribute = Attribute::query()->findOrFail($data['recordId']);

                        try {
                            app(BindCategoryAttributeAction::class)->run($category, new BindCategoryAttributeDTO(
                                attributeUuid: $attribute->uuid,
                                isRequired: (bool) ($data['is_required'] ?? false),
                                isVariantDefining: (bool) ($data['is_variant_defining'] ?? false),
                                isFilterable: (bool) ($data['is_filterable'] ?? true),
                            ));
                        } catch (CatalogException $exception) {
                            Notification::make()
                                ->title(__('catalog.product.notify.failed'))
                                ->body($exception->getMessage())
                                ->warning()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([]);
    }
}
