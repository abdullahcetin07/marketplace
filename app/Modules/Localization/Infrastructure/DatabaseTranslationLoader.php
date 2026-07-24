<?php

declare(strict_types=1);

namespace App\Modules\Localization\Infrastructure;

use App\Modules\Localization\Domain\Contracts\LanguageRepositoryContract;
use App\Modules\Localization\Domain\Models\Translation;
use Illuminate\Translation\FileLoader;
use Throwable;

/**
 * Overlays database translations on top of the shipped lang/ files.
 *
 * Registered in place of Laravel's FileLoader by LocalizationServiceProvider.
 *
 * WHY EXTEND RATHER THAN REPLACE FileLoader: the files stay the source of truth
 * for which keys exist. A developer adding `errors.new_thing` gets a working
 * key with no database row, and an operator who makes a mistake can delete
 * their override to fall back to the shipped copy. A pure database loader would
 * mean every new key needs a seeder, and a missing row renders the raw dotted
 * key to a customer.
 *
 * Resolution: database override → file → key itself.
 *
 * FAILURE MODE, DELIBERATE: if the database is unavailable this falls back
 * silently to file translations. A degraded locale is vastly better than a
 * site-wide 500 — translation is not worth taking the platform down for.
 *
 * @see App\Modules\Localization\Domain\Models\Translation
 * @see docs/localization.md
 */
final class DatabaseTranslationLoader extends FileLoader
{
    /**
     * Per-request memo. The loader is called for every group on every request,
     * and even a cache hit costs a serialise/deserialise.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $memo = [];

    /**
     * @param  string  $locale
     * @param  string  $group
     * @param  string|null  $namespace
     * @return array<string, mixed>
     */
    public function load($locale, $group, $namespace = null): array
    {
        $fileTranslations = parent::load($locale, $group, $namespace);

        // Vendor package translations are not overridable from the admin UI.
        // Allowing it would let an operator edit strings they cannot see the
        // context for, and those keys change on every package upgrade.
        if ($namespace !== null && $namespace !== '*') {
            return $fileTranslations;
        }

        $overrides = $this->overridesFor($locale, $group);

        if ($overrides === []) {
            return $fileTranslations;
        }

        // array_replace_recursive so a partial override of a nested group
        // (e.g. only enums.Status.active) leaves its siblings intact.
        return array_replace_recursive($fileTranslations, $overrides);
    }

    /**
     * Database overrides for one locale and group, as a nested array matching
     * the shape of a lang file.
     *
     * @return array<string, mixed>
     */
    private function overridesFor(string $locale, string $group): array
    {
        $memoKey = $locale.':'.$group;

        if (array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
        }

        return $this->memo[$memoKey] = $this->fetch($locale, $group);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $locale, string $group): array
    {
        try {
            // Resolved lazily: the loader is constructed during framework boot,
            // before the container has the repository binding available.
            $language = app(LanguageRepositoryContract::class)->findByCode($locale);

            if ($language === null) {
                return [];
            }

            /** @var array<string, mixed> $cached */
            $cached = cache()->remember(
                sprintf('localization:translations:%d:%s', $language->getKey(), $group),
                3600,
                static function () use ($language, $group): array {
                    $rows = Translation::query()
                        ->forLanguage($language)
                        ->inGroup($group)
                        ->pluck('value', 'key')
                        ->all();

                    $nested = [];

                    foreach ($rows as $key => $value) {
                        // 'Status.active' => ['Status' => ['active' => ...]]
                        data_set($nested, $key, $value);
                    }

                    return $nested;
                },
            );

            return $cached;
        } catch (Throwable) {
            // Migrations not yet run, database unreachable, cache down — all
            // recoverable by serving the shipped strings. @see class docblock.
            return [];
        }
    }
}
