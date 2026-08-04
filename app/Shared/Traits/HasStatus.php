<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use App\Shared\Enums\Status;
use Illuminate\Database\Eloquent\Builder;

/**
 * Generic lifecycle status backed by the Status enum.
 *
 * Models with a domain-specific lifecycle (Store, Offer, Product) cast their
 * own enum instead of using this trait — a shared status column must not be
 * overloaded with meanings that only apply to one aggregate.
 *
 * Requires: $table->string('status')->default('draft')->index();
 *
 * @property Status $status
 */
trait HasStatus
{
    /**
     * Merge the enum cast without clobbering the model's own casts.
     */
    public function initializeHasStatus(): void
    {
        $this->mergeCasts([$this->getStatusColumn() => Status::class]);
    }

    public function getStatusColumn(): string
    {
        return 'status';
    }

    /**
     * @param Builder<static> $query
     *
     * @return Builder<static>
     */
    public function scopeWhereStatus(Builder $query, Status|string ...$statuses): Builder
    {
        $values = array_map(
            static fn (Status|string $status): string => $status instanceof Status ? $status->value : $status,
            $statuses,
        );

        return $query->whereIn($this->getStatusColumn(), $values);
    }

    /**
     * Records the public may see. Prefer this over ->whereStatus(Status::Active)
     * at call sites so visibility rules stay changeable in one place.
     *
     * @param Builder<static> $query
     *
     * @return Builder<static>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereIn(
            $this->getStatusColumn(),
            array_column(Status::visible(), 'value'),
        );
    }

    /**
     * @param Builder<static> $query
     *
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->getStatusColumn(), Status::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status === Status::Active;
    }

    public function isVisible(): bool
    {
        return $this->status->isVisible();
    }

    /**
     * Persist a new status. Returns false when the model is immutable, so the
     * caller can surface a domain error rather than assume success.
     */
    public function markAs(Status $status): bool
    {
        if (! $this->status->isMutable() && $status !== $this->status) {
            return false;
        }

        return $this->forceFill([$this->getStatusColumn() => $status])->save();
    }

    public function activate(): bool
    {
        return $this->markAs(Status::Active);
    }

    public function deactivate(): bool
    {
        return $this->markAs(Status::Inactive);
    }

    public function archive(): bool
    {
        return $this->forceFill([$this->getStatusColumn() => Status::Archived])->save();
    }
}
