<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers\Api\Storefront;

use App\Core\Presentation\Controllers\BaseController;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the built Meta catalogue file. It builds nothing.
 *
 * **NO ACCESS TOKEN, UNLIKE THE GOOGLE ROUTE.** Meta's scheduled fetch is
 * configured with a plain URL in Commerce Manager and there is nothing secret
 * in the file — the same titles, prices and availability the storefront shows
 * anybody. A token here would be one more string to keep in sync with a panel
 * nobody looks at twice a year.
 *
 * **NOT BUILT YET IS A 404, NOT AN EMPTY FEED.** Handing Meta a valid but empty
 * catalogue is how every dynamic ad stops matching overnight; a fetch failure is
 * a problem somebody investigates.
 *
 * @see App\Modules\Catalog\Presentation\Commands\BuildMetaCatalogFeedCommand
 */
final class MetaCatalogFeedController extends BaseController
{
    public function show(): BinaryFileResponse
    {
        $disk = Storage::disk('public');
        $path = (string) config('feed.meta.path', 'feeds/meta-catalog.xml');

        if (! $disk->exists($path)) {
            throw new NotFoundHttpException;
        }

        return response()->file($disk->path($path), [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
