<?php

declare(strict_types=1);

namespace App\Core\Application\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Root of every queued job.
 *
 * WHY NOT EXTEND NOTHING: Laravel's defaults are wrong for a marketplace under
 * load. Unbounded retries turn a downstream outage into a poison-pill storm; a
 * missing uniqueness key lets a double-clicked button enqueue the same work
 * twice; and an unset timeout means one wedged HTTP call ties up a worker
 * indefinitely. This class sets safe defaults for all three and every job
 * inherits them.
 *
 * Defaults: 3 tries, exponential backoff, 120 s timeout, retries abandoned
 * after 1 hour, failures logged to the `errors` channel with full context.
 *
 * @see config/horizon.php for queue-to-worker mapping
 * @see docs/queues.md
 */
abstract class BaseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Attempts before the job is marked failed.
     */
    public int $tries = 3;

    /**
     * Seconds a single attempt may run before the worker kills it.
     */
    public int $timeout = 120;

    /**
     * Unhandled exceptions tolerated before the job fails, independently of
     * $tries. Relevant for jobs that release themselves back to the queue:
     * without it, a job can bounce forever without ever counting an attempt.
     */
    public int $maxExceptions = 3;

    /**
     * Delete the job if its model no longer exists, rather than failing.
     * Almost always what you want: the record was deleted, the work is moot.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Correlates the job with the request that dispatched it.
     */
    public string $correlationId;

    public function __construct()
    {
        $this->correlationId = app()->bound('correlation_id') && is_string(app('correlation_id'))
            ? app('correlation_id')
            : (string) Str::uuid();

        $this->onQueue($this->queueName());
    }

    /**
     * Exponential backoff in seconds between attempts.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * Absolute deadline. After this the job stops being retried even if
     * attempts remain.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    /**
     * Tags surfaced in Horizon, which is how you find a specific job among
     * thousands. Subclasses should append model-specific tags.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'job:'.class_basename(static::class),
            'correlation:'.$this->correlationId,
        ];
    }

    /**
     * Terminal failure handler. Runs after the final attempt.
     */
    public function failed(?Throwable $exception): void
    {
        Log::channel('errors')->error('Queued job failed permanently', [
            'job' => static::class,
            'correlation_id' => $this->correlationId,
            'queue' => $this->queue ?? 'default',
            'attempts' => $this->attempts(),
            'exception' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);
    }

    /**
     * Queue this job is pushed onto. Must match a queue Horizon supervises —
     * a typo here means the job is enqueued and never picked up.
     */
    protected function queueName(): string
    {
        return 'default';
    }
}
