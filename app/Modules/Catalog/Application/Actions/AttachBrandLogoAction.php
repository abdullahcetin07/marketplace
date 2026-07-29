<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\Models\Brand;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Sets a brand's logo (§2.2, §6).
 *
 * A LOGO IS SINGULAR, unlike a product's gallery — so this REPLACES rather than
 * appends. `Brand::logoUrl()` reads the first image in the collection, so an
 * appended second file would upload successfully, change nothing visible, and
 * leave the old logo as the live one. Clearing first is what makes "upload a new
 * logo" mean what the operator thinks it means.
 *
 * NO TRANSACTION, for the same reason as `AttachProductMediaAction`: the write
 * is a FILE. A rollback cannot un-write an object, and holding a database
 * transaction across disk or S3 I/O only holds locks for the length of an
 * upload.
 *
 * TWO SHAPES OF FILE, and the difference is a live 500 if it is got wrong — the
 * same one the product media action documents. A `string` is a path RELATIVE TO
 * A DISK, because the upload component already staged the bytes; handed to
 * `addMedia()` it would be read as an absolute filesystem path, resolve against
 * the working directory, find nothing and throw `FileDoesNotExist`. So a staged
 * path travels with the disk it was staged on and goes through
 * `addMediaFromDisk()`, which is a real cross-disk copy — the staging disk is
 * not the `images` collection's public one.
 *
 * The disk, the MIME allow-list and the size cap are not re-decided here: they
 * are the `images` collection's configuration in the shared `HasMedia` trait,
 * settled once for the platform.
 */
final class AttachBrandLogoAction extends BaseAction
{
    protected bool $useTransaction = false;

    /**
     * @param  mixed  ...$arguments  Brand, UploadedFile|string $file,
     *                               ?string $disk — the disk a string path is
     *                               relative to. Ignored for an UploadedFile.
     */
    public function handle(mixed ...$arguments): Media
    {
        /** @var Brand $brand */
        $brand = $arguments[0];
        /** @var UploadedFile|string $file */
        $file = $arguments[1];
        /** @var string|null $disk */
        $disk = $arguments[2] ?? null;

        // Replace, do not append — see the class docblock.
        $brand->clearMediaCollection('images');

        return $file instanceof UploadedFile
            // PRESERVED: the caller still owns the temporary upload — Livewire
            // re-renders its own preview from it and prunes its temp directory
            // itself.
            ? $brand->addMedia($file)
                ->preservingOriginal()
                ->toMediaCollection('images')
            // NOT preserved: the staged copy exists only to be moved here, and
            // preserving it would leave an orphan per upload on a disk nothing
            // ever sweeps.
            : $brand->addMediaFromDisk($file, $disk ?? (string) config('filesystems.default'))
                ->toMediaCollection('images');
    }
}
