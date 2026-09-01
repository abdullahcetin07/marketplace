<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

/**
 * Which shopping platform a product feed is being built for.
 *
 * **THE TWO FEEDS DIFFER IN ONE FIELD, AND IT IS THE IDENTIFIER.** Google
 * matches on the variant — a size and a colour are separate things to buy — so
 * `g:id` is the variant uuid there. Meta matches its catalogue against what the
 * Pixel sends, and the Pixel sends a PRODUCT uuid: `view_item` fires on a
 * product page where no variant has been chosen yet, and the buy box picks the
 * offer. Feeding Meta variant ids would leave every dynamic ad unable to find
 * the thing the shopper just looked at.
 *
 * Everything else — the exclusions, the KDV-inclusive price, the images, the
 * breadcrumb, the zero shipping — is deliberately identical. A second feed that
 * drifted from the first would be a second policy surface to keep approved.
 */
enum FeedTarget: string
{
    case Google = 'google';

    case Meta = 'meta';

    /** Where the built file lands, per target. */
    public function pathConfigKey(): string
    {
        return match ($this) {
            self::Google => 'feed.google.path',
            self::Meta => 'feed.meta.path',
        };
    }

    public function defaultPath(): string
    {
        return match ($this) {
            self::Google => 'feeds/google-merchant.xml',
            self::Meta => 'feeds/meta-catalog.xml',
        };
    }

    /**
     * Whether a row is identified by its variant.
     *
     * Google: yes. Meta: no — see the class docblock.
     */
    public function identifiesByVariant(): bool
    {
        return $this === self::Google;
    }

    /**
     * Whether sibling variants are grouped with `g:item_group_id`.
     *
     * Only meaningful when rows ARE variants. On a product-level feed the group
     * id would equal the row's own id, which says nothing and reads as an error.
     */
    public function emitsItemGroup(): bool
    {
        return $this === self::Google;
    }
}
