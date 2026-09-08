<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources\ProductModerationResource\Pages;

use App\Modules\Catalog\Application\Actions\UpdateProductAction;
use App\Modules\Catalog\Domain\DTOs\UpdateProductDTO;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Catalog\Presentation\Filament\Resources\ProductModerationResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * An admin correcting a catalogue entry (2026-09-08).
 *
 * **THIS EXISTS BECAUSE THE IMPORT STOPPED EDITING.** A supplier sheet used to
 * overwrite whatever it found, which is how 1,941 descriptions were blanked in a
 * single run; it now only inserts. So the one place a typo in a title, a wrong
 * category or a missing description gets fixed is a screen where a person sees
 * the record before changing it — this one.
 *
 * **IT DRIVES `UpdateProductAction` AND SAVES NO MODEL** — the module's standing
 * rule (ADR-074/076/088). Filament's default `handleRecordUpdate()` would
 * `fill()->save()` and fire nothing the domain listens to, leaving the row right
 * in the table and stale in search, the storefront and both feeds.
 *
 * **THE LIFECYCLE AND THE URL ARE NOT ON THIS FORM.** Status belongs to the
 * verdict actions, and the slug is left out of the DTO on purpose: the action
 * re-slugs only when `slug` is present, and a corrected title must not move an
 * address that is already indexed.
 */
final class EditProductModeration extends EditRecord
{
    protected static string $resource = ProductModerationResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Product $record */
        $categoryId = $data['category_id'] ?? null;
        $brandId = $data['brand_id'] ?? null;
        $taxRateId = $data['tax_rate_id'] ?? null;

        /*
        | THE FORM SPEAKS IDS AND THE DTO SPEAKS UUIDS, because a uuid is the
        | platform's public identifier (non-negotiable #7) and the action resolves
        | one before it writes. `present` names the three PATCH-semantics fields
        | this form actually renders — `brandUuid` is in the list precisely so
        | clearing the brand means null rather than "leave it alone" — while title
        | and description are applied by being non-empty. `slug` and `gtin` are
        | absent, which is how they stay untouched.
        */
        app(UpdateProductAction::class)->run($record, new UpdateProductDTO(
            title: array_filter([
                'tr' => $data['title_tr'] ?? null,
                'en' => $data['title_en'] ?? null,
            ], static fn (?string $value): bool => $value !== null),
            description: ['tr' => (string) ($data['description_tr'] ?? '')],
            categoryUuid: $categoryId === null ? null : Category::query()->whereKey($categoryId)->value('uuid'),
            brandUuid: $brandId === null ? null : Brand::query()->whereKey($brandId)->value('uuid'),
            taxRateUuid: $taxRateId === null ? null : TaxRate::query()->whereKey($taxRateId)->value('uuid'),
            present: ['categoryUuid', 'brandUuid', 'taxRateUuid'],
        ));

        return $record->refresh();
    }
}
