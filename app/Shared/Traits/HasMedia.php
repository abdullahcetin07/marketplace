<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Platform media conventions layered on top of spatie/laravel-medialibrary.
 *
 * WHY WRAP IT: every model that stores files must agree on the disk, the
 * conversion sizes, the accepted MIME types and the size cap. Encoding those
 * once here means a new module cannot accidentally write originals to the
 * local disk or ship 12 MB hero images to the storefront.
 *
 * A model using this trait must implement Spatie's HasMedia *interface*:
 *
 *     class Product extends Model implements \Spatie\MediaLibrary\HasMedia
 *     {
 *         use \App\Shared\Traits\HasMedia;
 *     }
 *
 * The interface and this trait share a short name; import the interface with
 * its FQCN to keep the distinction obvious at the top of each model.
 *
 * @see docs/media.md
 */
trait HasMedia
{
    use InteractsWithMedia;

    /**
     * Largest upload accepted, in bytes. Enforced again by the HTTP request
     * rules — this is the storage-layer backstop, not the only check.
     */
    public static function maxUploadSize(): int
    {
        return 10 * 1024 * 1024; // 10 MB
    }

    /**
     * @return array<int, string>
     */
    public static function acceptedImageMimeTypes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];
    }

    /**
     * @return array<int, string>
     */
    public static function acceptedDocumentMimeTypes(): array
    {
        return ['application/pdf'];
    }

    /**
     * Two collections cover every current need:
     *   images    — ordered gallery, publicly readable
     *   documents — private (invoices, tax certificates, ID scans)
     *
     * Modules add their own in registerMediaCollections() by calling
     * parent::registerMediaCollections() first.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useDisk(config('marketplace.media.public_disk'))
            ->acceptsMimeTypes(self::acceptedImageMimeTypes())
            ->withResponsiveImages();

        $this->addMediaCollection('documents')
            ->useDisk(config('marketplace.media.private_disk'))
            ->acceptsMimeTypes([
                ...self::acceptedImageMimeTypes(),
                ...self::acceptedDocumentMimeTypes(),
            ])
            ->singleFile();
    }

    /**
     * Conversions are queued (the default) so an upload request never blocks
     * on image processing. Horizon's `media` queue handles them.
     *
     * CHAIN ORDER IS DELIBERATE: `performOnCollections()` and `nonOptimized()`
     * are declared on Spatie's `Conversion`, but `fit()` and `format()` reach it
     * through `Conversion::__call`, which static analysis resolves to the image
     * DRIVER rather than back to the Conversion. Everything after them in a
     * chain therefore reads as a call on the wrong object. Putting the really
     * declared methods first keeps the chain analysable end to end. These are
     * independent setters on one object, so the order has no runtime meaning —
     * only this one.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->performOnCollections('images')
            ->nonOptimized()
            ->fit(Fit::Contain, 160, 160)
            ->format('webp');

        // ~620px wide for the on-page product image (portrait products land at
        // 620×930; landscape caps at 620 wide). The old 480 box left a 320px-wide
        // portrait upscaling — and blurry — in a ~600–700px gallery on wide screens.
        $this->addMediaConversion('preview')
            ->performOnCollections('images')
            ->fit(Fit::Contain, 620, 930)
            ->format('webp');

        $this->addMediaConversion('large')
            ->performOnCollections('images')
            ->fit(Fit::Contain, 1200, 1200)
            ->format('webp');
    }

    /**
     * URL of the first image, or null. Never throws on an empty collection.
     */
    public function imageUrl(string $conversion = 'preview'): ?string
    {
        $media = $this->getFirstMedia('images');

        return $media === null ? null : $this->urlFor($media, $conversion);
    }

    /**
     * Every image in the collection, in order — the same fallback rule as
     * `imageUrl()`, applied per image.
     *
     * FOR THE GALLERY ON A PUBLIC PAGE. Asking a media item for a conversion URL
     * returns the path the conversion WOULD live at, generated or not: Spatie
     * builds it by convention rather than by checking the disk. So a payload
     * assembled without this check hands the browser a 404 for every image whose
     * conversion has not been processed yet — which, on a queue that has fallen
     * behind or stopped, is every image uploaded since.
     *
     * @return array<int, string>
     */
    public function imageUrls(string $conversion = 'preview'): array
    {
        return $this->getMedia('images')
            ->map(fn (Media $media): string => $this->urlFor($media, $conversion))
            ->values()
            ->all();
    }

    /**
     * Gallery payload for the Next.js frontend.
     *
     * @return array<int, array<string, mixed>>
     */
    public function imageGallery(): array
    {
        return $this->getMedia('images')
            ->map(fn (Media $media): array => [
                'uuid' => $media->uuid,
                'name' => $media->name,
                'alt' => $media->getCustomProperty('alt'),
                'order' => $media->order_column,
                'thumb' => $this->urlFor($media, 'thumb'),
                'preview' => $this->urlFor($media, 'preview'),
                'large' => $this->urlFor($media, 'large'),
            ])
            ->values()
            ->all();
    }

    public function hasImages(): bool
    {
        return $this->getMedia('images')->isNotEmpty();
    }

    /**
     * The conversion's URL if it has actually been generated, otherwise the
     * original's.
     *
     * A FULL-SIZE PHONE PHOTO IS THE FALLBACK, and that is the trade: the page is
     * slower for as long as the queue is behind, and it WORKS. The alternative —
     * a correct-looking URL nobody can fetch — reads to a shopper as a product
     * with no picture, which is the one thing a listing cannot survive.
     */
    private function urlFor(Media $media, string $conversion): string
    {
        $url = $media->hasGeneratedConversion($conversion)
            ? $media->getUrl($conversion)
            : $media->getUrl();

        return $this->cacheBusted($media, $url);
    }

    /**
     * Append a `?v=` version token so a REGENERATED conversion is a brand-new URL
     * to any CDN in front of the origin.
     *
     * WHY: Spatie names a conversion file by convention (…-preview.webp) and never
     * changes it, so re-running `media-library:regenerate` overwrites the bytes at
     * the SAME path. A CDN that cached the old bytes under a long max-age keeps
     * serving them — the origin is fixed and the visitor is not. The token is the
     * media row's `updated_at`, which regenerate bumps, so the URL changes exactly
     * when the image changes and stays stable (fully cacheable) the rest of the
     * time. This is correct caching, not cache defeat.
     */
    private function cacheBusted(Media $media, string $url): string
    {
        $version = $media->updated_at?->getTimestamp() ?? 0;
        $separator = str_contains($url, '?') ? '&' : '?';

        return "{$url}{$separator}v={$version}";
    }
}
