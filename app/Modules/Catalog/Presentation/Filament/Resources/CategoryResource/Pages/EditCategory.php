<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources\CategoryResource\Pages;

use App\Modules\Catalog\Application\Actions\UpdateCategoryAction;
use App\Modules\Catalog\Domain\DTOs\UpdateCategoryDTO;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Presentation\Filament\Resources\CategoryResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Edits go through the ACTION, which owns the subtree path rewrite when the
 * parent changes and refuses a node becoming its own ancestor.
 *
 * The parent picker already excludes descendants, but the posted id is client
 * input — so a rejected move surfaces as a validation error on the field rather
 * than an error page, because the field is the thing that is wrong.
 */
final class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Category $record */
        $parent = isset($data['parent_id'])
            ? Category::query()->find($data['parent_id'])
            : null;

        try {
            return app(UpdateCategoryAction::class)->run($record, new UpdateCategoryDTO(
                name: [
                    'tr' => (string) $data['name_tr'],
                    'en' => $data['name_en'] ?? null,
                ],
                parentUuid: $parent?->uuid,
                slug: $data['slug'] ?? null,
                isActive: (bool) ($data['is_active'] ?? true),
                acceptsProducts: (bool) ($data['accepts_products'] ?? false),
                present: ['parentUuid', 'slug', 'isActive', 'acceptsProducts'],
            ));
        } catch (CatalogException $exception) {
            /*
            | Surface the refusal on the FIELD that caused it. Two rules can
            | reach here — a move that would make a node its own ancestor, and
            | closing a category that still holds products — and pinning both to
            | `parent_id` would tell a Category Manager to look at the wrong
            | control.
            */
            $field = ($exception->getContext()['reason'] ?? null) === 'category_still_has_products'
                ? 'data.accepts_products'
                : 'data.parent_id';

            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
