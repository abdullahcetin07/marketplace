<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Filament\Seller\Resources\OfferResource\Pages;

use App\Core\Domain\Contracts\CatalogBrowseContract;
use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Core\Domain\Contracts\StoreQueryContract;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Offer\Presentation\Filament\Seller\Resources\OfferResource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * "Katalogdan seç & sat" — the seller's path from the shared catalog to a live
 * listing (§4).
 *
 * THE PRODUCT IS FOUND, NEVER TYPED. A seller searches the published catalog,
 * picks a product, picks a variant, and sets price and stock. That sequence is
 * the whole point of ADR-037: they attach an offer to the canonical entry rather
 * than creating their own copy of it. Authoring a product that does not exist
 * yet is a different flow in a different module ("ürün aç", Catalog §5) and
 * stays there.
 *
 * THE SEARCH GOES THROUGH `CatalogBrowseContract`. Offer imports no module, so
 * the picker asks Catalog through the Core port — which is also why the variant
 * list repopulates from the contract when the product changes rather than from a
 * relation.
 *
 * MONEY IS CONVERTED HERE AND NOWHERE ELSE. The seller types 129,90; this page
 * multiplies by the currency's factor and hands the action integer minor units
 * (non-negotiable #6). Keeping the conversion at the boundary is what stops
 * decimal money leaking into the domain.
 *
 * EVERY PRECONDITION IS STILL RE-CHECKED BY THE ACTION. The pickers narrow the
 * choice — published products only, stores this seller may use — but a forged
 * payload is refused by `CreateOfferAction`, not by the form.
 */
final class CreateOffer extends CreateRecord
{
    protected static string $resource = OfferResource::class;

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('offer.create.what'))
                ->description(__('offer.create.what_hint'))
                ->schema([
                    Forms\Components\Select::make('product_uuid')
                        ->label(__('offer.field.product'))
                        ->required()
                        ->searchable()
                        ->native(false)
                        // Server-side search over the published catalog. The
                        // seller types; Catalog answers.
                        ->getSearchResultsUsing(fn (string $search): array => static::productOptions($search))
                        ->getOptionLabelUsing(fn (?string $value): ?string => $value === null
                            ? null
                            : (static::productOptions('', $value)[$value] ?? null))
                        ->helperText(__('offer.field.product_hint'))
                        // Changing the product invalidates the variant beneath
                        // it — clearing it is safer than leaving a SKU that
                        // belongs to something else.
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set): mixed => $set('variant_uuid', null)),

                    Forms\Components\Select::make('variant_uuid')
                        ->label(__('offer.field.variant'))
                        ->required()
                        ->native(false)
                        ->options(fn (Forms\Get $get): array => static::variantOptions($get('product_uuid')))
                        ->helperText(__('offer.field.variant_hint')),

                    Forms\Components\Select::make('store_uuid')
                        ->label(__('offer.field.store'))
                        ->required()
                        ->native(false)
                        ->options(fn (): array => OfferResource::sellableStores())
                        ->helperText(__('offer.field.store_hint')),
                ]),

            Forms\Components\Section::make(__('offer.create.terms'))
                ->schema([
                    Forms\Components\TextInput::make('price')
                        ->label(__('offer.field.price'))
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->step(0.01)
                        ->helperText(__('offer.field.price_hint')),

                    Forms\Components\TextInput::make('list_price')
                        ->label(__('offer.field.list_price'))
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->helperText(__('offer.field.list_price_hint')),

                    Forms\Components\TextInput::make('stock_quantity')
                        ->label(__('offer.field.stock'))
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->default(1)
                        ->step(1)
                        ->helperText(__('offer.field.stock_hint')),
                ])->columns(3),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $storeUuid = (string) $data['store_uuid'];

        /*
        | THE COMPANY IS DERIVED FROM THE CHOSEN STORE, never taken from the
        | payload. A store the seller may use belongs to a company they manage,
        | so deriving leaves nothing for a forged organization id to do — and
        | the ADR-040 pair is resolved from the two Core contracts rather than
        | trusted from a form.
        */
        $organizationId = (int) app(StoreQueryContract::class)->organizationIdFor($storeUuid);
        $organizationUuid = (string) app(OrganizationAuthorizationContract::class)
            ->organizationUuidFor($organizationId);

        $currency = app(CurrencyRepositoryContract::class)->default();

        return app(CreateOfferAction::class)->run(new CreateOfferDTO(
            variantUuid: (string) $data['variant_uuid'],
            sellingOrgId: $organizationId,
            sellingOrgUuid: $organizationUuid,
            storeUuid: $storeUuid,
            priceMinor: $currency->toMinor((string) $data['price']),
            stockQuantity: (int) $data['stock_quantity'],
            listPriceMinor: static::optionalMinor($data['list_price'] ?? null),
            currencyId: (int) $currency->getKey(),
        ));
    }

    /**
     * Published products matching a search, `uuid => "Title — Brand"`.
     *
     * @return array<string, string>
     */
    private static function productOptions(string $search, ?string $only = null): array
    {
        if ($only !== null) {
            $summary = app(CatalogBrowseContract::class)->productSummaries([$only])[$only] ?? null;

            return $summary === null ? [] : [$only => static::productLabel($summary)];
        }

        $result = app(CatalogBrowseContract::class)->searchPublishedProducts($search, perPage: 25);

        $options = [];

        foreach ($result['items'] as $item) {
            $options[$item['uuid']] = static::productLabel($item);
        }

        return $options;
    }

    /**
     * @param  array{title: string, brand: string|null}  $summary
     */
    private static function productLabel(array $summary): string
    {
        return $summary['brand'] === null
            ? $summary['title']
            : $summary['title'].' — '.$summary['brand'];
    }

    /**
     * @return array<string, string>
     */
    private static function variantOptions(mixed $productUuid): array
    {
        if (! is_string($productUuid) || $productUuid === '') {
            return [];
        }

        $options = [];

        foreach (app(CatalogBrowseContract::class)->variantsForProduct($productUuid) as $variant) {
            $options[$variant['uuid']] = $variant['label'].' · '.$variant['sku'];
        }

        return $options;
    }

    private static function optionalMinor(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return app(CurrencyRepositoryContract::class)->default()->toMinor((string) $value);
    }
}
