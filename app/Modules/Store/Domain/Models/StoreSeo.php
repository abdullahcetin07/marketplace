<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A storefront's SEO metadata (§2.4).
 *
 * One row per store (HasOne). `robots` defaults to `index,follow`; the public
 * surface overrides it to `noindex` for a non-live store (§5) so a paused or
 * draft page is not indexed. `meta_keywords` is a jsonb list.
 *
 * @property int $id
 * @property int $store_id
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property array<int, string>|null $meta_keywords
 * @property string|null $canonical_url
 * @property string $robots
 *
 * @see docs/modules/Store.md §2.4
 */
final class StoreSeo extends Model
{
    protected $table = 'store_seo';

    protected $fillable = [
        'store_id',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'canonical_url',
        'robots',
    ];

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta_keywords' => 'array',
        ];
    }
}
