<?php

declare(strict_types=1);

namespace App\Modules\Localization\Domain\Contracts;

use App\Modules\Localization\Domain\Models\Language;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read port for languages.
 *
 * Declared in Domain, implemented in Infrastructure. The caching that used to
 * live on the model's static finders lives behind this port now (ADR-019) —
 * which is also what gives the module its first repository, closing the gap
 * against 001_Architecture.md §4.
 *
 * @see App\Modules\Localization\Infrastructure\Repositories\LanguageRepository
 */
interface LanguageRepositoryContract
{
    /**
     * The platform default. Throws if unseeded — a platform with no default
     * locale cannot serve a request, so failing loudly at boot beats a null
     * dereference on the first page.
     */
    public function default(): Language;

    /**
     * The locale translation keys are authored in. A developer concern, so it
     * is configured by ISO code rather than flagged on a row.
     */
    public function fallback(): ?Language;

    public function findByCode(string $code): ?Language;

    /**
     * Enabled languages, ordered for a switcher.
     *
     * @return Collection<int, Language>
     */
    public function enabled(): Collection;

    /**
     * The language in effect for this request, resolved from the app locale.
     */
    public function current(): Language;

    /**
     * Drop cached entries. Called by the Infrastructure observer on write.
     */
    public function flush(?Language $language = null): void;
}
