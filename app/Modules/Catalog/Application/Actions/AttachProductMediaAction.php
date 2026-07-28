<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Adds gallery images to a product (§6).
 *
 * NO TRANSACTION. Everything else in this module wraps its work in one; this
 * deliberately does not, because the write is a FILE, not a row. Holding a
 * database transaction open across disk or S3 I/O buys nothing — a rollback
 * cannot un-write the object — and it holds locks for the duration of an upload.
 *
 * Catalog imagery goes to the PUBLIC disk: it is meant to be seen. That is
 * already the `images` collection's configuration in the shared HasMedia trait,
 * so this action does not re-decide it — one place, or a module eventually
 * writes product photographs to the private disk and nobody notices until the
 * storefront 403s.
 *
 * Conversions are queued, so this returns as soon as the originals are stored.
 *
 * TWO SHAPES OF FILE, and the difference is not cosmetic:
 *
 *   UploadedFile  — the request object, still in the caller's hands.
 *   string        — a path RELATIVE TO A DISK, because an upload component
 *                   already staged the bytes somewhere.
 *
 * A relative path handed to `addMedia()` is read as an absolute filesystem
 * path, so it resolves against the working directory, finds nothing, and throws
 * FileDoesNotExist. That is a live 500 the tests missed for a while: they only
 * ever passed the object. A staged path must therefore travel with the disk it
 * was staged on — `$disk` — and go through `addMediaFromDisk()`, which is a
 * genuine cross-disk copy (the staging disk is not the `images` collection's
 * public one).
 */
final class AttachProductMediaAction extends BaseAction
{
    protected bool $useTransaction = false;

    /**
     * @param  mixed  ...$arguments  Product, array<UploadedFile|string> $files,
     *                               ?string $disk — the disk the string paths
     *                               are relative to. Ignored for UploadedFile.
     *
     * @return array<int, Media>
     */
    public function handle(mixed ...$arguments): array
    {
        /** @var Product $product */
        $product = $arguments[0];
        /** @var array<int, UploadedFile|string> $files */
        $files = $arguments[1];
        /** @var string|null $disk */
        $disk = $arguments[2] ?? null;

        $attached = [];

        foreach ($files as $file) {
            $attached[] = $file instanceof UploadedFile
                // PRESERVED: the caller still owns the temporary upload — a
                // Livewire component re-renders its own preview from it, and
                // Livewire prunes its temp directory itself.
                ? $product->addMedia($file)
                    ->preservingOriginal()
                    ->toMediaCollection('images')
                // NOT preserved, equally deliberately: the staged copy exists
                // only to be moved here, and Spatie deletes it from the staging
                // disk once the collection has the bytes. Preserving it would
                // leave an orphan per upload on a disk nothing ever sweeps.
                : $product->addMediaFromDisk($file, $disk ?? (string) config('filesystems.default'))
                    ->toMediaCollection('images');
        }

        return $attached;
    }
}
