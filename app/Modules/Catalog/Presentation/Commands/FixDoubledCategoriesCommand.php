<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Commands;

use App\Modules\Catalog\Application\Actions\DeleteCategoryAction;
use App\Modules\Catalog\Application\Actions\UpdateCategoryAction;
use App\Modules\Catalog\Application\Actions\UpdateProductAction;
use App\Modules\Catalog\Domain\DTOs\UpdateCategoryDTO;
use App\Modules\Catalog\Domain\DTOs\UpdateProductDTO;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Console\Command;
use Throwable;

/**
 * Repairs the categories the bulk import (ADR-074) created with their own name
 * welded to itself — `magnezyum-bisglisinatmagnezyum-bisglisinat` — at the ROOT
 * of the tree, beside the correctly-named category they duplicate.
 *
 * **It is a MERGE, not a rename.** Every one of them has a twin already sitting
 * in the right branch, so renaming would leave two categories meaning the same
 * thing; the products move to the twin and the impostor is deleted. Nine of
 * them held 512 products between them, and their presence at the root is what
 * a shopper saw in the menu and what forced the Merchant feed to name four
 * slugs by hand.
 *
 * **IT DRIVES THE AUTHORING ACTIONS AND WRITES NO MODEL** — the ADR-074/076
 * rule, and here it is load-bearing twice over: `UpdateProductAction` re-checks
 * ADR-047 before moving a product and, because the model is saved rather than
 * mass-updated, Scout hears it and the search index follows. A query-builder
 * `update(['category_id' => …])` would leave every moved product correct in the
 * table and stale in search, the storefront and the feed.
 *
 * **A twin that does not accept products stops the merge for that category.**
 * `accepts_products = false` is a human decision (ADR-047) and this command has
 * no business overruling it to tidy a slug — it reports and moves on.
 *
 * Reports by default; `--apply` is the only thing that writes. Running it twice
 * changes nothing the second time, which is the property that makes it safe to
 * put in a deploy.
 */
final class FixDoubledCategoriesCommand extends Command
{
    /**
     * Roots that are not doubled but are still in the wrong place.
     *
     * `takviye-edici-gida-urunleri` is the supplement aisle the import left at
     * the top of the tree; it is re-PARENTED rather than merged, because there
     * is no twin to merge into and its products cannot go up to
     * `besin-takviyeleri` — that node is `accepts_products = false`, a curator's
     * decision this command will not overrule.
     *
     * @var array<string, string>
     */
    private const MISPLACED_ROOTS = [
        'takviye-edici-gida-urunleri' => 'besin-takviyeleri',
    ];

    protected $signature = 'catalog:fix-doubled-categories
                            {--apply : Merge and delete (default is report only)}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Merge the import-doubled root categories into their real twins';

    public function handle(
        UpdateProductAction $updateProduct,
        DeleteCategoryAction $deleteCategory,
        UpdateCategoryAction $updateCategory,
    ): int {
        $plan = $this->plan();
        $moves = $this->misplacedPlan();

        $this->report($plan, $moves);

        if ($plan === [] && $moves === []) {
            $this->info('Nothing to fix.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('Report only — nothing was written. Re-run with --apply.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Merge these categories?', false)) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $failures = 0;

        foreach ($plan as $entry) {
            if ($entry['twin'] === null || ! $entry['twin']->acceptsProducts()) {
                $failures++;

                continue;
            }

            try {
                $this->merge($entry['broken'], $entry['twin'], $updateProduct, $deleteCategory);
                $this->line("  merged {$entry['broken']->slug} → {$entry['twin']->slug}");
            } catch (Throwable $e) {
                $failures++;
                $this->error("  {$entry['broken']->slug}: ".$e->getMessage());
            }
        }

        foreach ($moves as $move) {
            try {
                $updateCategory->run($move['category'], new UpdateCategoryDTO(
                    parentUuid: $move['parent']->uuid,
                    present: ['parentUuid'],
                ));
                $this->line("  moved {$move['category']->slug} under {$move['parent']->slug}");
            } catch (Throwable $e) {
                $failures++;
                $this->error("  {$move['category']->slug}: ".$e->getMessage());
            }
        }

        $this->newLine();
        $this->info('Done.');

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The half of a slug that is repeated to make it, or null if it is not.
     *
     * `magnezyum-bisglisinatmagnezyum-bisglisinat` → `magnezyum-bisglisinat`.
     */
    public static function undouble(string $slug): ?string
    {
        $length = mb_strlen($slug);

        if ($length === 0 || $length % 2 !== 0) {
            return null;
        }

        $half = mb_substr($slug, 0, intdiv($length, 2));

        return $half.$half === $slug ? $half : null;
    }

    /**
     * Every doubled category, with the twin it should merge into.
     *
     * @return array<int, array{broken: Category, twin: Category|null, products: int}>
     */
    private function plan(): array
    {
        $plan = [];

        foreach (Category::query()->orderBy('id')->get() as $category) {
            $half = self::undouble((string) $category->slug);

            if ($half === null) {
                continue;
            }

            $twin = Category::query()->where('slug', $half)->first();

            $plan[] = [
                'broken' => $category,
                'twin' => $twin?->is($category) === true ? null : $twin,
                'products' => $category->products()->count(),
            ];
        }

        return $plan;
    }

    /**
     * @return array<int, array{category: Category, parent: Category}>
     */
    private function misplacedPlan(): array
    {
        $moves = [];

        foreach (self::MISPLACED_ROOTS as $slug => $parentSlug) {
            $category = Category::query()->where('slug', $slug)->first();
            $parent = Category::query()->where('slug', $parentSlug)->first();

            // Already moved — the idempotence that makes a second run a no-op.
            if ($category === null || $parent === null || $category->parent_id === $parent->getKey()) {
                continue;
            }

            $moves[] = ['category' => $category, 'parent' => $parent];
        }

        return $moves;
    }

    private function merge(
        Category $broken,
        Category $twin,
        UpdateProductAction $updateProduct,
        DeleteCategoryAction $deleteCategory,
    ): void {
        /*
        | One product at a time, through the action. Slower than an UPDATE and
        | that is the trade being made: the action is what re-checks ADR-047 and
        | what saves the MODEL, which is what Scout listens to.
        */
        $broken->products()->orderBy('id')->each(function (Product $product) use ($twin, $updateProduct): void {
            $updateProduct->run($product, new UpdateProductDTO(
                categoryUuid: $twin->uuid,
                present: ['categoryUuid'],
            ));
        });

        // Refuses if anything is left — the guard, not a formality.
        $deleteCategory->run($broken->fresh());
    }

    /**
     * @param array<int, array{broken: Category, twin: Category|null, products: int}> $plan
     * @param array<int, array{category: Category, parent: Category}> $moves
     */
    private function report(array $plan, array $moves): void
    {
        if ($plan !== []) {
            $this->table(
                ['doubled slug', 'products', 'merges into', 'blocked'],
                array_map(static fn (array $entry): array => [
                    $entry['broken']->slug,
                    $entry['products'],
                    $entry['twin']->slug ?? '— no twin —',
                    match (true) {
                        $entry['twin'] === null => 'no twin, skipped',
                        ! $entry['twin']->acceptsProducts() => 'twin is closed (ADR-047), skipped',
                        default => '',
                    },
                ], $plan),
            );
        }

        foreach ($moves as $move) {
            $this->line("misplaced root: {$move['category']->slug} → under {$move['parent']->slug}");
        }
    }
}
