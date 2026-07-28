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
 */
final class AttachProductMediaAction extends BaseAction
{
    protected bool $useTransaction = false;

    /**
     * @return array<int, Media>
     */
    public function handle(mixed ...$arguments): array
    {
        /** @var Product $product */
        $product = $arguments[0];
        /** @var array<int, UploadedFile|string> $files */
        $files = $arguments[1];

        $attached = [];

        foreach ($files as $file) {
            $attached[] = $product->addMedia($file)
                ->preservingOriginal()
                ->toMediaCollection('images');
        }

        return $attached;
    }
}
