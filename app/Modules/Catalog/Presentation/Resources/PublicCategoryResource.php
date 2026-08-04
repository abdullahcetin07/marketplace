<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A category as the storefront's menu and category page see it (ADR-059).
 *
 * ONE RESOURCE FOR THE TREE AND FOR THE PAGE, because a node is a node: the tree
 * carries `children` recursively and the page carries a `path` as well. Rendering
 * whichever keys the query produced beats two classes that must be kept saying the
 * same thing about the same row.
 *
 * `id` IS THE UUID and `slug` is the address (#7, ADR-059). Both are public keys
 * and both are here on purpose: the uuid is what the browse filter and every
 * other API call are keyed on, while the slug is what the URL reads. A client that
 * only had the slug would have to resolve it again to filter.
 *
 * `product_count` IS OF SELLABLE PRODUCTS. A menu that promises 48 and opens on an
 * empty listing is worse than a menu with no numbers — see `PublicTaxonomyBrowse`.
 */
final class PublicCategoryResource extends JsonResource
{
    /**
     * @param array<string, mixed> $node
     */
    public function __construct(private readonly array $node)
    {
        parent::__construct($node);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = [
            'id' => $this->node['uuid'],
            'name' => $this->node['name'],
            'slug' => $this->node['slug'],
            'product_count' => $this->node['product_count'],
        ];

        // The keys below exist on one shape or the other, never both. Emitting a
        // null `path` on every one of a hundred menu nodes would be pure weight.
        if (array_key_exists('parent_uuid', $this->node)) {
            $payload['parent_id'] = $this->node['parent_uuid'];
        }

        if (array_key_exists('path', $this->node)) {
            $payload['path'] = array_map(
                static fn (array $crumb): array => [
                    'id' => $crumb['uuid'],
                    'name' => $crumb['name'],
                    'slug' => $crumb['slug'],
                ],
                $this->node['path'],
            );
        }

        if (array_key_exists('children', $this->node)) {
            $payload['children'] = array_map(
                static fn (array $child): array => (new self($child))->toArray($request),
                $this->node['children'],
            );
        }

        return $payload;
    }
}
