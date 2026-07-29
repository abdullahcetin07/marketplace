<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources\CategoryResource\Pages;

use App\Modules\Catalog\Application\Actions\CreateCategoryAction;
use App\Modules\Catalog\Domain\DTOs\CreateCategoryDTO;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Presentation\Filament\Resources\CategoryResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Creation goes through the ACTION, never Filament's default model create.
 *
 * The action owns the two-step path write (the path contains the node's own id,
 * which does not exist until insert) and dispatches `CategoryCreated`. Letting
 * the page save the model directly would produce a node whose `path` does not
 * locate it, and no event at all.
 */
final class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        // isset() is already false for a null value, so it is the whole test.
        $parent = isset($data['parent_id'])
            ? Category::query()->find($data['parent_id'])
            : null;

        return app(CreateCategoryAction::class)->run(new CreateCategoryDTO(
            name: [
                'tr' => (string) $data['name_tr'],
                'en' => $data['name_en'] ?? null,
            ],
            parentUuid: $parent?->uuid,
            slug: $data['slug'] ?? null,
            isActive: (bool) ($data['is_active'] ?? true),
            acceptsProducts: (bool) ($data['accepts_products'] ?? false),
        ));
    }
}
