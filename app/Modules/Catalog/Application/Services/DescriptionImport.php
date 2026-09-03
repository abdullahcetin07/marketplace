<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Application\Actions\UpdateProductAction;
use App\Modules\Catalog\Domain\DTOs\UpdateProductDTO;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;

/**
 * Puts APPROVED product copy into the catalogue, one GTIN at a time
 * (BUILD_PRODUCT_DESCRIPTION_ENRICHMENT.md).
 *
 * **THIS IS THE INGESTION HALF, AND IT IS DELIBERATELY THE ONLY HALF.** The work
 * order describes a pipeline that gathers facts from manufacturer sites and
 * writes original copy from them. Gathering and writing are not things this
 * application can do: it has no LLM client, and adding one is an external
 * dependency with a cost and a decision behind it (ADR-003). What it CAN do —
 * and what nothing else was doing — is take copy a human has approved and land
 * it in the catalogue without bypassing the moderation lifecycle, without
 * overwriting somebody's edit, and without publishing a health claim.
 *
 * **IT DRIVES `UpdateProductAction` AND WRITES NO MODEL** — the ADR-074/076/088
 * rule. A `->update(['description_tr' => …])` would set the column and skip the
 * events, so the row would read correctly in the admin table and be stale in
 * search, the storefront and both feeds.
 *
 * **EVERY TEXT IS SCANNED FOR A HEALTH CLAIM BEFORE IT LANDS.** The patterns are
 * ADR-088's (`config/product_descriptions.forbidden_claims`) — claim-SHAPED, so
 * the mandatory supplement disclaimer ("hastalıkların tedavisinde kullanılmaz")
 * passes while the assertion it negates does not. A human approving copy is not
 * a substitute for the scan: the whole reason the list exists is that a
 * confident sentence reads fine until a regulator reads it.
 *
 * **IT REFUSES TO OVERWRITE ANYTHING THAT IS NOT THE GENERATED TEMPLATE.** After
 * ADR-088 every product has a description, so "is it empty?" no longer answers
 * "would I be destroying somebody's work?". The template's own sentence shape is
 * the marker; anything else needs `--force` and says so per row.
 */
final class DescriptionImport
{
    /**
     * The sentence ADR-088's generator always writes.
     *
     * `"{title}, {category} kategorisinde yer alan bir {noun}."` — recognisable,
     * and nothing a person writing product copy would produce by accident.
     */
    private const TEMPLATE_MARKER = 'kategorisinde yer alan bir';

    public function __construct(private readonly UpdateProductAction $update) {}

    /**
     * @param array<int, array{gtin: string, title: string, body: string}> $entries
     *
     * @return array{
     *     total: int,
     *     written: int,
     *     skipped_identical: int,
     *     skipped_hand_written: int,
     *     blocked_claim: int,
     *     not_found: int,
     *     rows: array<int, array{gtin: string, title: string, status: string, detail: string}>,
     * }
     */
    public function apply(array $entries, bool $write, bool $force = false): array
    {
        $report = [
            'total' => count($entries),
            'written' => 0,
            'skipped_identical' => 0,
            'skipped_hand_written' => 0,
            'blocked_claim' => 0,
            'not_found' => 0,
            'rows' => [],
        ];

        foreach ($entries as $entry) {
            $product = $this->findByGtin($entry['gtin']);

            if ($product === null) {
                $report['not_found']++;
                $report['rows'][] = $this->row($entry, 'bulunamadı', 'bu GTIN ile yayınlanmış ürün yok');

                continue;
            }

            $claim = $this->claimIn($entry['body']);

            if ($claim !== null) {
                $report['blocked_claim']++;
                $report['rows'][] = $this->row($entry, 'sağlık beyanı', $claim);

                continue;
            }

            $current = (string) $product->description_tr;

            if (trim($current) === trim($entry['body'])) {
                $report['skipped_identical']++;
                $report['rows'][] = $this->row($entry, 'aynı', 'metin zaten bu');

                continue;
            }

            if (! $force && $current !== '' && ! str_contains($current, self::TEMPLATE_MARKER)) {
                $report['skipped_hand_written']++;
                $report['rows'][] = $this->row($entry, 'elle yazılmış', 'şablon değil — --force gerekir');

                continue;
            }

            if ($write) {
                $this->update->run($product, new UpdateProductDTO(
                    description: ['tr' => $entry['body']],
                    present: ['description'],
                ));
            }

            $report['written']++;
            $report['rows'][] = $this->row($entry, $write ? 'yazıldı' : 'yazılacak', $product->uuid);
        }

        return $report;
    }

    /**
     * The GTIN may be the product's or one of its variants'.
     *
     * The catalogue carries both: `products.gtin` is the dedup key the importer
     * writes (ADR-074) and a variant's barcode is what is on the box. Approved
     * copy arrives with whichever number the person had in front of them.
     */
    private function findByGtin(string $gtin): ?Product
    {
        $gtin = trim($gtin);

        if ($gtin === '') {
            return null;
        }

        $product = Product::query()->where('gtin', $gtin)->first();

        if ($product !== null) {
            return $product;
        }

        $variant = ProductVariant::query()->where('barcode', $gtin)->first();

        return $variant?->product;
    }

    /** The first forbidden pattern the text trips, or null. */
    private function claimIn(string $text): ?string
    {
        /** @var array<int, string> $patterns */
        $patterns = (array) config('product_descriptions.forbidden_claims', []);

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                return (string) ($matches[0] ?? $pattern);
            }
        }

        return null;
    }

    /**
     * @param array{gtin: string, title: string, body: string} $entry
     *
     * @return array{gtin: string, title: string, status: string, detail: string}
     */
    private function row(array $entry, string $status, string $detail): array
    {
        return [
            'gtin' => $entry['gtin'],
            'title' => $entry['title'],
            'status' => $status,
            'detail' => $detail,
        ];
    }
}
