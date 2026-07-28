<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Concerns;

/**
 * Per-locale text columns for catalog display strings (Catalog.md §13.5).
 *
 * WHY COLUMNS AND NOT THE `translations` TABLE: that table exists for UI copy —
 * keys an operator overrides without a deploy. Catalog text is *content*, not
 * copy: it is authored per record, searched, sorted and filtered on. A join per
 * string, or a JSON blob, would make "products whose Turkish title matches X"
 * an awkward query on the platform's single most-read table. So `title_tr` and
 * `title_en` are real columns, indexable and orderable.
 *
 * The ruling is tr + en FROM THE START (§13.5): the platform is bilingual and
 * retrofitting per-locale columns onto a populated catalog costs far more than
 * carrying an empty English column now.
 *
 * Which locales exist is `config('catalog.locales')` — a schema fact (adding one
 * is a migration), which is why it lives in config rather than the `languages`
 * lookup table. `config()` is explicitly permitted in the Domain layer
 * (ADR-019): it reads a static array, it does not resolve infrastructure.
 *
 * Usage — declare the base attribute names, get `title_tr`/`title_en` handling:
 *
 *     final class Product extends Model
 *     {
 *         use HasLocalizedText;
 *
 *         public static function localizedAttributes(): array
 *         {
 *             return ['title', 'description'];
 *         }
 *     }
 *
 *     $product->localized('title');        // active locale, then fallback
 *     Product::localizedColumn('title');   // 'title_tr' — for orderBy/where
 */
trait HasLocalizedText
{
    /**
     * The base attribute names this model carries per-locale columns for.
     *
     * A method rather than a property, so a model can override it without the
     * trait/class property-conflict rules getting in the way. Overridden by
     * every model that uses the trait; the empty default is never the answer.
     *
     * @return array<int, string>
     */
    public static function localizedAttributes(): array
    {
        return [];
    }

    /**
     * Every per-locale column on the model, for a migration-shaped list or a
     * select. `['name_tr', 'name_en']`.
     *
     * @return array<int, string>
     */
    public static function allLocalizedColumns(): array
    {
        $columns = [];

        foreach (static::localizedAttributes() as $attribute) {
            $columns = [...$columns, ...self::localizedColumns($attribute)];
        }

        return $columns;
    }

    /**
     * Every locale the catalog carries columns for. The first is the authoring
     * locale a form defaults to.
     *
     * @return array<int, string>
     */
    public static function catalogLocales(): array
    {
        /** @var array<int, string> $locales */
        $locales = (array) config('catalog.locales', ['tr', 'en']);

        return array_values(array_filter($locales, 'is_string'));
    }

    /**
     * The locale used when the active one has no text — the platform's
     * authored fallback (config/marketplace.php).
     */
    public static function fallbackLocale(): string
    {
        $fallback = config('marketplace.localization.fallback_language', 'en');

        return is_string($fallback) ? $fallback : 'en';
    }

    /**
     * The column backing one attribute in one locale, defaulting to the ACTIVE
     * locale — so `orderBy(Product::localizedColumn('title'))` sorts in the
     * language the operator is reading.
     */
    public static function localizedColumn(string $attribute, ?string $locale = null): string
    {
        return $attribute.'_'.($locale ?? self::activeLocale());
    }

    /**
     * Every column backing one attribute, in locale order.
     *
     * @return array<int, string>
     */
    public static function localizedColumns(string $attribute): array
    {
        return array_map(
            static fn (string $locale): string => $attribute.'_'.$locale,
            self::catalogLocales(),
        );
    }

    /**
     * Resolve one attribute: the requested (or active) locale, then the
     * platform fallback, then the first locale that has anything at all.
     *
     * Never returns null. A half-translated catalog entry should render in the
     * wrong language rather than render as a blank row — an empty product title
     * in a moderation queue is worse than a Turkish one on an English page.
     */
    public function localized(string $attribute, ?string $locale = null): string
    {
        $candidates = [
            $locale ?? self::activeLocale(),
            self::fallbackLocale(),
            ...self::catalogLocales(),
        ];

        foreach ($candidates as $candidate) {
            $value = $this->getAttribute($attribute.'_'.$candidate);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Whether the attribute has text in every locale the catalog carries — the
     * completeness check the authoring UI nudges on.
     */
    public function isFullyLocalized(string $attribute): bool
    {
        foreach (self::catalogLocales() as $locale) {
            $value = $this->getAttribute($attribute.'_'.$locale);

            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Spread a `['tr' => '...', 'en' => '...']` map onto the backing columns,
     * ignoring locales the catalog does not carry.
     *
     * @param array<string, string|null> $byLocale
     */
    public function fillLocalized(string $attribute, array $byLocale): static
    {
        foreach (self::catalogLocales() as $locale) {
            if (array_key_exists($locale, $byLocale)) {
                $this->setAttribute($attribute.'_'.$locale, $byLocale[$locale]);
            }
        }

        return $this;
    }

    /**
     * The backing columns as a `['tr' => '...', 'en' => '...']` map — what a
     * localized form field binds to.
     *
     * @return array<string, string|null>
     */
    public function localizedMap(string $attribute): array
    {
        $map = [];

        foreach (self::catalogLocales() as $locale) {
            $value = $this->getAttribute($attribute.'_'.$locale);
            $map[$locale] = is_string($value) ? $value : null;
        }

        return $map;
    }

    /**
     * The locale the application is currently rendering in.
     *
     * Reading the active locale is ambient application state, like `now()` — it
     * resolves no infrastructure binding, so it sits on the permitted side of
     * ADR-019 alongside `now()` and `config()`. It is narrowed to a supported
     * catalog locale so an unexpected locale falls back rather than probing a
     * column that does not exist.
     */
    private static function activeLocale(): string
    {
        $locale = app()->getLocale();
        $supported = self::catalogLocales();

        return in_array($locale, $supported, true)
            ? $locale
            : (self::fallbackLocale() !== '' ? self::fallbackLocale() : ($supported[0] ?? 'tr'));
    }
}
