<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Store\Domain\DTOs\UpdateStoreBrandingDTO;
use App\Modules\Store\Domain\Models\Store;
use App\Modules\Store\Domain\Models\StoreBranding;

/**
 * Update a storefront's theme fields (§2.3). PATCH via present list. The
 * logo/banner/favicon media are handled by the media endpoints, not here.
 */
final class UpdateStoreBrandingAction extends BaseAction
{
    public function handle(mixed ...$arguments): StoreBranding
    {
        /** @var Store $store */
        $store = $arguments[0];
        /** @var UpdateStoreBrandingDTO $data */
        $data = $arguments[1];

        $branding = $store->branding()->firstOrCreate([]);

        $changes = [];

        if ($data->has('primary_color')) {
            $changes['primary_color'] = $data->primaryColor;
        }

        if ($data->has('accent_color')) {
            $changes['accent_color'] = $data->accentColor;
        }

        if ($data->has('theme')) {
            $changes['theme'] = $data->theme;
        }

        if ($changes !== []) {
            $branding->update($changes);
        }

        return $branding->refresh();
    }
}
