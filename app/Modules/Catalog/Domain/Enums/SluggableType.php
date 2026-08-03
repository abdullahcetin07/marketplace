<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Database\Eloquent\Model;

/**
 * What a public slug points at (ADR-059).
 *
 * AN ENUM RATHER THAN A MORPH MAP, and rather than the lookup table the sibling
 * rule would suggest, because adding a kind is unavoidably code: a fourth type
 * needs a resolver branch, a page in the storefront and a backfill. That is the
 * project's own test for enum-vs-table (CLAUDE.md), applied.
 *
 * ITS VALUE IS THE PUBLIC WIRE FORMAT — `{"type": "product"}` — not a class name.
 * `App\Modules\Catalog\Domain\Models\Product` in a payload would leak the
 * application's internal shape to every storefront and would break the day the
 * class moves, which is exactly what Laravel's morph maps exist to avoid.
 *
 * No `Enum` suffix (ADR-007).
 *
 * @see App\Modules\Catalog\Domain\Models\Slug
 */
enum SluggableType: string
{
    case Product = 'product';

    case Category = 'category';

    case Brand = 'brand';

    /**
     * The model class this kind addresses.
     *
     * The one place the mapping lives, so the resolver, the registry and the
     * backfill cannot disagree about what "brand" means.
     *
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Product => Product::class,
            self::Category => Category::class,
            self::Brand => Brand::class,
        };
    }

    /**
     * The order the backfill and any bulk re-issue claim slugs in.
     *
     * CATEGORIES FIRST, THEN BRANDS, THEN PRODUCTS — deliberately, because the
     * flat namespace means the three compete for the same names and somebody has
     * to lose. A category is the most navigational URL and the fewest in number;
     * a product title is the longest and the least likely to collide. So a
     * collision costs a numeric suffix on the row that matters least.
     *
     * @return array<int, self>
     */
    public static function claimOrder(): array
    {
        return [self::Category, self::Brand, self::Product];
    }
}
