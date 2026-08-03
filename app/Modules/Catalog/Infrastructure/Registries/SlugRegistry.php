<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Registries;

use App\Modules\Catalog\Domain\Contracts\SlugRegistryContract;
use App\Modules\Catalog\Domain\DTOs\SlugMatchDTO;
use App\Modules\Catalog\Domain\Enums\SluggableType;
use App\Modules\Catalog\Domain\Models\Slug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The global slug namespace, enforced (ADR-059).
 *
 * `Str::slug` ALREADY FOLDS TURKISH CORRECTLY — İ→i, ı→i, ş→s, ğ→g, ü→u, ö→o,
 * ç→c — so there is no hand-rolled slugifier here, and a test pins that rather
 * than trusting it. Writing one would mean maintaining a transliteration table
 * that the framework already maintains better, and getting it subtly wrong
 * produces URLs nobody notices until they are indexed.
 *
 * RESERVED WORDS ARE REFUSED, NOT REJECTED. A seller naming a product "Sepet"
 * gets `sepet-2`, not an error: their product is fine, it simply cannot live at
 * the storefront's basket URL. Throwing would block an ordinary listing over a
 * routing detail the seller has no way to know about.
 *
 * THE COLLISION LOOP IS A LOOP, not a count-and-append, because the count is
 * unreliable the moment an alias exists: three retired slugs for one product
 * would make "-4" look free when it is not. Asking the unique index one candidate
 * at a time is slower and correct.
 *
 * IT STILL RACES, and that is stated rather than hidden. Two concurrent creates
 * can both find `bioderma` free; the UNIQUE index refuses the second, and the
 * caller sees an integrity violation rather than a silently duplicated URL — the
 * correct failure. Serialising slug issue behind a lock would put a mutex on every
 * product creation to prevent something that needs two people naming the same
 * thing in the same millisecond.
 *
 * @see App\Modules\Catalog\Domain\Contracts\SlugRegistryContract
 */
final class SlugRegistry implements SlugRegistryContract
{
    /**
     * Where the suffix loop gives up.
     *
     * Not a real limit — reaching 1 000 collisions on one name means something
     * pathological is happening — but an unbounded `while` that queries per turn
     * is a hang, and a hang is harder to diagnose than an exception.
     */
    private const int MAX_SUFFIX = 1_000;

    public function issue(string $requested, SluggableType $type, ?int $keepFor = null): string
    {
        $base = Str::slug($requested);

        /*
        | A title of nothing but non-transliterable characters slugs to the empty
        | string, which would put the entity at the site root. The type name is an
        | ugly fallback and an addressable one, and the loop below makes it unique.
        */
        if ($base === '') {
            $base = $type->value;
        }

        $candidate = $base;
        $suffix = 2;

        while ($this->isTaken($candidate, $type, $keepFor) || $this->isReserved($candidate)) {
            if ($suffix > self::MAX_SUFFIX) {
                // Deliberately loud. Silently returning a random slug would hide
                // whatever is generating a thousand identical names.
                throw new RuntimeException("Could not issue a unique slug for \"{$requested}\".");
            }

            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    public function register(string $slug, SluggableType $type, int $id): void
    {
        DB::transaction(function () use ($slug, $type, $id): void {
            $existing = Slug::query()->where('slug', $slug)->first();

            if ($existing !== null) {
                /*
                | Somebody else's slug. `issue()` should have prevented this, so
                | reaching here means a caller bypassed it or lost a race — either
                | way the unique index is about to be the only thing standing
                | between one URL and two pages.
                */
                if ($existing->sluggable_type !== $type || $existing->sluggable_id !== $id) {
                    throw new RuntimeException("Slug \"{$slug}\" already belongs to another entity.");
                }

                // Ours already — possibly a retired alias being made canonical
                // again, which is a legitimate way to undo a rename.
                if ($existing->is_canonical) {
                    return;
                }
            }

            /*
            | DEMOTE, NEVER DELETE. The old address keeps resolving so an inbound
            | link 301s instead of 404ing — the entire reason this table has an
            | `is_canonical` column rather than one row per entity (ADR-059).
            */
            Slug::query()
                ->where('sluggable_type', $type->value)
                ->where('sluggable_id', $id)
                ->where('slug', '!=', $slug)
                ->update(['is_canonical' => false]);

            Slug::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'sluggable_type' => $type->value,
                    'sluggable_id' => $id,
                    'is_canonical' => true,
                ],
            );
        });
    }

    public function resolve(string $slug): ?SlugMatchDTO
    {
        $row = Slug::query()->where('slug', $slug)->first();

        if ($row === null) {
            return null;
        }

        $model = $this->model($row);

        if ($model === null) {
            /*
            | The registry row outlived what it pointed at. Treated as "no such
            | slug" rather than as an error: to a visitor it is the same fact, and
            | reporting it differently would let a probe distinguish a deleted
            | product from one that never existed.
            */
            return null;
        }

        $canonical = Slug::query()
            ->ownedBy($row->sluggable_type, $row->sluggable_id)
            ->canonical()
            ->value('slug');

        return new SlugMatchDTO(
            type: $row->sluggable_type,
            uuid: (string) $model->getAttribute('uuid'),
            slug: $row->slug,
            // Falls back to the row itself when no canonical one survives, so a
            // half-migrated registry serves a page rather than a redirect loop.
            canonicalSlug: is_string($canonical) ? $canonical : $row->slug,
        );
    }

    public function forget(SluggableType $type, int $id): void
    {
        Slug::query()
            ->where('sluggable_type', $type->value)
            ->where('sluggable_id', $id)
            ->delete();
    }

    public function isReserved(string $slug): bool
    {
        /** @var array<int, string> $reserved */
        $reserved = config('catalog.slugs.reserved', []);

        return in_array(mb_strtolower($slug), array_map('mb_strtolower', $reserved), true);
    }

    /**
     * Whether the slug is spoken for by anything but this entity.
     */
    private function isTaken(string $slug, SluggableType $type, ?int $keepFor): bool
    {
        $query = Slug::query()->where('slug', $slug);

        if ($keepFor !== null) {
            // An entity re-issuing over its own slug keeps it, rather than being
            // handed "-2" because it collided with itself.
            $query->where(static fn ($inner) => $inner
                ->where('sluggable_type', '!=', $type->value)
                ->orWhere('sluggable_id', '!=', $keepFor));
        }

        return $query->exists();
    }

    /**
     * The row's target, or null when it has gone.
     */
    private function model(Slug $row): ?Model
    {
        $class = $row->sluggable_type->modelClass();

        return $class::query()->whereKey($row->sluggable_id)->first();
    }
}
