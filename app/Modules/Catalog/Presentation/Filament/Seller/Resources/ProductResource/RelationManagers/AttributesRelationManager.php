<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource\RelationManagers;

use App\Modules\Catalog\Application\Actions\SetProductAttributesAction;
use App\Modules\Catalog\Domain\Contracts\AttributeRepositoryContract;
use App\Modules\Catalog\Domain\DTOs\ProductAttributeValueDTO;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\AttributeValue;
use App\Modules\Catalog\Domain\Models\Product;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The product's DESCRIPTIVE attributes (§2.4).
 *
 * THE FORM IS BUILT FROM THE CATEGORY'S SCHEMA, one field per bound attribute,
 * typed the way the attribute is typed: a select for enumerated values, a text
 * or number input otherwise. That is the point of the taxonomy carrying types at
 * all — a seller filling in "Malzeme" gets the platform's list of materials, not
 * a free-text box that produces "pamuk", "Pamuk " and "PAMUK".
 *
 * VARIANT AXES ARE EXCLUDED. "Size M" belongs to the variant, not the product,
 * and the action refuses it anyway — so offering the field would be offering a
 * choice that will be rejected.
 *
 * REQUIRED ATTRIBUTES ARE MARKED BUT NOT ENFORCED HERE (§3.2): the check runs at
 * publish, so a seller can save a half-filled product and come back to it. The
 * asterisk is a nudge, not a gate.
 *
 * SAVED AS A WHOLE SET, because "set the attributes" is what a form submit
 * means — a merge would make clearing a value impossible through the UI.
 */
final class AttributesRelationManager extends RelationManager
{
    protected static string $relationship = 'attributes';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('catalog.product.attributes');
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

                Tables\Columns\TextColumn::make('pivot.value')
                    ->label(__('catalog.attribute.value'))
                    ->getStateUsing(fn (Attribute $record): string => $this->displayValue($record))
                    ->placeholder('—'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('set')
                    ->label(__('catalog.product.attributes'))
                    ->icon('heroicon-o-pencil-square')
                    ->fillForm(fn (): array => $this->currentValues())
                    ->form(fn (): array => $this->schemaFields())
                    ->action(fn (array $data) => $this->save($data)),
            ])
            ->bulkActions([])
            ->paginated(false);
    }

    /**
     * One field per bound, non-axis attribute, typed by the attribute.
     *
     * @return array<int, Forms\Components\Component>
     */
    private function schemaFields(): array
    {
        $fields = [];

        foreach ($this->descriptiveAttributes() as $attribute) {
            $key = 'attr_'.$attribute->getKey();
            $required = $this->isRequired($attribute);

            $field = $attribute->type->usesPredefinedValues()
                ? Forms\Components\Select::make($key)
                    ->options($attribute->values
                        ->where('is_active', true)
                        ->mapWithKeys(fn (AttributeValue $v): array => [$v->getKey() => $v->localized('label')])
                        ->all())
                    ->native(false)
                    ->searchable()
                : Forms\Components\TextInput::make($key);

            $fields[] = $field
                ->label($attribute->localized('name').($required ? ' *' : ''))
                // Marked, not enforced: §3.2 checks required attributes at
                // publish so authoring can be incremental.
                ->helperText($required ? __('catalog.attribute.is_required_hint') : null);
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(array $data): void
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        $assignments = [];

        foreach ($this->descriptiveAttributes() as $attribute) {
            $raw = $data['attr_'.$attribute->getKey()] ?? null;

            if ($raw === null || $raw === '') {
                continue;
            }

            $assignments[] = $attribute->type->usesPredefinedValues()
                ? new ProductAttributeValueDTO(
                    attributeUuid: $attribute->uuid,
                    valueUuid: (string) $attribute->values->firstWhere('id', (int) $raw)?->uuid,
                )
                : new ProductAttributeValueDTO(
                    attributeUuid: $attribute->uuid,
                    value: (string) $raw,
                );
        }

        try {
            app(SetProductAttributesAction::class)->run($product, $assignments);
        } catch (CatalogException $exception) {
            Notification::make()
                ->title(__('catalog.product.notify.failed'))
                ->body($exception->getMessage())
                ->warning()
                ->send();

            return;
        }

        Notification::make()->title(__('catalog.product.notify.updated'))->success()->send();
    }

    /**
     * The form's starting state — what the product already has.
     *
     * @return array<string, mixed>
     */
    private function currentValues(): array
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        $state = [];

        foreach ($product->attributes()->get() as $attribute) {
            $pivot = $attribute->getRelation('pivot');

            $state['attr_'.$attribute->getKey()] = $attribute->type->usesPredefinedValues()
                ? $pivot->getAttribute('attribute_value_id')
                : $pivot->getAttribute('value');
        }

        return $state;
    }

    /**
     * Bound to the category, minus the variant axes (§2.4).
     *
     * @return array<int, Attribute>
     */
    private function descriptiveAttributes(): array
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        $repository = app(AttributeRepositoryContract::class);
        $axes = $repository->variantDefiningFor($product->category)->pluck('id')->all();

        return $repository->schemaFor($product->category)
            ->reject(static fn (Attribute $attribute): bool => in_array($attribute->getKey(), $axes, true))
            ->values()
            ->all();
    }

    private function isRequired(Attribute $attribute): bool
    {
        return (bool) $attribute->getRelation('pivot')->getAttribute('is_required');
    }

    /**
     * The human-readable value: the chosen option's label for a select, the raw
     * string otherwise.
     */
    private function displayValue(Attribute $attribute): string
    {
        $pivot = $attribute->getRelation('pivot');
        $valueId = $pivot->getAttribute('attribute_value_id');

        if ($valueId !== null) {
            return (string) $attribute->values->firstWhere('id', (int) $valueId)?->localized('label');
        }

        return (string) $pivot->getAttribute('value');
    }
}
