<?php

declare(strict_types=1);

namespace App\Modules\Media\Application\Jobs;

use App\Core\Application\Jobs\BaseJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Remove files from object storage after their database record is gone.
 *
 * WHY DELETION IS ASYNCHRONOUS: one media record can be a dozen objects on S3
 * — the original plus every conversion and responsive variant. Doing that
 * inline means a user clicking "delete" waits on a dozen network round-trips,
 * and a single S3 timeout leaves the record deleted but the files orphaned.
 *
 * Takes PATHS, not a model. By the time this runs the record is already gone,
 * which is the correct order: a file that outlives its record is a storage
 * bill, whereas a record pointing at a missing file is a broken image on a
 * customer's screen.
 *
 * @see docs/media.md
 */
final class DeleteMediaJob extends BaseJob
{
    /**
     * @param  array<int, string>  $paths
     */
    public function __construct(
        private readonly array $paths,
        private readonly string $disk,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $storage = Storage::disk($this->disk);
        $failed = [];

        foreach ($this->paths as $path) {
            try {
                // Not an error if it is already gone — a retry of this job
                // must not fail on the objects the first attempt removed.
                if ($storage->exists($path)) {
                    $storage->delete($path);
                }
            } catch (\Throwable $e) {
                $failed[] = $path;
                report($e);
            }
        }

        if ($failed !== []) {
            // Orphaned objects cost money silently. Surfacing them is the only
            // way a cleanup sweep ever gets written.
            Log::channel('errors')->warning('Media files could not be deleted', [
                'disk' => $this->disk,
                'paths' => array_slice($failed, 0, 20),
                'failed_count' => count($failed),
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [...parent::tags(), 'disk:'.$this->disk];
    }

    protected function queueName(): string
    {
        return 'media';
    }
}
