<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources\AttributeResource\RelationManagers;

use App\Modules\Catalog\Application\Actions\CreateAttributeValueAction;
use App\Modules\Catalog\Domain\DTOs\CreateAttributeValueDTO;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\AttributeValue;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The enumerated options of a `select` attribute (§2.3).
 *
 * HIDDEN ENTIRELY for other types — a Number attribute has no option list, and
 * offering an empty one invites somebody to fill it with values the validator
 * will never accept.
 *
 * `value` is the machine handle and `label_*` what a human reads, kept apart so
 * re-wording "Kırmızı" cannot silently create a second colour.
 */
final class ValuesRelationManager extends RelationManager
{
    protected static string $relationship = 'values';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('catalog.attribute.values');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        /** @var Attribute $ownerRecord */
        return $ownerRecord->type->usesPredefinedValues();
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('value')
                ->label(__('catalog.attribute.value'))
                ->helperText(__('catalog.attribute.value_hint'))
                ->required()
                ->maxLength(255)
                ->rule('alpha_dash'),

            Forms\Components\TextInput::make('label_tr')
                ->label(__('catalog.attribute.label').' (TR)')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('label_en')
                ->label(__('catalog.attribute.label').' (EN)')
                ->maxLength(255),

            Forms\Components\Toggle::make('is_active')
                ->label(__('catalog.attribute.is_active'))
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('value')->label(__('catalog.attribute.value')),

                Tables\Columns\TextColumn::make('label_tr')
                    ->label(__('catalog.attribute.label'))
                    ->formatStateUsing(fn (AttributeValue $record): string => $record->localized('label')),

                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('catalog.attribute.is_active'))
                    ->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->using(function (array $data): Model {
                        /** @var Attribute $attribute */
                        $attribute = $this->getOwnerRecord();

                        try {
                            return app(CreateAttributeValueAction::class)->run(new CreateAttributeValueDTO(
                                attributeUuid: $attribute->uuid,
                                value: (string) $data['value'],
                                label: ['tr' => (string) $data['label_tr'], 'en' => $data['label_en'] ?? null],
                                isActive: (bool) ($data['is_active'] ?? true),
                            ));
                        } catch (CatalogException $exception) {
                            Notification::make()
                                ->title(__('catalog.product.notify.failed'))
                                ->body($exception->getMessage())
                                ->warning()
                                ->send();

                            throw $exception;
                        }
                    }),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([])
            ->defaultSort('position');
    }
}
