<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Store\Domain\DTOs\UpdateStoreSeoDTO;
use App\Modules\Store\Domain\Models\Store;
use App\Modules\Store\Domain\Models\StoreSeo;

/**
 * Update a storefront's SEO metadata (§2.4). PATCH via the DTO's present list.
 */
final class UpdateStoreSeoAction extends BaseAction
{
    public function handle(mixed ...$arguments): StoreSeo
    {
        /** @var Store $store */
        $store = $arguments[0];
        /** @var UpdateStoreSeoDTO $data */
        $data = $arguments[1];

        $seo = $store->seo()->firstOrCreate([]);

        $changes = [];

        if ($data->has('meta_title')) {
            $changes['meta_title'] = $data->metaTitle;
        }

        if ($data->has('meta_description')) {
            $changes['meta_description'] = $data->metaDescription;
        }

        if ($data->has('meta_keywords')) {
            $changes['meta_keywords'] = $data->metaKeywords;
        }

        if ($data->has('canonical_url')) {
            $changes['canonical_url'] = $data->canonicalUrl;
        }

        if ($data->has('robots')) {
            $changes['robots'] = $data->robots;
        }

        if ($changes !== []) {
            $seo->update($changes);
        }

        return $seo->refresh();
    }
}
