<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stamps the authenticated actor who last modified the record.
 *
 * Complements HasCreator; the two are separate traits because plenty of models
 * want provenance without wanting mutation tracking (and vice versa).
 *
 * NOTE: this records only *who* touched the row last. The full field-level
 * history lives in the activity log — see App\Shared\Traits\HasActivityLog and
 * docs/logging.md.
 *
 * Requires: $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
 *
 * @property int|null $updated_by
 * @property-read User|null $updater
 */
trait HasUpdater
{
    public static function bootHasUpdater(): void
    {
        static::updating(function (self $model): void {
            // Skip when nothing but the stamp itself changed, otherwise a
            // no-op save() rewrites the audit trail with misleading data.
            if ($model->isDirty() && ! $model->isDirty($model->getUpdaterColumn())) {
                $model->{$model->getUpdaterColumn()} = $model->resolveActorId();
            }
        });
    }

    public function getUpdaterColumn(): string
    {
        return 'updated_by';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, $this->getUpdaterColumn());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUpdatedBy(Builder $query, User|int $user): Builder
    {
        return $query->where(
            $this->getUpdaterColumn(),
            $user instanceof User ? $user->getKey() : $user,
        );
    }

    /**
     * Persist a change without touching the updater stamp — for system-driven
     * writes (imports, reconciliation jobs) that should not look like a human
     * edited the record.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateSilently(array $attributes): bool
    {
        return static::withoutEvents(fn (): bool => $this->forceFill($attributes)->save());
    }

    protected function resolveActorId(): ?int
    {
        foreach (['admin', 'seller', 'customer', 'web'] as $guard) {
            $id = auth()->guard($guard)->id();

            if ($id !== null) {
                return (int) $id;
            }
        }

        return null;
    }
}
