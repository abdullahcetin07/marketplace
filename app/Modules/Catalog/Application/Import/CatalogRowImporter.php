<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Import;

use App\Modules\Catalog\Application\Actions\DraftProductAction;
use App\Modules\Catalog\Application\Actions\PublishProductAction;
use App\Modules\Catalog\Application\Actions\SubmitProductForReviewAction;
use App\Modules\Catalog\Application\Actions\UpsertVariantAction;
use App\Modules\Catalog\Application\Jobs\AttachImportedProductImages;
use App\Modules\Catalog\Domain\Contracts\ProductRepositoryContract;
use App\Modules\Catalog\Domain\DTOs\DraftProductDTO;
use App\Modules\Catalog\Domain\DTOs\ModerationDecisionDTO;
use App\Modules\Catalog\Domain\DTOs\UpsertVariantDTO;
use App\Modules\Catalog\Domain\Exceptions\CatalogImportException;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\TaxRate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One spreadsheet row → a published product (ADR-074).
 *
 * **IT DRIVES THE AUTHORING ACTIONS AND WRITES NO MODEL ITSELF**, which is the
 * ADR's central instruction and the reason this is a service rather than a
 * `Product::create` loop. Bypassing the actions would skip the moderation
 * lifecycle, the slug registry, the GTIN guard, `combination_key`, and every event
 * other modules consume — an imported product would look right in the table and be
 * invisible to search, the storefront and Offer.
 *
 * **A BAD ROW THROWS AND THE BATCH CARRIES ON.** An admin uploading four thousand
 * products will have typos; the one outcome nobody wants is the good rows rolled
 * back with them. Every refusal here is a `CatalogImportException` carrying a
 * Turkish sentence a human can act on, which Filament writes to
 * `failed_import_rows`.
 *
 * **IDEMPOTENT ON GTIN.** Re-uploading the same file updates rather than
 * duplicates — the property that makes a correction workflow possible at all:
 * fix three cells, re-upload the whole sheet.
 *
 * @see docs/modules/Catalog.md — bulk import
 */
final class CatalogRowImporter
{
    /**
     * Images that may be fetched. **GIF IS DELIBERATELY ABSENT** — animated
     * product photography is not a thing this catalogue wants, and Spatie's
     * conversions on an animated gif produce a still frame nobody chose.
     *
     * @var array<int, string>
     */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif'];

    public function __construct(
        private readonly CatalogTaxonomyResolver $taxonomy,
        private readonly ProductRepositoryContract $products,
        private readonly DraftProductAction $draftProduct,
        private readonly UpsertVariantAction $upsertVariant,
        private readonly SubmitProductForReviewAction $submitForReview,
        private readonly PublishProductAction $publish,
    ) {}

    /**
     * @param array<string, string|null> $row keyed by the mapped columns
     */
    public function import(array $row, int $adminId): Product
    {
        $title = $this->text($row['baslik'] ?? null);
        $path = $this->text($row['kategori_yolu'] ?? null);

        if ($title === null) {
            throw CatalogImportException::missingColumn('baslik');
        }

        if ($path === null) {
            throw CatalogImportException::missingColumn('kategori_yolu');
        }

        $category = $this->taxonomy->categoryByPath($path);
        $brand = $this->taxonomy->brand($row['marka'] ?? null);
        $taxRate = $this->taxonomy->taxRate($row['kdv'] ?? null);
        $gtin = $this->text($row['gtin'] ?? null);
        $description = $this->text($row['aciklama'] ?? null) ?? '';

        $existing = $gtin === null ? null : $this->products->findByGtin($gtin);

        if ($existing !== null) {
            /*
            | **THE SAME GTIN IS THE SAME PRODUCT, NOT A SECOND ONE.** This is what
            | makes a correction pass possible: fix three cells in the sheet and
            | re-upload the whole thing. Without it the second upload would throw
            | `gtinAlreadyInCatalog` on every row that worked the first time.
            |
            | IT DOES NOT RE-PUBLISH OR TOUCH VARIANTS. The product already went
            | through the lifecycle; an update is a correction to its labels, not a
            | new moderation event.
            */
            return $this->update($existing, $title, $description, $category, $brand, $taxRate, $row);
        }

        /*
        | **A RENEWED BARCODE IS THE SAME PRODUCT, AND ITS ROW IS SKIPPED**
        | (owner's call, 2026-08-13). A supplier re-barcodes an item and the sheet
        | carries both, so `Bioderma Photoderm LEB SPF30 100 ml` arrives twice as
        | `3701129808047` and `...48`. The GTIN dedup above cannot see it — the
        | barcodes genuinely differ — and the two rows then collide on one slug.
        |
        | **CHECKED HERE AND CAUGHT BELOW, BECAUSE THE COLLISION HAS TWO SHAPES.**
        | `SlugRegistry::issue()` already appends `-2`, but it decides against the
        | registry BEFORE the product row exists; two chunks running the same title
        | concurrently both get the clean slug and the second insert dies on
        | `products_slug_unique`. This check answers the settled case — the twin is
        | already committed — and the catch answers the raced one.
        |
        | Either way it must not reach Filament as a database exception. That is
        | what happened for a day: an `UniqueConstraintViolationException` escaping
        | this method cannot be recorded as a row failure, so Filament wrote the row
        | with an EMPTY reason and failed the whole JOB — the queue re-ran the chunk
        | and every innocent row beside it was reported failed too. 117 blank
        | failures out of 78 real collisions.
        */
        $clash = $this->products->findBySlug(Str::slug($title));

        if ($clash !== null) {
            throw CatalogImportException::titleAlreadyInCatalog($title, $clash->gtin, $gtin);
        }

        try {
            return $this->createProduct($title, $description, $category, $brand, $taxRate, $gtin, $row, $adminId);
        } catch (UniqueConstraintViolationException) {
            // THE RACE, LOST. Another chunk registered this title's slug between
            // the check above and this insert; the twin exists, so the row is the
            // same skip with the same sentence.
            throw CatalogImportException::titleAlreadyInCatalog(
                $title,
                $this->products->findBySlug(Str::slug($title))?->gtin,
                $gtin,
            );
        }
    }

    /**
     * The draft, its default variant, its images and the full moderation
     * lifecycle. @see `create()` for why the slug collision around it lives there.
     *
     * @param array<string, string|null> $row
     */
    private function createProduct(
        string $title,
        string $description,
        Category $category,
        ?Brand $brand,
        TaxRate $taxRate,
        ?string $gtin,
        array $row,
        int $adminId,
    ): Product {
        $product = $this->draftProduct->run(new DraftProductDTO(
            categoryUuid: $category->uuid,
            title: ['tr' => $title],
            description: ['tr' => $description],
            brandUuid: $brand?->uuid,
            taxRateUuid: $taxRate->uuid,
            gtin: $gtin,
            /*
            | **NULL PROVENANCE, AND THAT IS CORRECT FOR AN ADMIN IMPORT.**
            | `proposed_by_*` records which SELLER proposed a product — provenance,
            | not ownership (ADR-040). Nobody proposed these; the platform entered
            | them, and stamping the admin's own organization would invent a
            | merchant relationship that does not exist.
            */
            proposedByOrgId: null,
            proposedByOrgUuid: null,
            proposedByUserId: null,
        ));

        // EMPTY `valueUuids` IS THE SINGLE DEFAULT VARIANT (v1). Colour/size axes
        // need the moderated category attribute schema and are phase 2 — a
        // spreadsheet cell must not silently create schema (ADR-038).
        $this->upsertVariant->run($product, new UpsertVariantDTO(isDefault: true));

        $this->attachImages($product, $row['gorsel_url'] ?? null);

        /*
        | THROUGH THE FULL LIFECYCLE, NOT AROUND IT. `SubmitProductForReviewAction`
        | is what enforces "≥1 variant and a tax bracket", and `PublishProductAction`
        | is what checks the category's required attributes. An importer that set
        | `status = published` directly would be asserting both without checking
        | either.
        */
        $this->submitForReview->run($product);

        return $this->publish->run($product, new ModerationDecisionDTO(moderatedBy: $adminId));
    }

    /**
     * An existing product, corrected.
     *
     * **THE LABELS AND THE FILING, NOT THE LIFECYCLE.** A re-upload fixes a typo
     * in a title or moves a product to the right category; it does not re-open
     * moderation, re-create variants or re-publish something an admin may have
     * deliberately suspended.
     *
     * @param array<string, string|null> $row
     */
    private function update(
        Product $product,
        string $title,
        string $description,
        Category $category,
        ?Brand $brand,
        TaxRate $taxRate,
        array $row,
    ): Product {
        $product->forceFill([
            'title_tr' => $title,
            'description_tr' => $description,
            'category_id' => $category->getKey(),
            'brand_id' => $brand?->getKey(),
            'tax_rate_id' => $taxRate->getKey(),
        ])->save();

        // The "only when there are none" guard moved INTO `attachImages()`, so
        // both call sites carry it and the queued job re-checks besides.
        $this->attachImages($product, $row['gorsel_url'] ?? null);

        return $product->refresh();
    }

    /**
     * Pipe-separated URLs → a job on the `media` queue.
     *
     * **THE FETCH LEFT THIS CLASS BECAUSE IT WAS KILLING THE IMPORT.** Filament
     * sends 100 rows per chunk and each row carries ~1.6 urls, so downloading
     * here meant ~160 sequential HTTP round trips inside a job the worker kills
     * at 120 seconds. It never finished: 385 `MaxAttemptsExceeded` failures in
     * seventeen minutes, and a 21,205-row file that reported exactly 2,000
     * successes — the twenty chunks that happened to fit.
     *
     * So the row's own work is now database work, which is bounded and fast, and
     * the network work is queued one job per product where ten minutes are
     * allowed. @see `AttachImportedProductImages` for the whole diagnosis.
     *
     * **THE URL IS STILL JUDGED HERE**, before anything is queued: a cell holding
     * a PDF should not cost a job to discover, and the log entry belongs with the
     * row that carried the bad cell.
     *
     * **NOTHING IS QUEUED WHEN THE PRODUCT ALREADY HAS PHOTOS.** Re-running an
     * import must not stack a fourth copy of the same picture; the job re-checks
     * as well, since it runs later and a retry could arrive after the fact.
     */
    private function attachImages(Product $product, ?string $urls): void
    {
        if ($product->getMedia('images')->isNotEmpty()) {
            return;
        }

        $list = [];

        foreach (array_filter(array_map(trim(...), explode('|', (string) $urls))) as $url) {
            if (! $this->looksLikeImage($url)) {
                Log::channel('errors')->info('Skipped a non-image url during a catalogue import', [
                    'product_uuid' => $product->uuid,
                    'url' => $url,
                ]);

                continue;
            }

            $list[] = $url;
        }

        if ($list === []) {
            return;
        }

        AttachImportedProductImages::dispatch((int) $product->getKey(), $list);
    }

    /**
     * Judged by extension, before a byte is fetched — the cheap check that keeps
     * an animated gif or a PDF out without a round trip.
     */
    private function looksLikeImage(string $url): bool
    {
        $extension = mb_strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return in_array($extension, self::IMAGE_EXTENSIONS, true);
    }

    /**
     * A cell, trimmed — and empty means ABSENT rather than "".
     */
    private function text(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
