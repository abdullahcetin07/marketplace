<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource\Pages;

use App\Modules\Catalog\Application\Actions\AttachProductMediaAction;
use App\Modules\Catalog\Application\Actions\UpdateProductAction;
use App\Modules\Catalog\Domain\DTOs\UpdateProductDTO;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Edit the draft's content, upload images, and (through the relation manager)
 * build its variants.
 *
 * The lifecycle decides whether this page is reachable at all —
 * `ProductResource::canEdit()` defers to ProductPolicy, which allows it only in
 * a seller-editable state (§3.1). Submitting is a deliberate separate step from
 * the list.
 */
final class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Images are a header ACTION, not a form field (§6).
     *
     * `filament/spatie-laravel-media-library-plugin` is not a dependency here,
     * and pulling one in to render an upload box would be a package for a
     * widget. Going through `AttachProductMediaAction` instead means the media
     * write travels the same path as every other write in the module — and the
     * disk, MIME allow-list and size cap stay in `App\Shared\Traits\HasMedia`,
     * decided once for the platform.
     *
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('upload_images')
                ->label(__('catalog.product.images'))
                ->icon('heroicon-o-photo')
                ->form([
                    FileUpload::make('files')
                        ->hiddenLabel()
                        ->multiple()
                        ->image()
                        // STATED, not inherited. The component stages the upload
                        // on a disk and hands the action a path relative to it;
                        // if the two ever named different disks the action would
                        // look for the bytes where they are not. Same expression,
                        // both sides — see uploadDisk().
                        ->disk($this->uploadDisk())
                        // The HTTP-layer cap; HasMedia::maxUploadSize() is the
                        // storage-layer backstop behind it.
                        ->maxSize(10240)
                        ->maxFiles(10)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    /** @var Product $product */
                    $product = $this->getRecord();

                    app(AttachProductMediaAction::class)->run($product, (array) $data['files'], $this->uploadDisk());

                    Notification::make()->title(__('catalog.product.notify.updated'))->success()->send();
                }),
        ];
    }

    protected function getSavedNotificationTitle(): string
    {
        return __('catalog.product.notify.updated');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Product $record */
        $category = Category::query()->findOrFail($data['category_id']);
        $brand = isset($data['brand_id'])
            ? Brand::query()->find($data['brand_id'])
            : null;

        try {
            return app(UpdateProductAction::class)->run($record, new UpdateProductDTO(
                title: [
                    'tr' => (string) $data['title_tr'],
                    'en' => $data['title_en'] ?? null,
                ],
                description: [
                    'tr' => $data['description_tr'] ?? null,
                    'en' => $data['description_en'] ?? null,
                ],
                categoryUuid: $category->uuid,
                brandUuid: $brand?->uuid,
                gtin: $data['gtin'] ?? null,
                // Every field the form actually renders is present; `slug` is
                // not on this form, so it is absent and stays untouched (§3.5).
                present: ['categoryUuid', 'brandUuid', 'gtin'],
            ));
        } catch (CatalogException $exception) {
            throw ValidationException::withMessages([
                ($exception->getContext()['reason'] ?? null) === 'gtin_taken' ? 'data.gtin' : 'data.category_id'
                    => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Where the upload component STAGES the file before the action copies it
     * into the `images` collection.
     *
     * This is not the collection's disk and is not trying to be: `HasMedia`
     * still decides that the gallery lives on the public disk. This is the
     * short-lived landing spot in between, and it is a method rather than two
     * literals so the component and the action cannot drift apart — the whole
     * bug this replaced was those two disagreeing.
     */
    private function uploadDisk(): string
    {
        return (string) config('filament.default_filesystem_disk');
    }
}
