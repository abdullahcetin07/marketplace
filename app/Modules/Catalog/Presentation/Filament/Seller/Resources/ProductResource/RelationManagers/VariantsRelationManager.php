<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource\RelationManagers;

use App\Modules\Catalog\Application\Actions\GenerateVariantsAction;
use App\Modules\Catalog\Application\Actions\UpsertVariantAction;
use App\Modules\Catalog\Domain\Contracts\AttributeRepositoryContract;
use App\Modules\Catalog\Domain\DTOs\GenerateVariantsDTO;
use App\Modules\Catalog\Domain\DTOs\UpsertVariantDTO;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\AttributeValue;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The product's SKUs, and the "generate variants" surface (ADR-039, §13.4).
 *
 * TWO WAYS IN, one storage model. "Generate" multiplies the seller's chosen
 * axis values out into every combination; "add one" writes a single row. Both go
 * through actions that produce identical explicit variants, so the convenience
 * path and the manual path cannot drift.
 *
 * THE AXIS PICKER IS BUILT FROM THE CATEGORY'S SCHEMA, not from every attribute
 * that exists: only attributes the product's category marks variant-defining are
 * offered, because only those are axes here (§2.3). A category with none says so
 * plainly and the seller generates the single default variant.
 *
 * NO PRICE AND NO STOCK COLUMN on this table. The variant is what an Offer will
 * reference; what it costs and how many there are belong to Offer and Inventory
 * (ADR-037).
 */
final class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('catalog.product.variants');
    }

    public function table(Table $table): Table
    {
        return $table
            // `combinationLabel()` reads the variant's values, and strict mode
            // makes a lazy load THROW rather than quietly issue a query per row
            // (CLAUDE.md). The repositories declare this on `$with`; a relation
            // manager builds its own query, so it declares it here.
            ->modifyQueryUsing(fn ($query) => $query->with('attributeValues'))
            ->columns([
                Tables\Columns\TextColumn::make('sku')
                    ->label(__('catalog.variant.sku'))
                    ->searchable(),

                Tables\Columns\TextColumn::make('combination_key')
                    ->label(__('catalog.variant.combination'))
                    ->formatStateUsing(fn (ProductVariant $record): string => $record->combinationLabel()),

                Tables\Columns\TextColumn::make('barcode')
                    ->label(__('catalog.variant.barcode'))
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_default')
                    ->label(__('catalog.variant.is_default'))
                    ->boolean()
                    ->toggleable(),
            ])
            ->headerActions([
                $this->generateAction(),
                $this->addAction(),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    // §3.3 — a product must keep at least one variant, because
                    // that is what an Offer references.
                    ->visible(function (): bool {
                        /** @var Product $product */
                        $product = $this->getOwnerRecord();

                        return $product->variants()->count() > 1;
                    }),
            ])
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-squares-2x2')
            ->emptyStateHeading(__('catalog.variant.empty.heading'))
            ->emptyStateDescription(__('catalog.variant.empty.description'))
            ->defaultSort('position');
    }

    /**
     * Cartesian auto-generate (§13.4, ruled).
     *
     * One multi-select per axis. The seller ticks the values they stock and
     * every combination appears; re-running later adds only what is new, so
     * introducing a colour never disturbs SKUs already printed on labels.
     */
    private function generateAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('generate')
            ->label(__('catalog.product.action.generate_variants'))
            ->icon('heroicon-o-squares-plus')
            ->color('primary')
            ->form(fn (): array => $this->axisFields())
            ->action(function (array $data): void {
                /** @var Product $product */
                $product = $this->getOwnerRecord();

                $selection = [];

                foreach ($this->variantDefiningAttributes() as $attribute) {
                    $chosen = $data['axis_'.$attribute->getKey()] ?? [];

                    if ($chosen !== []) {
                        $selection[$attribute->uuid] = $this->valueUuids($attribute, (array) $chosen);
                    }
                }

                try {
                    $created = app(GenerateVariantsAction::class)->run(
                        $product,
                        new GenerateVariantsDTO(selection: $selection),
                    );
                } catch (CatalogException $exception) {
                    Notification::make()
                        ->title(__('catalog.product.notify.failed'))
                        ->body($exception->getMessage())
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('catalog.product.notify.variants_generated', ['count' => count($created)]))
                    ->success()
                    ->send();
            });
    }

    /**
     * One variant by hand — the late combination the cartesian did not cover.
     */
    private function addAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('add')
            ->label(__('catalog.product.action.add_variant'))
            ->icon('heroicon-o-plus')
            ->color('gray')
            ->form(fn (): array => [
                ...$this->axisFields(multiple: false),
                Forms\Components\TextInput::make('sku')
                    ->label(__('catalog.variant.sku'))
                    ->helperText(__('catalog.variant.sku_hint'))
                    ->maxLength(255),
                Forms\Components\TextInput::make('barcode')
                    ->label(__('catalog.variant.barcode'))
                    ->maxLength(255),
            ])
            ->action(function (array $data): void {
                /** @var Product $product */
                $product = $this->getOwnerRecord();

                $valueUuids = [];

                foreach ($this->variantDefiningAttributes() as $attribute) {
                    $chosen = $data['axis_'.$attribute->getKey()] ?? null;

                    if ($chosen !== null && $chosen !== '') {
                        $valueUuids = [...$valueUuids, ...$this->valueUuids($attribute, [$chosen])];
                    }
                }

                try {
                    app(UpsertVariantAction::class)->run($product, new UpsertVariantDTO(
                        valueUuids: $valueUuids,
                        sku: ($data['sku'] ?? '') !== '' ? (string) $data['sku'] : null,
                        barcode: ($data['barcode'] ?? '') !== '' ? (string) $data['barcode'] : null,
                    ));
                } catch (CatalogException $exception) {
                    Notification::make()
                        ->title(__('catalog.product.notify.failed'))
                        ->body($exception->getMessage())
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('catalog.product.notify.variants_generated', ['count' => 1]))
                    ->success()
                    ->send();
            });
    }

    /**
     * A field per variant axis, or a plain notice when the category defines
     * none — a form of empty selects would be a puzzle, not a form.
     *
     * @return array<int, Forms\Components\Component>
     */
    private function axisFields(bool $multiple = true): array
    {
        $attributes = $this->variantDefiningAttributes();

        if ($attributes === []) {
            return [
                Forms\Components\Placeholder::make('no_axes')
                    ->hiddenLabel()
                    ->content(__('catalog.variant.no_axes')),
            ];
        }

        $fields = [];

        foreach ($attributes as $attribute) {
            $options = $attribute->values
                ->where('is_active', true)
                ->mapWithKeys(fn (AttributeValue $value): array => [
                    $value->getKey() => $value->localized('label'),
                ])
                ->all();

            $field = Forms\Components\Select::make('axis_'.$attribute->getKey())
                ->label($attribute->localized('name'))
                ->options($options)
                ->native(false);

            $fields[] = $multiple
                ? $field->multiple()->helperText(__('catalog.variant.axes_hint'))
                : $field;
        }

        return $fields;
    }

    /**
     * The axes of the OWNER'S category (§2.3) — not every variant-defining
     * attribute in the platform.
     *
     * @return array<int, Attribute>
     */
    private function variantDefiningAttributes(): array
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        return app(AttributeRepositoryContract::class)
            ->variantDefiningFor($product->category)
            ->all();
    }

    /**
     * Translate the picker's internal ids back into the uuids the DTO speaks —
     * actions take public identifiers, never internal ids (non-negotiable #7).
     *
     * @param  array<int, mixed>  $valueIds
     * @return array<int, string>
     */
    private function valueUuids(Attribute $attribute, array $valueIds): array
    {
        $ids = array_map('intval', $valueIds);

        return $attribute->values
            ->whereIn('id', $ids)
            ->pluck('uuid')
            ->map(static fn (mixed $uuid): string => (string) $uuid)
            ->values()
            ->all();
    }
}
