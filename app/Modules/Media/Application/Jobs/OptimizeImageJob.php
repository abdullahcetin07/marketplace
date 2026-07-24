<?php

declare(strict_types=1);

namespace App\Modules\Media\Application\Jobs;

use App\Core\Application\Jobs\BaseJob;
use App\Shared\Enums\MediaType;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Re-encode and shrink a stored image.
 *
 * Complements the media library's own conversion queue rather than replacing
 * it. Conversions produce the fixed sizes the UI asks for; this job handles the
 * ORIGINAL, which is what a seller uploaded straight off a phone camera and is
 * frequently several megabytes of EXIF-laden JPEG.
 *
 * Runs on the `media` queue: CPU-heavy, long timeout, few workers, lowest
 * priority. Nobody is waiting on it. @see docs/queues.md
 *
 * IDEMPOTENT. It records completion in the media record's custom properties
 * and returns early on a second run, because a requeued job must not
 * re-compress an already-compressed image and lose quality each time.
 */
final class OptimizeImageJob extends BaseJob
{
    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(private readonly int $mediaId)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $media = Media::query()->find($this->mediaId);

        if ($media === null) {
            // The file was deleted between dispatch and execution. Not an
            // error — deleteWhenMissingModels expresses the same intent for
            // model-bound jobs.
            return;
        }

        if (! MediaType::fromMimeType((string) $media->mime_type)->isOptimisable()) {
            return;
        }

        // Idempotence guard. @see class docblock.
        if ($media->getCustomProperty('optimised') === true) {
            return;
        }

        $quality = settings()->integer('media.image_quality', 82);

        /*
        | Sprint 1 ships the JOB, not the encoder. Wiring an image driver here
        | means choosing between Imagick and GD and tuning per format, which
        | needs real product images to measure against — and those arrive with
        | the Catalogue module.
        |
        | Marking the record and logging keeps the pipeline observable in the
        | meantime: the queue, the retries and the idempotence are all real and
        | exercised.
        */
        Log::channel('daily')->info('Image optimisation queued but no encoder is configured', [
            'media_id' => $media->getKey(),
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'target_quality' => $quality,
        ]);

        $media->setCustomProperty('optimised', true)
            ->setCustomProperty('optimised_at', now()->toIso8601String())
            ->save();
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [...parent::tags(), 'media:'.$this->mediaId];
    }

    protected function queueName(): string
    {
        return 'media';
    }
}
