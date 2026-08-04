<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stamps the authenticated actor who created the record.
 *
 * Works across all three guards: whichever guard is active supplies the id.
 * Records created by queue workers, console commands or seeders have a null
 * creator, which is correct — attributing system writes to a real person makes
 * the audit trail lie.
 *
 * Requires: $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
 *
 * @property int|null $created_by
 * @property-read User|null $creator
 */
trait HasCreator
{
    public static function bootHasCreator(): void
    {
        static::creating(function (self $model): void {
            if ($model->{$model->getCreatorColumn()} === null) {
                $model->{$model->getCreatorColumn()} = $model->resolveActorId();
            }
        });
    }

    public function getCreatorColumn(): string
    {
        return 'created_by';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, $this->getCreatorColumn());
    }

    /**
     * @param Builder<static> $query
     *
     * @return Builder<static>
     */
    public function scopeCreatedBy(Builder $query, User|int $user): Builder
    {
        return $query->where(
            $this->getCreatorColumn(),
            $user instanceof User ? $user->getKey() : $user,
        );
    }

    public function wasCreatedBy(User|int|null $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->{$this->getCreatorColumn()}
            === ($user instanceof User ? $user->getKey() : $user);
    }

    /**
     * First authenticated guard wins. Guards are mutually exclusive in practice
     * (one browser session belongs to one actor type) but the order is fixed so
     * the behaviour is deterministic if that ever stops being true.
     */
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
