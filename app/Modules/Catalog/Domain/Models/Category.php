<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Catalog\Domain\Concerns\HasLocalizedText;
use App\Shared\Traits\HasUuid;
use Database\Modules\Catalog\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node in the platform's category tree (ADR-038).
 *
 * OWNED BY THE CATEGORY MANAGER, never by a seller. The tree and each node's
 * attribute schema are what make search, filters and comparison possible; a
 * seller who could invent categories would produce a catalog nobody can facet.
 *
 * STORAGE — adjacency list + a materialised `path` (§13.1, ruled). Writes are a
 * single `parent_id` pointer; descendant reads are one prefix scan on `path`.
 * No tree package, no nested-set write complexity. The cost is that moving a
 * subtree rewrites the paths beneath it — rare, and bounded to that subtree.
 *
 * The path is a slash-delimited chain of INTERNAL IDS with both edges closed —
 * `/3/17/42/`. Ids, not slugs, because a rename must not invalidate the tree;
 * closed on both sides because `/1/` must not prefix-match `/17/`.
 *
 * ATTACHMENT IS A FLAG, NOT TREE SHAPE (ADR-047, §3.2): a product attaches where
 * `accepts_products` says so. The leaf rule it replaced made the taxonomy's
 * shape decide — adding one sub-category to *Makyaj* silently stopped *Makyaj*
 * holding products — which is exactly the case the owner needed to allow. The
 * cost is that "a category with children never holds products" is no longer
 * automatic; central curation, always the model under ADR-038, is now explicit.
 *
 * `is_active` rather than a status enum — a category is lookup-style reference
 * data (ADR-015). Deactivating is what §7's `CategoryArchived` means; nodes are
 * never hard-deleted out from under the products that reference them.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $parent_id
 * @property string $path
 * @property int $depth
 * @property string $name_tr
 * @property string|null $name_en
 * @property string $slug
 * @property bool $is_active
 * @property bool $accepts_products
 * @property int $position
 * @property-read Category|null $parent
 * @property-read Collection<int, Category> $children
 * @property-read Collection<int, Attribute> $attributes
 *
 * @see docs/modules/Catalog.md §2.1, §13.1
 */
final class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use HasLocalizedText;
    use HasUuid;

    /**
     * The separator that closes both ends of every path segment.
     */
    public const string PATH_SEPARATOR = '/';

    protected $table = 'categories';

    /**
     * Factories live under `database/Modules/Catalog/Factories`, not the default
     * `database/factories`, so the model names its own.
     */
    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }

    protected $fillable = [
        'parent_id',
        'path',
        'depth',
        'name_tr',
        'name_en',
        'slug',
        'is_active',
        'accepts_products',
        'position',
    ];

    /**
     * Attributes carried in per-locale columns (§13.5).
     *
     * @return array<int, string>
     */
    public static function localizedAttributes(): array
    {
        return ['name'];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /**
     * The category's attribute schema (§2.3). The pivot carries the PER-CATEGORY
     * overrides — the same attribute may be variant-defining in "Giyim" and
     * merely descriptive in "Mobilya", which is the whole reason the flags live
     * on the binding rather than on the attribute.
     *
     * NOT NAMED `attributes()`, for the same reason as
     * `Product::descriptiveAttributes()`: it would collide with Eloquent's own
     * `$attributes` property and break on property access.
     *
     * @return BelongsToMany<Attribute, $this>
     */
    public function schemaAttributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'category_attribute')
            ->withPivot(['is_required', 'is_variant_defining', 'is_filterable', 'position'])
            ->withTimestamps()
            ->orderBy('category_attribute.position');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Whether this node has no children.
     *
     * NO LONGER THE ATTACH RULE (ADR-047) — that is `accepts_products` now. This
     * survives because "has children" is still a real question the taxonomy
     * asks: a branch cannot be archived or deleted out from under its subtree
     * (§3.2), and the Category Manager's tree renders differently for a
     * container. Inactive children count, because reactivating a hidden child
     * must not change what was true while it was invisible.
     */
    public function isLeaf(): bool
    {
        return ! $this->children()->exists();
    }

    /**
     * Whether a product may attach here (ADR-047, §3.2).
     *
     * The flag, not the tree shape. A flagged category MAY have children — a
     * product sits at *Makyaj* while *Göz Makyajı* exists under it — which is
     * precisely what the leaf rule made impossible and what this replaced it
     * for.
     */
    public function acceptsProducts(): bool
    {
        return (bool) $this->accepts_products;
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * The path this node should carry, given its parent. The single definition
     * of the format — the migration, the actions and the tests all defer here
     * rather than each re-deriving the string.
     */
    public static function pathFor(?self $parent, int $id): string
    {
        $prefix = $parent === null ? self::PATH_SEPARATOR : $parent->path;

        return $prefix.$id.self::PATH_SEPARATOR;
    }

    public static function depthFor(?self $parent): int
    {
        return $parent === null ? 0 : $parent->depth + 1;
    }

    /**
     * The ancestor ids encoded in the path, root first, excluding this node.
     *
     * @return array<int, int>
     */
    public function ancestorIds(): array
    {
        $segments = explode(self::PATH_SEPARATOR, $this->path);
        $ids = array_values(array_filter(
            $segments,
            static fn (string $segment): bool => $segment !== '',
        ));

        return array_map('intval', array_slice($ids, 0, -1));
    }

    /**
     * Every node beneath this one, at any depth — the prefix scan the
     * materialised path exists for.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDescendantsOf(Builder $query, self $category): Builder
    {
        return $query
            ->where('path', 'like', $category->path.'%')
            ->whereKeyNot($category->getKey());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Leaves only — the categories a product may actually attach to.
     *
     * Expressed as "has no children" rather than a stored flag: a flag would
     * have to be maintained on every insert and move, and would be wrong for
     * exactly as long as nobody noticed.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLeaves(Builder $query): Builder
    {
        return $query->whereDoesntHave('children');
    }

    /**
     * Categories a product may be filed against (ADR-047).
     *
     * What every seller-facing picker offers. A plain column predicate rather
     * than `leaves()`'s `whereDoesntHave` subquery — the flag is indexed, and
     * the two now answer different questions.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAcceptsProducts(Builder $query): Builder
    {
        return $query->where('accepts_products', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'accepts_products' => 'boolean',
            'depth' => 'integer',
            'position' => 'integer',
        ];
    }
}
