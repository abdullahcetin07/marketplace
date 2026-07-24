<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Gives a model a public, non-enumerable UUID alongside its internal bigint PK.
 *
 * WHY BOTH: the auto-increment primary key stays the storage and foreign-key
 * unit (compact, sequential, index-friendly on PostgreSQL), while the UUID is
 * the only identifier ever exposed over the API or in a URL. This prevents
 * competitors from deriving order volume by incrementing an id, without paying
 * the index-fragmentation cost of a UUID primary key.
 *
 * Requires a `uuid` column: $table->uuid('uuid')->unique();
 *
 * @property string $uuid
 *
 * @method static Builder whereUuid(string|array<int, string> $uuid)
 */
trait HasUuid
{
    /**
     * Booted by Eloquent via the trait-name convention.
     */
    public static function bootHasUuid(): void
    {
        static::creating(function (self $model): void {
            if (blank($model->{$model->getUuidColumn()})) {
                $model->{$model->getUuidColumn()} = (string) Str::uuid();
            }
        });
    }

    /**
     * Resolve by UUID, or fail.
     */
    public static function findByUuidOrFail(string $uuid): static
    {
        return static::query()->where((new static)->getUuidColumn(), $uuid)->firstOrFail();
    }

    public static function findByUuid(string $uuid): ?static
    {
        return static::query()->where((new static)->getUuidColumn(), $uuid)->first();
    }

    public function initializeHasUuid(): void
    {
        // Guarantee the UUID is never mass-assignable, whatever $fillable says.
        $this->mergeGuarded([$this->getUuidColumn()]);
    }

    public function getUuidColumn(): string
    {
        return 'uuid';
    }

    /**
     * Bind {model} route parameters by UUID so no internal id leaks into a URL.
     */
    public function getRouteKeyName(): string
    {
        return $this->getUuidColumn();
    }

    /**
     * @param  Builder<static>  $query
     * @param  string|array<int, string>  $uuid
     * @return Builder<static>
     */
    public function scopeWhereUuid(Builder $query, string|array $uuid): Builder
    {
        return is_array($uuid)
            ? $query->whereIn($this->getUuidColumn(), $uuid)
            : $query->where($this->getUuidColumn(), $uuid);
    }
}
