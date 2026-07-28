<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Listeners;

use App\Modules\Catalog\Domain\Contracts\ProductRepositoryContract;
use App\Modules\Catalog\Domain\Events\ProductArchived;
use App\Modules\Catalog\Domain\Events\ProductPublished;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Events\Dispatcher;

/**
 * Keeps the search index in step with the moderation lifecycle (§10).
 *
 * INDEX ON PUBLISHED, DROP ON ARCHIVED. Those are the only two transitions that
 * change whether a product should be findable, which is why this subscribes to
 * exactly two events rather than observing every save.
 *
 * DRIVEN BY EVENTS, NOT BY THE MODEL'S OBSERVER. Scout's automatic syncing fires
 * on every save — including a moderator's `moderated_at` write on a REJECTION,
 * which would put a refused product through the indexing pipeline for nothing.
 * The events fire after commit (`BaseAction::after()`), so nothing is ever
 * indexed from a transaction that rolled back.
 *
 * A FAILED INDEX MUST NOT UNDO A PUBLICATION. Indexing is queued
 * (`SCOUT_QUEUE=true`) onto the `search` queue, so a cluster that is down delays
 * discoverability instead of failing the moderator's approval — and
 * `SearchIndexingFailed` is reportable, because a product that exists but cannot
 * be found is silent data loss from the customer's view (docs/search.md).
 *
 * The relations `toSearchableArray()` reads are eager-loaded here: strict mode
 * makes a lazy load throw, and this runs outside any repository.
 *
 * @see App\Modules\Catalog\Domain\Models\Product::toSearchableArray()
 */
final class SyncProductSearchIndex
{
    public function __construct(private readonly ProductRepositoryContract $products) {}

    public function onPublished(ProductPublished $event): void
    {
        $this->loadForIndexing($event->productUuid)?->searchable();
    }

    public function onArchived(ProductArchived $event): void
    {
        // No eager loading needed to REMOVE a document — the engine deletes by
        // key, and loading the graph for a product nobody will see again is
        // work for its own sake.
        $this->products->findByUuid($event->productUuid)?->unsearchable();
    }

    /**
     * Register the listeners.
     *
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            ProductPublished::class => 'onPublished',
            ProductArchived::class => 'onArchived',
        ];
    }

    private function loadForIndexing(string $productUuid): ?Product
    {
        $product = $this->products->findByUuid($productUuid);

        return $product?->load($product->searchRelations());
    }
}
