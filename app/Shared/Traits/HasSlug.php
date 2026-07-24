<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * URL-safe, collision-free slug generated from a source attribute.
 *
 * Turkish-aware: Str::slug() is given the 'tr' language so "Ürün Kataloğu"
 * becomes "urun-katalogu" rather than dropping the non-ASCII characters.
 *
 * Slugs are generated once on create and then frozen — regenerating on every
 * title edit silently breaks inbound links and search rankings. Call
 * regenerateSlug() explicitly when a rename really should change the URL.
 *
 * Requires: $table->string('slug')->unique();
 *
 * @property string $slug
 */
trait HasSlug
{
    public static function bootHasSlug(): void
    {
        static::creating(function (self $model): void {
            if (blank($model->{$model->getSlugColumn()})) {
                $model->{$model->getSlugColumn()} = $model->generateUniqueSlug();
            }
        });
    }

    public static function findBySlug(string $slug): ?static
    {
        return static::query()->where((new static)->getSlugColumn(), $slug)->first();
    }

    /**
     * Attribute the slug is derived from. Override per model.
     */
    public function getSlugSource(): string
    {
        return 'name';
    }

    public function getSlugColumn(): string
    {
        return 'slug';
    }

    /**
     * Build a slug that does not collide, appending -2, -3, ... as needed.
     */
    public function generateUniqueSlug(?string $source = null): string
    {
        $source ??= (string) $this->{$this->getSlugSource()};

        $base = Str::slug($source, '-', 'tr');

        // A source of only punctuation/emoji slugs to an empty string, which
        // would violate the unique index on the second such record.
        if ($base === '') {
            $base = Str::lower(class_basename(static::class)).'-'.Str::random(6);
        }

        $slug = $base;
        $suffix = 1;

        while ($this->slugExists($slug)) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    /**
     * Recompute and persist the slug. Deliberately explicit — see class docs.
     */
    public function regenerateSlug(): bool
    {
        return $this->forceFill([
            $this->getSlugColumn() => $this->generateUniqueSlug(),
        ])->save();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereSlug(Builder $query, string $slug): Builder
    {
        return $query->where($this->getSlugColumn(), $slug);
    }

    protected function slugExists(string $slug): bool
    {
        return static::query()
            ->where($this->getSlugColumn(), $slug)
            ->when($this->exists, fn (Builder $q): Builder => $q->whereKeyNot($this->getKey()))
            ->when(
                method_exists($this, 'bootSoftDeletes'),
                fn (Builder $q): Builder => $q->withTrashed(),
            )
            ->exists();
    }
}
