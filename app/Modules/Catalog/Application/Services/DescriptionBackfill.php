<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Application\Actions\UpdateProductAction;
use App\Modules\Catalog\Domain\DTOs\UpdateProductDTO;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;

/**
 * Fills empty product descriptions from category templates (ADR-088).
 *
 * **IT DRIVES `UpdateProductAction`; IT NEVER WRITES THE MODEL.** This is the
 * load-bearing rule the bulk catalogue importer (ADR-074) and the seller offer
 * feed (ADR-076) were both built on, and the reason is the same each time: a
 * `->update(['description_tr' => …])` writes the column and skips the Scout sync
 * the model's `Searchable` trait performs on save. The row would be right in the
 * table and stale in search — right in the admin list, wrong to every shopper
 * using the search box. The action costs a little more and is the only version
 * that is actually finished.
 *
 * **ONLY EMPTY, NEVER OVERWRITE.** The command exists to fill a hole. Once real
 * copy starts arriving — a supplier feed, an editor, the GTIN content source this
 * defers to — a run that ignored that would flatten it in bulk with no undo.
 * Which makes the whole thing idempotent for free: a second run finds nothing to
 * do.
 *
 * **THE MODERATION LIFECYCLE IS NOT TOUCHED.** These products are published and
 * stay published; `UpdateProductAction` carries no status field precisely so a
 * content edit cannot move one (§3.1). Sending seven thousand live products back
 * through review to add a sentence would take the catalogue offline.
 *
 * Chunked, because the catalogue is ~20k rows and the sweep holds one chunk of
 * models at a time.
 */
final class DescriptionBackfill
{
    /**
     * @var array<int, array{name: string, slug: string, parent_id: int|null}>
     */
    private array $categories = [];

    public function __construct(
        private readonly ProductDescriptionTemplate $template,
        private readonly UpdateProductAction $update,
    ) {}

    /**
     * @return array{
     *     considered: int,
     *     filled: int,
     *     skipped_has_description: int,
     *     skipped_undescribable: int,
     * }
     */
    public function run(int $chunkSize = 200, ?int $limit = null, bool $dryRun = false): array
    {
        $this->loadCategoryTree();

        $report = [
            'considered' => 0,
            'filled' => 0,
            'skipped_has_description' => 0,
            'skipped_undescribable' => 0,
        ];

        $onlyEmpty = (bool) config('product_descriptions.only_empty', true);

        /*
        | EVERY PUBLISHED PRODUCT, NOT ONLY THE SELLABLE ONES.
        |
        | The work order scoped this to `is_sellable` because those are the rows
        | the feed needs — but that flag is a CACHE of current commerce state
        | (ADR-079), rebuilt every ten minutes from stock and store status. Gating
        | a one-off content backfill on it makes the result depend on WHEN it ran:
        | a product out of stock this afternoon gets no description, comes back in
        | stock next week, and is silently dropped from the feed until somebody
        | remembers to re-run this.
        |
        | A description is a catalogue fact; sellability is a commerce fact. The
        | superset costs one pass over ~20k rows instead of ~7k and removes the
        | timing dependency entirely.
        */
        Product::query()
            ->where('status', ProductStatus::Published->value)
            ->with(['brand', 'category'])
            ->orderBy('id')
            ->chunkById(max(1, $chunkSize), function ($products) use (&$report, $onlyEmpty, $limit, $dryRun): bool {
                foreach ($products as $product) {
                    if ($limit !== null && $report['filled'] >= $limit) {
                        return false;
                    }

                    $report['considered']++;

                    if ($onlyEmpty && trim($product->localized('description')) !== '') {
                        $report['skipped_has_description']++;

                        continue;
                    }

                    $description = $this->template->for(
                        $product,
                        $this->pathFor($product->category_id),
                        $this->rootSlugFor($product->category_id),
                    );

                    /*
                    | NULL MEANS "NOTHING TRUTHFUL TO SAY" — no title, or no
                    | category. It is counted rather than filled with a generic
                    | sentence: a description that describes nothing is exactly
                    | what Google rejects, and it would also hide the row from
                    | whoever has to fix it.
                    */
                    if ($description === null) {
                        $report['skipped_undescribable']++;

                        continue;
                    }

                    if (! $dryRun) {
                        $this->update->run($product, new UpdateProductDTO(
                            description: ['tr' => $description],
                            present: ['description'],
                        ));
                    }

                    $report['filled']++;
                }

                return true;
            });

        return $report;
    }

    /**
     * Category names from the root down, for the product's own category.
     *
     * @return array<int, string>
     */
    private function pathFor(?int $categoryId): array
    {
        $names = [];
        $id = $categoryId;

        while ($id !== null && isset($this->categories[$id])) {
            array_unshift($names, $this->categories[$id]['name']);
            $id = $this->categories[$id]['parent_id'];
        }

        return $names;
    }

    private function rootSlugFor(?int $categoryId): ?string
    {
        $id = $categoryId;
        $rootSlug = null;

        while ($id !== null && isset($this->categories[$id])) {
            $rootSlug = $this->categories[$id]['slug'];
            $id = $this->categories[$id]['parent_id'];
        }

        return $rootSlug;
    }

    /**
     * The whole tree, once per run.
     *
     * Five hundred-odd rows against twenty thousand products whose family would
     * otherwise be a walk up the parent chain per row — and a recursive
     * `category.parent.parent…` eager load that strict mode would still make
     * N-deep.
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
}
