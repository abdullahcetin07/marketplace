<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers\Api\Storefront;

use App\Core\Presentation\Controllers\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the pre-built Google Merchant Center feed.
 *
 * **IT BUILDS NOTHING.** Twenty thousand items assembled inside a request would
 * time out, and it would time out against Google's fetcher, which records the
 * failure against the Merchant Center account. `feed:build-google-merchant`
 * writes the file nightly; this hands it over.
 *
 * **PUBLIC, BECAUSE EVERY FIELD IN IT ALREADY IS.** Title, price, image and
 * availability are on the storefront for anyone to read. The optional
 * `feed.google.access_token` therefore guards crawl budget and tidiness, not
 * secrecy — and when it is empty the route is simply open, which is the honest
 * default rather than security theatre.
 *
 * **A MISSING TOKEN 404s RATHER THAN 403s.** A 403 confirms the URL is right and
 * invites guessing; a 404 says the same thing the absence of the file would say.
 *
 * @see App\Modules\Catalog\Application\Services\GoogleMerchantFeed
 */
final class GoogleMerchantFeedController extends BaseController
{
    public function show(Request $request): BinaryFileResponse
    {
        $token = trim((string) config('feed.google.access_token', ''));

        if ($token !== '' && ! hash_equals($token, (string) $request->query('key', ''))) {
            throw new NotFoundHttpException;
        }

        $disk = Storage::disk('public');
        $path = (string) config('feed.google.path', 'feeds/google-merchant.xml');

        // NOT BUILT YET IS A 404, NOT AN EMPTY FEED. Handing Google a valid but
        // empty `<channel>` is how an entire catalogue gets marked as withdrawn.
        if (! $disk->exists($path)) {
            throw new NotFoundHttpException;
        }

        return response()->file($disk->path($path), [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
