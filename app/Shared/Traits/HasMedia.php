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

        $this->addMediaConversion('preview')
            ->performOnCollections('images')
            ->fit(Fit::Contain, 480, 480)
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

        if ($media === null) {
            return null;
        }

        return $media->hasGeneratedConversion($conversion)
            ? $media->getUrl($conversion)
            : $media->getUrl();
    }

    /**
     * Gallery payload for the Next.js frontend.
     *
     * @return array<int, array<string, mixed>>
     */
    public function imageGallery(): array
    {
        return $this->getMedia('images')
            ->map(static fn (Media $media): array => [
                'uuid' => $media->uuid,
                'name' => $media->name,
                'alt' => $media->getCustomProperty('alt'),
                'order' => $media->order_column,
                'thumb' => $media->getUrl('thumb'),
                'preview' => $media->getUrl('preview'),
                'large' => $media->getUrl('large'),
            ])
            ->values()
            ->all();
    }

    public function hasImages(): bool
    {
        return $this->getMedia('images')->isNotEmpty();
    }
}
