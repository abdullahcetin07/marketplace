<?php

declare(strict_types=1);

namespace App\Modules\Media\Application\Services;

use App\Modules\Media\Application\Jobs\DeleteMediaJob;
use App\Modules\Media\Application\Jobs\OptimizeImageJob;
use App\Modules\Media\Domain\Exceptions\MediaRejected;
use App\Shared\Enums\MediaType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Upload, validation and removal for stored files.
 *
 * WHY A SERVICE OVER spatie/laravel-medialibrary: the library handles storage
 * and conversions well but validates nothing. Left to itself, every module
 * would re-decide what MIME types it accepts and how large a file may be, and
 * the answers would drift. This is the single gate.
 *
 * VALIDATION IS ON THE REAL MIME TYPE, not the extension and not the
 * browser-supplied Content-Type. Both are attacker-controlled; a `.jpg` that
 * is actually a PHP script is the oldest upload exploit there is.
 *
 * Sprint 1 ships this infrastructure with no product media attached to it —
 * @see docs/media.md.
 */
final class MediaService
{
    /**
     * Attach a file to a model.
     *
     * @param  HasMedia&Model  $model
     *
     * @throws MediaRejected
     */
    public function attach(
        Model $model,
        UploadedFile $file,
        string $collection = 'images',
        ?string $name = null,
    ): Media {
        $type = $this->validate($file);

        $media = $model->addMedia($file)
            ->usingName($name ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            // Sanitised: the original filename reaches a URL and a
            // Content-Disposition header, both of which are injection surfaces.
            ->sanitizingFileName(fn (string $fileName): string => $this->sanitiseFileName($fileName))
            ->withCustomProperties(['media_type' => $type->value])
            ->toMediaCollection($collection);

        if ($type->isOptimisable()) {
            // Queued: the user's upload request must not wait on re-encoding.
            OptimizeImageJob::dispatch($media->getKey());
        }

        return $media;
    }

    /**
     * Validate an upload against the platform's allow-list and size ceiling.
     *
     * @throws MediaRejected
     */
    public function validate(UploadedFile $file): MediaType
    {
        // getMimeType() inspects the file contents (finfo); getClientMimeType()
        // trusts the browser. Only the first is safe.
        $mimeType = (string) $file->getMimeType();
        $type = MediaType::fromMimeType($mimeType);

        if (! in_array($mimeType, $type->acceptedMimeTypes(), true)) {
            throw MediaRejected::unsupportedType($mimeType);
        }

        $configuredMax = settings()->integer('media.max_upload_mb', 10) * 1024 * 1024;
        $max = min($type->maxSize(), $configuredMax);

        if ($file->getSize() > $max) {
            throw MediaRejected::tooLarge($file->getSize(), $max);
        }

        return $type;
    }

    /**
     * Delete a media record and queue removal of its files.
     *
     * ORDER MATTERS: the record goes first, the objects follow asynchronously.
     * A file outliving its record is a storage bill; a record pointing at a
     * missing file is a broken image on a customer's screen.
     */
    public function detach(Media $media): void
    {
        $paths = $this->pathsFor($media);
        $disk = $media->disk;

        $media->delete();

        if ($paths !== []) {
            DeleteMediaJob::dispatch($paths, $disk);
        }
    }

    /**
     * A short-lived signed URL for a private file.
     *
     * Private media is never publicly addressable — the bucket is separate
     * from the public one precisely so a policy mistake cannot expose identity
     * documents. @see docs/media.md
     */
    public function temporaryUrl(Media $media, ?int $seconds = null): string
    {
        $seconds ??= (int) config('marketplace.media.signed_url_ttl', 300);

        return $media->getTemporaryUrl(now()->addSeconds($seconds));
    }

    /**
     * Every object belonging to a media record: the original, its conversions
     * and its responsive variants.
     *
     * @return array<int, string>
     */
    private function pathsFor(Media $media): array
    {
        $directory = $media->getKey();

        return [
            "{$directory}/{$media->file_name}",
            "{$directory}/conversions",
            "{$directory}/responsive-images",
        ];
    }

    /**
     * Strip everything that could be interpreted as a path or as markup.
     *
     * Turkish filenames are common here, so the transliteration is explicit
     * rather than relying on a locale-sensitive helper.
     */
    private function sanitiseFileName(string $fileName): string
    {
        $replacements = [
            'ı' => 'i', 'İ' => 'I', 'ş' => 's', 'Ş' => 'S', 'ğ' => 'g', 'Ğ' => 'G',
            'ü' => 'u', 'Ü' => 'U', 'ö' => 'o', 'Ö' => 'O', 'ç' => 'c', 'Ç' => 'C',
        ];

        $fileName = strtr($fileName, $replacements);
        $fileName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $fileName) ?? 'file';

        // Collapse leading dots so a name cannot become a hidden file or
        // traverse upward.
        return ltrim(trim($fileName, '-'), '.') ?: 'file';
    }
}
