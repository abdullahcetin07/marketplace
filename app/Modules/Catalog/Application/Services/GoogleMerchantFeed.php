<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Core\Domain\Contracts\InventoryQueryContract;
use App\Core\Domain\Contracts\OfferQueryContract;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the Google Merchant Center product feed (RSS 2.0, `g:` namespace).
 *
 * WHY A FILE AND NOT A ROUTE THAT RENDERS. Twenty thousand items assembled inside
 * an HTTP request is a request that times out — and it would time out against
 * GOOGLE, whose fetcher records the failure against the account rather than
 * retrying politely. The nightly command writes a file; the route hands that file
 * over and does no work at all.
 *
 * WHY IT LIVES IN CATALOG. The feed is mostly catalogue: title, description,
 * images, brand, category path, GTIN. Those are this module's own columns, read
 * off its own models — a module does not ask itself a question through a Core
 * port. The two facts it genuinely does not own arrive the way every other module
 * gets them: **price through `OfferQueryContract`, availability through
 * `InventoryQueryContract`**. No module is imported, so `LayeringTest` stays
 * green, and no price or stock COLUMN is added to Catalog, so does
 * `CatalogBoundaryTest`.
 *
 * WHY EVERY READ IS BATCHED. The predecessor of this pattern — the sellable wall
 * asking `isAvailable()` once per offer — took 25 seconds and intermittently 504'd
 * (ADR-079). Per chunk this makes exactly two cross-module calls, both plural, and
 * the category tree is loaded once for the whole run rather than walked per row.
 * Strict mode would throw on a lazy load here; that it does not is the test.
 *
 * WHAT GETS DROPPED, AND WHY IT IS COUNTED. Google rejects an item with no
 * description, no image, or a disapproved category, and a rejected item is worse
 * than an absent one because it counts against the account. So the build drops
 * them itself and REPORTS the counts — the "no description" number is the backlog
 * of Turkish copy still to be written, and it is the only place that number exists.
 *
 * @see BUILD_GOOGLE_MERCHANT_FEED.md
 */
final class GoogleMerchantFeed
{
    /**
     * Google's own cap on `additional_image_link`.
     */
    private const int MAX_ADDITIONAL_IMAGES = 10;

    /**
     * Google truncates a title past 150 characters; better to cut it ourselves
     * than to have it cut mid-word in a shopping card.
     */
    private const int MAX_TITLE_LENGTH = 150;

    /**
     * @var array<int, array{name: string, slug: string, parent_id: int|null}>
     */
    private array $categories = [];

    public function __construct(
        private readonly OfferQueryContract $offers,
        private readonly InventoryQueryContract $inventory,
    ) {}

    /**
     * Build the feed and replace the published file.
     *
     * @return array{
     *     sellable: int,
     *     written: int,
     *     dropped_no_description: int,
     *     dropped_no_image: int,
     *     dropped_no_offer: int,
     *     dropped_excluded_category: int,
     *     without_gtin: int,
     *     out_of_stock: int,
     *     path: string,
     *     bytes: int,
     *     published: bool,
     * }
     */
    public function build(): array
    {
        $this->loadCategoryTree();

        $report = [
            'sellable' => 0,
            'written' => 0,
            'dropped_no_description' => 0,
            'dropped_no_image' => 0,
            'dropped_no_offer' => 0,
            'dropped_excluded_category' => 0,
            'without_gtin' => 0,
            'out_of_stock' => 0,
        ];

        $disk = Storage::disk('public');
        $path = (string) config('feed.google.path', 'feeds/google-merchant.xml');

        /*
        | BUILT BESIDE THE LIVE FILE AND MOVED OVER IT AT THE END. Google may
        | fetch at any moment, including halfway through this loop, and a
        | half-written feed is not a smaller feed — it is malformed XML, which
        | fails the whole fetch. Writing in place would make that a nightly
        | coin flip.
        */
        $temporary = $path.'.building';
        $disk->put($temporary, '');

        /** @var resource $handle */
        $handle = fopen($disk->path($temporary), 'wb');

        fwrite($handle, $this->header());

        Product::query()
            ->where('status', ProductStatus::Published->value)
            // The composite index is (status, is_sellable), in that order — the
            // same indexed read the browse uses (ADR-079).
            ->where('is_sellable', true)
            ->with(['brand', 'category', 'media', 'variants'])
            ->orderBy('id')
            ->chunk(
                max(1, (int) config('feed.google.chunk_size', 500)),
                function ($products) use ($handle, &$report): void {
                    $report['sellable'] += $products->count();

                    fwrite($handle, $this->itemsFor($products->all(), $report));
                },
            );

        fwrite($handle, $this->footer());
        fclose($handle);

        /*
        | AN EMPTY FEED NEVER REPLACES A GOOD ONE.
        |
        | A run that writes zero items is well-formed XML saying "this merchant
        | sells nothing", and Google reads it exactly that way: the entire
        | catalogue goes from listed to withdrawn overnight, and getting it back
        | is a re-review, not a re-fetch. The ways to produce one are ordinary —
        | `is_sellable` not yet rebuilt after a deploy, a bad migration, an Offer
        | outage — and every one of them is temporary while the withdrawal is not.
        |
        | So the build KEEPS the previous file and reports the failure. Yesterday's
        | prices are wrong by a day; an empty feed is wrong about everything.
        |
        | On a first run there is nothing to keep, and the route's 404 is then the
        | honest answer — Merchant Center reports a fetch failure, which is a
        | problem somebody investigates, rather than a successful fetch of nothing,
        | which is a problem nobody sees.
        */
        if ($report['written'] === 0) {
            $disk->delete($temporary);

            $report['path'] = $path;
            $report['bytes'] = $disk->exists($path) ? (int) $disk->size($path) : 0;
            $report['published'] = false;

            return $report;
        }

        $disk->delete($path);
        rename($disk->path($temporary), $disk->path($path));

        $report['path'] = $path;
        $report['bytes'] = (int) $disk->size($path);
        $report['published'] = true;

        return $report;
    }

    /**
     * @param array<int, Product> $products
     * @param array<string, mixed> $report
     */
    private function itemsFor(array $products, array &$report): string
    {
        $productUuids = array_map(static fn (Product $p): string => $p->uuid, $products);

        /*
        | THE TWO CROSS-MODULE READS, both once per chunk rather than once per
        | row. Prices carry the buy box's own eligibility, so a product absent
        | here has nothing sellable right now regardless of what `is_sellable`
        | still says.
        */
        $prices = $this->offers->buyBoxPricesFor($productUuids);

        $variantUuids = [];

        foreach ($products as $product) {
            $variant = $this->variantFor($product);

            if ($variant !== null) {
                $variantUuids[] = $variant->uuid;
            }
        }

        /*
        | Keyed `sellingOrgUuid|variantUuid`, so the answer to "can anyone sell
        | this variant" is the presence of ANY key ending in it. The feed never
        | needs to know WHICH merchant won — single-merchant settlement means the
        | shopper buys from the platform either way (ADR-060).
        */
        $availableVariants = [];

        foreach (array_keys($this->inventory->availableKeysAmong($variantUuids)) as $key) {
            $parts = explode('|', (string) $key);
            $availableVariants[end($parts)] = true;
        }

        $xml = '';

        foreach ($products as $product) {
            $item = $this->itemFor($product, $prices, $availableVariants, $report);

            if ($item !== null) {
                $xml .= $item;
                $report['written']++;
            }
        }

        return $xml;
    }

    /**
     * One `<item>`, or null when the product must not be submitted.
     *
     * @param array<string, array{price_minor: int, list_price_minor: int|null, currency_code: string, in_stock: bool, seller_count: int}> $prices
     * @param array<string, true> $availableVariants
     * @param array<string, mixed> $report
     */
    private function itemFor(Product $product, array $prices, array $availableVariants, array &$report): ?string
    {
        $price = $prices[$product->uuid] ?? null;

        if ($price === null) {
            $report['dropped_no_offer']++;

            return null;
        }

        if ($this->isExcluded($product)) {
            $report['dropped_excluded_category']++;

            return null;
        }

        $description = $this->plainText($product->localized('description'));

        if (mb_strlen($description) < (int) config('feed.google.min_description_length', 30)) {
            $report['dropped_no_description']++;

            return null;
        }

        /*
        | `large` rather than `preview`: Google wants the biggest image available
        | and penalises small ones. `imageUrl()` returns null when the conversion
        | has not been generated, which on a queue that has fallen behind is every
        | recent upload — submitting those would be submitting 404s.
        */
        $image = $product->imageUrl('large');

        if ($image === null || $image === '') {
            $report['dropped_no_image']++;

            return null;
        }

        $variant = $this->variantFor($product);

        if ($variant === null) {
            $report['dropped_no_offer']++;

            return null;
        }

        $inStock = isset($availableVariants[$variant->uuid]);

        if (! $inStock) {
            $report['out_of_stock']++;
        }

        $gtin = $this->gtinFor($product, $variant);

        if ($gtin === null) {
            $report['without_gtin']++;
        }

        $brand = $product->brand?->name;
        $sellableVariants = $product->variants->count();

        $lines = [];
        $lines[] = '  <item>';

        // PUBLIC IDENTIFIER IS THE UUID (ADR-005 §7). The internal integer id is
        // not merely private — it is the row count of the catalogue, published to
        // a competitor's crawler, and a test asserts it never appears here.
        $lines[] = $this->tag('g:id', $variant->uuid);

        if ($sellableVariants > 1) {
            $lines[] = $this->tag('g:item_group_id', $product->uuid);
        }

        $lines[] = $this->tag('title', $this->titleFor($product, $variant, $sellableVariants));
        $lines[] = $this->tag('description', $description);
        $lines[] = $this->tag('link', $this->linkFor($product));
        $lines[] = $this->tag('g:image_link', $this->absolute($image));

        foreach (array_slice($product->imageUrls('large'), 1, self::MAX_ADDITIONAL_IMAGES) as $additional) {
            $lines[] = $this->tag('g:additional_image_link', $this->absolute($additional));
        }

        $lines[] = $this->tag('g:availability', $inStock ? 'in_stock' : 'out_of_stock');

        /*
        | KDV-INCLUSIVE, AND THAT IS WHY THERE IS NO `tax` NODE. The buy box price
        | is the gross price a Turkish shopper is shown and charged (ADR-055/061);
        | adding a tax node on top would have Google display VAT twice.
        |
        | A decimal STRING built from minor units — the money rule at the boundary
        | (ADR-005). No float is constructed anywhere on this path.
        */
        $lines[] = $this->tag(
            'g:price',
            $this->decimal((int) $price['price_minor']).' '.$price['currency_code'],
        );

        if ($brand !== null && $brand !== '') {
            $lines[] = $this->tag('g:brand', $brand);
        }

        if ($gtin !== null) {
            $lines[] = $this->tag('g:gtin', $gtin);
        }

        /*
        | `identifier_exists` is how Google is told an item is legitimately
        | unidentifiable rather than sloppily submitted. Saying `yes` without a
        | GTIN gets the item disapproved; saying `no` when one exists throws away
        | the strong match that makes a listing rank.
        |
        | **IT ANSWERS FOR THE GTIN, NOT THE BRAND** (2026-08-24). This used to
        | require both, and a GTIN is a unique identifier ON ITS OWN — brand+MPN
        | is the ALTERNATIVE to it, not a second half of it. Measured on the live
        | feed: every one of the 6,933 items carries a GTIN and 3,329 of them
        | (48%) still said `no`, because that many have no brand row in the
        | catalogue. Each of those told Google to disregard the barcode — on a
        | new domain, the strongest matching signal the platform has.
        |
        | The `brand && mpn` half of Google's rule is not written here because
        | the catalogue has no MPN column; adding it as dead code would read as
        | though it did.
        */
        $lines[] = $this->tag('g:identifier_exists', $gtin !== null ? 'yes' : 'no');
        $lines[] = $this->tag('g:condition', 'new');
        $lines[] = $this->tag('g:product_type', $this->breadcrumbFor($product));

        /*
        | v1 CHARGES NO SHIPPING (ADR-063), and it is written explicitly rather
        | than left to the Merchant Center account setting: the two can disagree,
        | and when they do it is the feed a shopper sees quoted.
        */
        $lines[] = '    <g:shipping>';
        $lines[] = '      '.$this->tag('g:country', 'TR', 0);
        $lines[] = '      '.$this->tag('g:price', '0.00 '.$price['currency_code'], 0);
        $lines[] = '    </g:shipping>';
        $lines[] = '  </item>';

        return implode("\n", $lines)."\n";
    }

    /**
     * The variant a feed row stands for.
     *
     * v1 gives most products a single default variant (ADR-074), so this is
     * usually that one row. `variants` is eager-loaded by the caller — reading it
     * per product would be the lazy load strict mode throws on.
     */
    private function variantFor(Product $product): ?ProductVariant
    {
        /** @var ProductVariant|null $default */
        $default = $product->variants->firstWhere('is_default', true);

        /** @var ProductVariant|null $variant */
        $variant = $default ?? $product->variants->first();

        return $variant;
    }

    private function titleFor(Product $product, ProductVariant $variant, int $variantCount): string
    {
        $title = $product->localized('title');

        if ($variantCount > 1 && $variant->sku !== '') {
            $title .= ' - '.$variant->sku;
        }

        return mb_substr($this->plainText($title), 0, self::MAX_TITLE_LENGTH);
    }

    /**
     * The GTIN a shopper's search matches on.
     *
     * The variant's barcode wins over the product's: a catalogue entry can carry
     * one for its default while a specific SKU carries its own, and the more
     * specific of the two is the one that identifies what is actually shipped.
     */
    private function gtinFor(Product $product, ProductVariant $variant): ?string
    {
        foreach ([$variant->barcode, $product->gtin] as $candidate) {
            $value = trim((string) $candidate);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Google requires an absolute image URL and rejects a relative one.
     *
     * The media helper builds URLs from the public disk's configured `url`, which
     * IS absolute in production because it is derived from `APP_URL` — but this
     * feed is published to a third party, and "the disk happens to be configured
     * right on this environment" is a weak thing to publish on. Prefixing here
     * makes the guarantee local to the one place that needs it.
     */
    private function absolute(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return (string) config('feed.google.storefront_url').'/'.ltrim($url, '/');
    }

    private function linkFor(Product $product): string
    {
        // Flat slug, no `/urun/` prefix (ADR-059): the storefront serves a product
        // at the root and the canonical tag says so, and a feed link that redirects
        // is a feed link Google reports as a redirect.
        return (string) config('feed.google.storefront_url').'/'.$product->slug;
    }

    /**
     * "Cilt Bakımı > Nemlendiriciler" — our own taxonomy, as free text.
     */
    private function breadcrumbFor(Product $product): string
    {
        $names = [];
        $id = $product->category_id;

        while ($id !== null && isset($this->categories[$id])) {
            array_unshift($names, $this->categories[$id]['name']);
            $id = $this->categories[$id]['parent_id'];
        }

        return implode(' > ', $names);
    }

    private function isExcluded(Product $product): bool
    {
        $excluded = (array) config('feed.google.excluded_category_slugs', []);

        if ($excluded === []) {
            return false;
        }

        $id = $product->category_id;

        // EXCLUDING A CATEGORY EXCLUDES ITS DESCENDANTS. A policy strike lands on
        // "supplements", not on the leaf somebody filed a product under, and an
        // owner adding a slug means the branch.
        while ($id !== null && isset($this->categories[$id])) {
            if (in_array($this->categories[$id]['slug'], $excluded, true)) {
                return true;
            }

            $id = $this->categories[$id]['parent_id'];
        }

        return false;
    }

    /**
     * The whole tree, once per run.
     *
     * Five hundred-odd rows, against twenty thousand products whose breadcrumb
     * would otherwise be a walk up the parent chain per row. Loading it here is
     * also what keeps the product query free of a recursive `category.parent…`
     * eager load that strict mode would still make N-deep.
     */
    private function loadCategoryTree(): void
    {
        $this->categories = Category::query()
            ->get(['id', 'parent_id', 'name_tr', 'name_en', 'slug'])
            ->keyBy('id')
            ->map(static fn (Category $category): array => [
                'name' => $category->localized('name'),
                'slug' => (string) $category->slug,
                'parent_id' => $category->parent_id,
            ])
            ->all();
    }

    /**
     * Minor units to the decimal string Google parses. Integer arithmetic only —
     * `129.90`, never `129.9` and never a float (ADR-005).
     */
    private function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) abs($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * HTML out, whitespace collapsed.
     *
     * A description arrives from the admin editor as markup; Google wants plain
     * text and treats stray tags as a quality problem. `html_entity_decode` runs
     * BEFORE stripping so `&lt;p&gt;` written as an entity does not survive as
     * visible angle brackets — and after this the value is escaped again on its
     * way into the XML.
     */
    private function plainText(string $value): string
    {
        $text = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function tag(string $name, string $value, int $indent = 4): string
    {
        return str_repeat(' ', $indent).'<'.$name.'>'.$this->escape($value).'</'.$name.'>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function header(): string
    {
        $title = $this->escape((string) config('app.name'));
        $link = $this->escape((string) config('feed.google.storefront_url'));

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">'."\n"
            .'<channel>'."\n"
            .'  <title>'.$title.'</title>'."\n"
            .'  <link>'.$link.'</link>'."\n"
            .'  <description>'.$title.' ürün akışı</description>'."\n";
    }

    private function footer(): string
    {
        return '</channel>'."\n".'</rss>'."\n";
    }
}
