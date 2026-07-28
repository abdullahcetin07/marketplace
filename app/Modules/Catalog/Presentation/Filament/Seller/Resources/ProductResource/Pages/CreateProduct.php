<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource\Pages;

use App\Modules\Catalog\Application\Actions\DraftProductAction;
use App\Modules\Catalog\Domain\DTOs\DraftProductDTO;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * "Ürün aç" — opens a DRAFT (§5, entry point 2).
 *
 * Nothing is submitted and nothing is published here. Creation goes through
 * DraftProductAction, which owns the leaf-category check, the GTIN dedup lookup
 * and the slug policy, and dispatches `ProductDrafted`.
 *
 * The two refusals it can raise both land on the FIELD that caused them rather
 * than as an error page: "that category cannot hold products" and "this barcode
 * is already in the catalog" are things the seller can act on, and the GTIN one
 * in particular is the whole point of ADR-037's dedup key — the seller should
 * offer the existing product instead of opening a second one.
 */
final class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function getRedirectUrl(): string
    {
        // To the edit page, not the index: a fresh draft has no variants yet and
        // cannot be submitted, so the next step is right there.
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): string
    {
        return __('catalog.product.notify.drafted');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $memberships = ProductResource::sellerOrganizations();

        /*
        | The picker is HIDDEN when the seller belongs to one company, so the
        | posted payload has no id in the common case — resolve it here rather
        | than depending on a hidden field's dehydration semantics.
        |
        | When an id IS posted it is client input, so membership is re-checked:
        | the select being scoped is a UI convenience, not a security boundary.
        | A tampered form must not file a product against someone else's company.
        */
        $organizationId = isset($data['proposed_by_org_id'])
            ? (int) $data['proposed_by_org_id']
            : (count($memberships) === 1 ? $memberships[0] : 0);

        if (! in_array($organizationId, $memberships, true)) {
            throw ValidationException::withMessages([
                'data.proposed_by_org_id' => __('errors.forbidden'),
            ]);
        }

        $category = Category::query()->findOrFail($data['category_id']);
        $brand = isset($data['brand_id'])
            ? Brand::query()->find($data['brand_id'])
            : null;

        try {
            return app(DraftProductAction::class)->run(new DraftProductDTO(
                categoryUuid: $category->uuid,
                title: [
                    'tr' => (string) $data['title_tr'],
                    'en' => $data['title_en'] ?? null,
                ],
                description: [
                    'tr' => $data['description_tr'] ?? null,
                    'en' => $data['description_en'] ?? null,
                ],
                brandUuid: $brand?->uuid,
                gtin: $data['gtin'] ?? null,
                proposedByOrgId: $organizationId,
                proposedByOrgUuid: $this->organizationUuidFor($organizationId),
                proposedByUserId: (int) auth()->id(),
            ));
        } catch (CatalogException $exception) {
            throw ValidationException::withMessages([
                $this->fieldFor($exception) => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The uuid half of the provenance pair (ADR-033).
     *
     * Read straight off the `organizations` table rather than through an
     * Organization model: Catalog imports none (ADR-040), and this is the one
     * scalar the module needs that the Core contract does not expose.
     */
    private function organizationUuidFor(int $organizationId): ?string
    {
        $uuid = \Illuminate\Support\Facades\DB::table('organizations')
            ->where('id', $organizationId)
            ->value('uuid');

        return is_string($uuid) ? $uuid : null;
    }

    /**
     * Put the message on the field the seller can actually fix.
     */
    private function fieldFor(CatalogException $exception): string
    {
        return match ($exception->getContext()['reason'] ?? null) {
            'gtin_taken' => 'data.gtin',
            'category_not_leaf' => 'data.category_id',
            default => 'data.title_tr',
        };
    }
}
