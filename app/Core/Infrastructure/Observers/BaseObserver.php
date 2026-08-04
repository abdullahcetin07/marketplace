<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Observers;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Root of every model observer.
 *
 * WHAT BELONGS IN AN OBSERVER: derived state that must hold no matter how the
 * model was written — cache invalidation, search reindexing, denormalised
 * counters, audit stamps.
 *
 * WHAT DOES NOT: business rules. An observer fires on seeders, imports, admin
 * edits and tinker sessions alike, and its failure modes are invisible at the
 * call site. If a rule can reject a write, it belongs in an action where the
 * caller can see and handle the refusal.
 *
 * Every hook is a no-op by default, so a subclass overrides only what it needs
 * and gains new hooks automatically as this class grows.
 *
 * Register observers in AppServiceProvider (or a module's service provider)
 * using the #[ObservedBy] attribute on the model.
 *
 * @template TModel of Model
 */
abstract class BaseObserver
{
    /**
     * Whether hooks are suppressed for bulk operations. Toggled by
     * withoutObserving().
     */
    protected static bool $muted = false;

    /**
     * Run a callback with this observer's side effects disabled.
     *
     * Use for imports and back-fills where per-row reindexing would be
     * catastrophic; reindex once at the end instead.
     *
     * @template TValue
     *
     * @param Closure(): TValue $callback
     *
     * @return TValue
     */
    public static function withoutObserving(Closure $callback): mixed
    {
        $previous = static::$muted;
        static::$muted = true;

        try {
            return $callback();
        } finally {
            static::$muted = $previous;
        }
    }

    /**
     * @param TModel $model
     */
    public function creating(Model $model): void
    {
        //
    }

    /**
     * @param TModel $model
     */
    public function created(Model $model): void
    {
        $this->onChange($model, 'created');
    }

    /**
     * @param TModel $model
     */
    public function updating(Model $model): void
    {
        //
    }

    /**
     * @param TModel $model
     */
    public function updated(Model $model): void
    {
        $this->onChange($model, 'updated');
    }

    /**
     * @param TModel $model
     */
    public function saving(Model $model): void
    {
        //
    }

    /**
     * @param TModel $model
     */
    public function saved(Model $model): void
    {
        //
    }

    /**
     * @param TModel $model
     */
    public function deleting(Model $model): void
    {
        //
    }

    /**
     * @param TModel $model
     */
    public function deleted(Model $model): void
    {
        $this->onChange($model, 'deleted');
    }

    /**
     * @param TModel $model
     */
    public function restored(Model $model): void
    {
        $this->onChange($model, 'restored');
    }

    /**
     * @param TModel $model
     */
    public function forceDeleted(Model $model): void
    {
        $this->onChange($model, 'force_deleted');
    }

    /**
     * Single funnel for the three side effects nearly every observer wants.
     * Override the individual hooks below rather than this method.
     *
     * @param TModel $model
     */
    protected function onChange(Model $model, string $event): void
    {
        if (static::$muted) {
            return;
        }

        $this->invalidateCache($model, $event);
        $this->syncSearchIndex($model, $event);
        $this->recordAudit($model, $event);
    }

    /**
     * @param TModel $model
     */
    protected function invalidateCache(Model $model, string $event): void
    {
        //
    }

    /**
     * Scout keeps the index in sync automatically for Searchable models; this
     * hook exists for indexes a model affects but does not own (e.g. an offer
     * change that must refresh its parent product document).
     *
     * @param TModel $model
     */
    protected function syncSearchIndex(Model $model, string $event): void
    {
        //
    }

    /**
     * @param TModel $model
     */
    protected function recordAudit(Model $model, string $event): void
    {
        //
    }

    /**
     * @param TModel $model
     * @param array<string, mixed> $context
     */
    protected function log(Model $model, string $message, array $context = []): void
    {
        Log::channel('audit')->info($message, [
            'model' => $model::class,
            'model_id' => $model->getKey(),
            ...$context,
        ]);
    }
}
