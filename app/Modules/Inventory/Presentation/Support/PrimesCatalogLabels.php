<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Support;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolve a whole page of catalogue labels in one round trip.
 *
 * **`CatalogLabels` HAS ALWAYS DOCUMENTED THIS AND NOTHING EVER DID IT**
 * (2026-09-24). Its note says `prime()` "collapses that to one query when the
 * caller can hand over the whole page's uuids up front, which the resources
 * below do" — they did not. No caller primed, and `app(CatalogLabels::class)`
 * was unbound, so every cell built a FRESH instance whose memo was read once and
 * thrown away: two queries per row, on tables that exist to be scanned.
 *
 * Both halves are needed and neither works alone. The container binding makes
 * one instance serve the page; this makes that instance know the page before the
 * first cell asks.
 *
 * `getTableRecords()` is Filament's own accessor and caches its result, so
 * calling `parent` here costs nothing and the rows are the real ones — filtered,
 * sorted and paginated exactly as rendered.
 */
trait PrimesCatalogLabels
{
    /**
     * @return Collection<int, Model>|CursorPaginator<int, Model>|Paginator<int, Model>
     */
    public function getTableRecords(): Collection|CursorPaginator|Paginator
    {
        $records = parent::getTableRecords();

        $products = [];
        $variants = [];

        foreach ($records as $record) {
            $product = $record->getAttribute('product_uuid');
            $variant = $record->getAttribute('variant_uuid');

            if (is_string($product) && $product !== '') {
                $products[] = $product;
            }

            if (is_string($variant) && $variant !== '') {
                $variants[] = $variant;
            }
        }

        if ($products !== [] || $variants !== []) {
            app(CatalogLabels::class)->prime(
                array_values(array_unique($products)),
                array_values(array_unique($variants)),
            );
        }

        return $records;
    }
}
