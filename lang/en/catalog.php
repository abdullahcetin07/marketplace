<?php

declare(strict_types=1);

/*
| Catalog module UI strings — the English fallback (config/app.php).
|
| Copy only. The catalog's own CONTENT lives in per-locale columns on the rows
| themselves (Catalog.md §13.5).
*/

return [

    'category' => [
        'singular' => 'Category',
        'plural' => 'Categories',
        'name' => 'Name',
        'parent' => 'Parent category',
        'parent_none' => 'Top level (no parent)',
        'slug' => 'Slug',
        'slug_hint' => 'Derived from the name if left blank. Changing it changes the public address.',
        'path' => 'Path',
        'depth' => 'Depth',
        'position' => 'Order',
        'is_active' => 'Active',
        'is_leaf' => 'No sub-categories',
        // ADR-047 — whether products may attach is this flag now, not the
        // tree's shape. A flagged category may still have children.
        'accepts_products' => 'Accepts products',
        'accepts_products_hint' => 'When on, products can be filed directly here — even if this category has sub-categories.',
        'accepts_products_locked' => 'This category holds products; move them elsewhere before turning it off.',
        'products_count' => 'Products',
        'attributes' => 'Attribute schema',
        'empty' => [
            'heading' => 'No categories yet',
            'description' => 'Create the catalog\'s first branch. Products attach to leaf categories only.',
        ],
        'action' => [
            'archive' => 'Archive',
            'archive_confirm' => 'The category is deactivated, not deleted. Existing products are unaffected.',
            'delete' => 'Delete',
            'delete_confirm' => 'This category is removed for good. Only a category with no products and no sub-categories can be deleted.',
        ],
        'notify' => [
            'archived' => 'Category archived.',
            'deleted' => 'Category deleted.',
        ],
    ],

    'attribute' => [
        'singular' => 'Attribute',
        'plural' => 'Attributes',
        'code' => 'Code',
        'code_hint' => 'The stable machine handle (e.g. colour). Labels can be re-worded; this cannot.',
        'name' => 'Label',
        'type' => 'Type',
        'is_required' => 'Required',
        'is_required_hint' => 'Required in this category. Checked at publish, not at draft.',
        'is_variant_defining' => 'Defines variants',
        'is_variant_defining_hint' => 'Only a select attribute can be a variant axis — a cartesian product needs a finite set.',
        'is_filterable' => 'Filterable',
        'is_active' => 'Active',
        'position' => 'Order',
        'values' => 'Values',
        'values_count' => 'Values',
        'value' => 'Value',
        'value_hint' => 'The stable machine handle (e.g. red).',
        'label' => 'Label',
        'empty' => [
            'heading' => 'No attributes yet',
            'description' => 'Attributes like Colour and Size bind to categories and define their variants.',
        ],
    ],

    'brand' => [
        'singular' => 'Brand',
        'plural' => 'Brands',
        'name' => 'Name',
        'slug' => 'Slug',
        'is_active' => 'Active',
        'logo' => 'Logo',
        'logo_hint' => 'Optional. A square logo on a transparent background works best. 2 MB maximum.',
        'empty' => [
            'heading' => 'No brands yet',
            'description' => 'Sellers pick a brand, never invent one — two spellings split every brand filter.',
        ],
    ],

    'product' => [
        'singular' => 'Product',
        'plural' => 'Products',
        'open' => 'Open a product',
        'title' => 'Product name',
        'description' => 'Description',
        'category' => 'Category',
        'category_hint' => 'Leaf categories only — the attribute schema comes from there.',
        'brand' => 'Brand',
        'brand_none' => 'Unbranded',
        'gtin' => 'Barcode (GTIN)',
        'gtin_hint' => 'Enter it if there is one: it stops the same product being opened twice.',
        'slug' => 'Slug',
        'status' => 'Status',
        'organization' => 'Organization',
        'organization_hint' => 'The product is proposed on this company\'s behalf. Once approved it joins the shared catalog.',
        'proposed_by' => 'Proposed by',
        'submitted_at' => 'Submitted',
        'published_at' => 'Published',
        'moderated_at' => 'Reviewed',
        'moderation_reason' => 'Reason',
        'images' => 'Images',
        'attributes' => 'Attributes',
        'variants' => 'Variants',
        'variants_count' => 'Variants',

        'shared_notice' => 'The catalog is shared: an approved product belongs to the platform and other sellers may sell it too. There is no price and no stock here — those are an offer.',

        'empty' => [
            'heading' => 'You have not proposed any products yet',
            'description' => 'Open a product that is not in the catalog. It goes live once a category manager approves it.',
            'queue_heading' => 'The queue is empty',
            'queue_description' => 'No products are waiting for review.',
        ],

        'action' => [
            'submit' => 'Submit for review',
            'submit_confirm' => 'The product goes to a category manager. You cannot edit it after submitting.',
            'publish' => 'Approve and publish',
            'publish_confirm' => 'The product joins the shared catalog.',
            'reject' => 'Reject',
            'request_revision' => 'Request a revision',
            'archive' => 'Delist',
            'archive_confirm' => 'The product is removed from listings but not deleted.',
            'generate_variants' => 'Generate variants',
            'add_variant' => 'Add a variant',
        ],

        'reason' => 'Reason',
        'reason_reject_hint' => 'The seller reads this. Say plainly why it cannot be accepted.',
        'reason_revision_hint' => 'The seller reads this, fixes it and re-submits. Say what to fix.',

        'notify' => [
            'drafted' => 'Product draft created.',
            'updated' => 'Product updated.',
            'submitted' => 'Product submitted for review.',
            'published' => 'Product published.',
            'rejected' => 'Product rejected.',
            'revision_requested' => 'Revision requested.',
            'archived' => 'Product delisted.',
            'variants_generated' => ':count variants generated.',
            'failed' => 'That could not be completed.',
        ],
    ],

    'variant' => [
        'singular' => 'Variant',
        'plural' => 'Variants',
        'sku' => 'SKU',
        'sku_hint' => 'Generated automatically if left blank.',
        'barcode' => 'Barcode',
        'combination' => 'Combination',
        'is_default' => 'Default',
        'position' => 'Order',
        'axes' => 'Variant axes',
        'axes_hint' => 'Every combination of the values you pick is generated. Delete the ones you do not stock.',
        'no_axes' => 'This category defines no variant axes — a single default variant is created.',
        'empty' => [
            'heading' => 'No variants yet',
            'description' => 'Every product needs at least one — the variant is the unit that gets sold.',
        ],
    ],

    'moderation' => [
        'queue' => 'Product review queue',
        'queue_singular' => 'Product awaiting review',
    ],

];
