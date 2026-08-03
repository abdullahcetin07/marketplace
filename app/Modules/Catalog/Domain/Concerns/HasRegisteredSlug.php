<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Concerns;

use App\Modules\Catalog\Domain\Contracts\SlugRegistryContract;
use App\Modules\Catalog\Domain\Enums\SluggableType;

/**
 * Keeps an entity's `slug` column and the global registry in step (ADR-059).
 *
 * A MODEL HOOK RATHER THAN A CALL IN EACH ACTION, and that is the whole point:
 * there are four actions that write a slug today, plus two Filament panels, three
 * factories and a seeder — and the failure mode of forgetting one is not a crash,
 * it is a product that silently has no public address. A registry that is only
 * populated by the paths somebody remembered is not a registry.
 *
 * `saved`, NOT `saving`: the row needs an id before it can be pointed at, and a
 * create has none until it is written. Both run inside the action's transaction
 * (`BaseAction`), so a failed registration rolls the entity back with it — the
 * two cannot end up disagreeing.
 *
 * IT ONLY WRITES WHEN THE SLUG ACTUALLY CHANGED, so an ordinary edit — a price
 * correction, a description tidy, a moderation transition — costs nothing.
 *
 * IT DOES NOT GENERATE. The slug arrives on the model already, from the actions
 * that ask `CategorySlugGeneratorContract` (which asks the same registry). This
 * only records where the entity ended up, so a model saved with a hand-set slug
 * still gets an address rather than being quietly unreachable.
 *
 * HARD DELETES FORGET, soft deletes do not. A soft-deleted product stops
 * resolving on its own — the registry loads the model to answer, and the default
 * scope hides it — so its slug stays parked and comes back if it is restored.
 * A row that is really gone must release its slug, or the name is reserved
 * forever by something that no longer exists.
 *
 * @see App\Modules\Catalog\Infrastructure\Registries\SlugRegistry
 */
trait HasRegisteredSlug
{
    public static function bootHasRegisteredSlug(): void
    {
        static::saved(function (self $model): void {
            $slug = $model->getAttribute('slug');

            if (! is_string($slug) || $slug === '') {
                return;
            }

            // `wasChanged` is false on a create (nothing changed — everything is
            // new), so `wasRecentlyCreated` is the other half of "this needs an
            // address".
            if (! $model->wasRecentlyCreated && ! $model->wasChanged('slug')) {
                return;
            }

            app(SlugRegistryContract::class)->register(
                $slug,
                $model->sluggableType(),
                (int) $model->getKey(),
            );
        });

        static::deleted(function (self $model): void {
            // `forceDeleting` is null on a model without SoftDeletes, where every
            // delete is permanent — so the check reads "is this row really gone".
            $soft = method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting();

            if ($soft) {
                return;
            }

            app(SlugRegistryContract::class)->forget($model->sluggableType(), (int) $model->getKey());
        });
    }

    /**
     * Which kind of public address this entity has.
     */
    abstract public function sluggableType(): SluggableType;
}
