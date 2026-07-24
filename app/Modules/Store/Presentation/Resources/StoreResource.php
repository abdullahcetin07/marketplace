<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Http\Request;

/**
 * The private (seller / admin) view of a store — the management contract, distinct
 * from the public `PublicStoreResource`.
 *
 * Exposes what a manager or operator needs: the store's public id (UUID, never the
 * internal id), slug, name, number, operational status, its owning organization by
 * UUID (the cross-context reference, ADR-033 — never the internal id), locale, and
 * timestamps. Still no internal ids, settings internals or audit rows.
 *
 * @mixin Store
 *
 * @see docs/modules/Store.md §12
 */
final class StoreResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Store $store */
        $store = $this->resource;

        return [
            'id' => $this->publicId(),
            'organization_id' => $store->organization_uuid,
            'slug' => $store->slug,
            'name' => $store->name,
            'store_number' => $store->store_number,
            'status' => $store->status->value,
            'locale' => [
                'language' => $store->defaultLanguage?->code,
                'currency' => $store->defaultCurrency?->code,
                'timezone' => $store->timezone?->name,
            ],
            'activated_at' => $store->activated_at?->toIso8601String(),
            'suspended_at' => $store->suspended_at?->toIso8601String(),
            ...$this->timestamps(),
        ];
    }
}
