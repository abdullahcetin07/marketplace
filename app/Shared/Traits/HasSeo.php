<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use Illuminate\Support\Str;

/**
 * Per-record SEO metadata, stored in a single JSONB `seo` column.
 *
 * WHY JSONB rather than eight columns: the shape of SEO metadata changes far
 * more often than the aggregates that carry it (Open Graph, Twitter cards,
 * structured data all arrived after the fact). A JSONB column absorbs those
 * additions without a migration on a large table, and PostgreSQL can still
 * index individual keys if a query ever needs one.
 *
 * Every getter falls back to a sensible derivation from the model itself, so
 * a record with no SEO input still renders complete meta tags.
 *
 * Requires: $table->jsonb('seo')->nullable();
 *
 * @property array<string, mixed>|null $seo
 */
trait HasSeo
{
    public function initializeHasSeo(): void
    {
        $this->mergeCasts([$this->getSeoColumn() => 'array']);
    }

    public function getSeoColumn(): string
    {
        return 'seo';
    }

    /**
     * Attribute used when no explicit seo.title was supplied.
     */
    public function getSeoTitleSource(): string
    {
        return 'name';
    }

    /**
     * Attribute used when no explicit seo.description was supplied.
     */
    public function getSeoDescriptionSource(): string
    {
        return 'description';
    }

    public function seoValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->{$this->getSeoColumn()}, $key, $default);
    }

    /**
     * Google truncates around 60 characters.
     */
    public function seoTitle(): string
    {
        $title = $this->seoValue('title')
            ?? $this->{$this->getSeoTitleSource()}
            ?? config('app.name');

        return Str::limit(strip_tags((string) $title), 60, '');
    }

    /**
     * Google truncates around 160 characters.
     */
    public function seoDescription(): string
    {
        $description = $this->seoValue('description')
            ?? $this->{$this->getSeoDescriptionSource()}
            ?? '';

        return Str::limit(strip_tags((string) $description), 160, '');
    }

    /**
     * @return array<int, string>
     */
    public function seoKeywords(): array
    {
        $keywords = $this->seoValue('keywords', []);

        if (is_string($keywords)) {
            $keywords = explode(',', $keywords);
        }

        return array_values(array_filter(array_map(
            static fn (mixed $keyword): string => trim((string) $keyword),
            is_array($keywords) ? $keywords : [],
        )));
    }

    public function seoCanonicalUrl(): ?string
    {
        return $this->seoValue('canonical');
    }

    /**
     * `noindex` is opt-in per record; anything not explicitly hidden is
     * indexable, so a missing key must not accidentally deindex a page.
     */
    public function seoIsIndexable(): bool
    {
        return $this->seoValue('noindex', false) !== true;
    }

    public function seoRobots(): string
    {
        return $this->seoIsIndexable() ? 'index,follow' : 'noindex,nofollow';
    }

    /**
     * Flat payload the Next.js frontend consumes to build its <head>.
     *
     * @return array<string, mixed>
     */
    public function seoMeta(): array
    {
        return [
            'title' => $this->seoTitle(),
            'description' => $this->seoDescription(),
            'keywords' => $this->seoKeywords(),
            'canonical' => $this->seoCanonicalUrl(),
            'robots' => $this->seoRobots(),
            'og' => [
                'title' => $this->seoValue('og_title') ?? $this->seoTitle(),
                'description' => $this->seoValue('og_description') ?? $this->seoDescription(),
                'image' => $this->seoValue('og_image'),
                'type' => $this->seoValue('og_type', 'website'),
            ],
        ];
    }

    /**
     * Merge a partial SEO payload without dropping untouched keys.
     *
     * @param array<string, mixed> $attributes
     */
    public function setSeo(array $attributes): static
    {
        $this->{$this->getSeoColumn()} = array_merge(
            $this->{$this->getSeoColumn()} ?? [],
            $attributes,
        );

        return $this;
    }
}
