<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Import;

use App\Modules\Catalog\Application\Actions\CreateBrandAction;
use App\Modules\Catalog\Application\Actions\CreateCategoryAction;
use App\Modules\Catalog\Domain\Contracts\BrandRepositoryContract;
use App\Modules\Catalog\Domain\DTOs\CreateBrandDTO;
use App\Modules\Catalog\Domain\DTOs\CreateCategoryDTO;
use App\Modules\Catalog\Domain\Exceptions\CatalogImportException;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\TaxRate;
use Illuminate\Support\Str;

/**
 * The three lookups a spreadsheet cell has to become before a product can exist:
 * a category path, a brand name, a KDV bracket (ADR-074).
 *
 * **SPLIT OUT OF `CatalogRowImporter` FOR THE DEPENDENCY LIMIT**, and the seam
 * turned out to be a real one rather than an arithmetic dodge: everything here
 * answers "what does this text mean in the catalogue?", while the importer answers
 * "what do I do with a row?". Seven constructor dependencies is the documented
 * ceiling (CLAUDE.md) and the importer was at nine.
 *
 * @see CatalogRowImporter
 */
final class CatalogTaxonomyResolver
{
    /**
     * The `>` in "Erkek > Ayakkabı > Spor". Spaces around it are optional.
     */
    private const PATH_SEPARATOR = '>';

    public function __construct(
        private readonly BrandRepositoryContract $brands,
        private readonly CreateCategoryAction $createCategory,
        private readonly CreateBrandAction $createBrand,
    ) {}

    /**
     * "Erkek > Ayakkabı > Spor Ayakkabı" → the leaf, creating what is missing.
     *
     * **RESOLVED BY NAME WITHIN A PARENT, NOT BY GLOBAL SLUG — a DEVIATION from
     * the work order**, which said to walk `findBySlug(Str::slug($segment))` per
     * segment. That is wrong for a tree and would corrupt the catalogue silently:
     * `categories.slug` is UNIQUE across the whole table, so once "Erkek >
     * Ayakkabı" exists, importing "Kadın > Ayakkabı" finds the MEN'S shoes
     * category by slug and files every women's shoe under it. Every row succeeds,
     * nothing is reported, and the tree is simply wrong.
     *
     * A path segment is only meaningful relative to its parent, so that is what is
     * matched.
     *
     * **THE LEAF MUST ACCEPT PRODUCTS** (ADR-047). Created leaves are made so; an
     * EXISTING category that does not is a failed row rather than a silent flip,
     * because `accepts_products` is a moderated decision about the shape of the
     * catalogue and a spreadsheet cell must not overturn one.
     */
    public function categoryByPath(string $path): Category
    {
        $segments = array_values(array_filter(
            array_map(trim(...), explode(self::PATH_SEPARATOR, $path)),
            static fn (string $segment): bool => $segment !== '',
        ));

        if ($segments === []) {
            throw CatalogImportException::missingColumn('kategori_yolu');
        }

        $parent = null;
        $last = count($segments) - 1;

        foreach ($segments as $index => $name) {
            $isLeaf = $index === $last;
            $found = $this->childByName($parent, $name);

            if ($found === null) {
                $found = $this->createCategory->run(new CreateCategoryDTO(
                    name: ['tr' => $name],
                    parentUuid: $parent?->uuid,
                    isActive: true,
                    // Only the last segment takes products; the ones above it are
                    // shelves, not shelves' contents.
                    acceptsProducts: $isLeaf,
                ));
            }

            if ($isLeaf && ! $found->acceptsProducts()) {
                throw CatalogImportException::categoryRejectsProducts($path, $name);
            }

            $parent = $found;
        }

        /** @var Category $parent */
        return $parent;
    }

    /**
     * The brand, reused by slug or created.
     *
     * **BY SLUG IS RIGHT HERE AND WRONG FOR CATEGORIES**, and the difference is
     * the tree: brands are a flat set, so "Nike" means one thing platform-wide.
     * `CreateBrandAction` does not dedup, so this must.
     */
    public function brand(?string $name): ?Brand
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return $this->brands->findBySlug(Str::slug($name))
            ?? $this->createBrand->run(new CreateBrandDTO(name: trim($name)));
    }

    /**
     * The KDV bracket for a cell that may say "20", "%20", "0,01" or nothing.
     *
     * **A BRACKET CLASSIFIES THE GOODS AND IS NOT NEGOTIABLE PER SELLER**
     * (ADR-055) — a book is %1 whoever sells it — so this resolves against the
     * managed `tax_rates` lookup and never creates one.
     *
     * AN UNREADABLE CELL FALLS BACK RATHER THAN FAILING THE ROW: getting a product
     * in at the standard bracket and correcting it later beats rejecting a
     * catalogue over a formatting choice, and the bracket is visible and editable
     * on the product afterwards.
     */
    public function taxRate(?string $value): TaxRate
    {
        $default = TaxRate::query()->where('code', 'kdv-20')->first()
            ?? TaxRate::query()->orderBy('id')->first();

        if ($default === null) {
            throw CatalogImportException::rowRejected('KDV oranları tanımlı değil; önce vergi oranlarını seed edin.');
        }

        $normalised = trim(str_replace(['%', ',', ' '], ['', '.', ''], (string) $value));

        if ($normalised === '') {
            return $default;
        }

        // "20" → the 0.2000 bracket; "0.20" → the same. Both spellings appear in
        // real spreadsheets and neither is wrong.
        $ratio = (float) $normalised;

        if ($ratio > 1) {
            $ratio /= 100;
        }

        return TaxRate::query()->where('rate', number_format($ratio, 4, '.', ''))->first()
            ?? TaxRate::query()->where('code', 'kdv-'.(int) round($ratio * 100))->first()
            ?? $default;
    }

    /**
     * One segment, matched among its siblings.
     *
     * CASE-INSENSITIVE ON THE TURKISH NAME because a spreadsheet is typed by a
     * human: "AYAKKABI" and "Ayakkabı" are the same shelf.
     *
     * **BOTH SIDES ARE FOLDED BY THE DATABASE, AND THE FIRST VERSION FOLDED ONE
     * SIDE IN PHP.** That shipped, and it created 1,000 copies of "Yetişkinler
     * İçin Güneş Kremleri" in a real catalogue before anybody noticed. The Turkish
     * dotted capital İ is why:
     *
     *     PHP   mb_strtolower('İÇİN')  →  'i̇çi̇n'   (i + U+0307 combining dot)
     *     pgsql lower('İÇİN')          →  'için'    (one character)
     *
     * Two spellings of the same word that never compare equal, so every imported
     * row believed its category did not exist yet and made another one. The
     * docblock above this method already said the comparison belongs to the
     * database — the code then did half of it in PHP, which is worse than doing
     * neither, because it looks careful.
     *
     * `LOWER(?)` on the parameter keeps ONE case-folding implementation in play:
     * whichever the column's own collation uses.
     */
    private function childByName(?Category $parent, string $name): ?Category
    {
        return Category::query()
            ->when(
                $parent === null,
                static fn ($query) => $query->whereNull('parent_id'),
                static fn ($query) => $query->where('parent_id', $parent?->getKey()),
            )
            ->whereRaw('LOWER(name_tr) = LOWER(?)', [$name])
            ->first();
    }
}
