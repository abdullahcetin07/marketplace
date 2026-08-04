<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Http\Request;

/**
 * The public storefront contract (ADR-034/036) — the marketplace's canonical
 * public entry point.
 *
 * A STRICT ALLOW-LIST. Only publishable fields appear: the UUID (the sole public
 * identifier — never the internal id), slug, name, locale, branding, SEO and
 * public contact. Internal ids, `organization_id`, settings, audit, timestamps
 * and any private configuration are deliberately absent.
 *
 * STABLE + EXTENSIBLE. `extensions` is where other modules' contributions land
 * (products, reviews, campaigns, …) under their own keys (ADR-036). New sections
 * arrive as new keys — the envelope shape never changes — so deployed clients
 * never break. A field is added here only when it is a permanent public promise.
 *
 * @mixin Store
 *
 * @see docs/modules/Store.md §12
 */
final class PublicStoreResource extends BaseResource
{
    /**
     * @var array<string, mixed>
     */
    private array $extensions = [];

    /**
     * @param array<string, mixed> $extensions
     */
    public function withExtensions(array $extensions): self
    {
        $this->extensions = $extensions;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Store $store */
        $store = $this->resource;

        return [
            'id' => $this->publicId(),
            'slug' => $store->slug,
            'name' => $store->name,
            'locale' => [
                'language' => $store->defaultLanguage?->code,
                'currency' => $store->defaultCurrency?->code,
                'timezone' => $store->timezone?->name,
            ],
            'branding' => $this->branding($store),
            'seo' => $this->seo($store),
            'contact' => $this->contact($store),
            'extensions' => (object) $this->extensions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function branding(Store $store): array
    {
        $branding = $store->branding;

        return [
            'logo' => $this->mediaUrl($store, 'logo'),
            'banner' => $this->mediaUrl($store, 'banner'),
            'favicon' => $this->mediaUrl($store, 'favicon'),
            'primary_color' => $branding?->primary_color,
            'accent_color' => $branding?->accent_color,
            'theme' => $branding?->theme,
        ];
    }

    private function mediaUrl(Store $store, string $collection): ?string
    {
        $url = $store->branding?->getFirstMediaUrl($collection);

        return ($url === null || $url === '') ? null : $url;
    }

    /**
     * @return array<string, mixed>
     */
    private function seo(Store $store): array
    {
        $seo = $store->seo;

        return [
            'meta_title' => $seo?->meta_title ?? $store->name,
            'meta_description' => $seo?->meta_description,
            'meta_keywords' => $seo?->meta_keywords ?? [],
            'canonical_url' => $seo?->canonical_url ?? $this->defaultCanonical($store),
            'robots' => $seo?->robots ?? 'index,follow',
        ];
    }

    /**
     * A sensible canonical when the seller has not set one: the frontend URL +
     * the primary public path segment + the slug (§5, ADR-035). Structured-data
     * / Open-Graph generation builds on the same allow-list later.
     */
    private function defaultCanonical(Store $store): string
    {
        $segments = (array) config('marketplace.store.public_path_segments', ['store']);
        $segment = (string) ($segments[0] ?? 'store');

        return rtrim((string) config('marketplace.frontend.url'), '/').'/'.$segment.'/'.$store->slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function contact(Store $store): array
    {
        $contact = $store->contact;

        return [
            'email' => $contact?->public_email,
            'phone' => $contact?->public_phone,
            'address' => $contact?->address,
            'support_hours' => $contact?->support_hours,
        ];
    }
}
