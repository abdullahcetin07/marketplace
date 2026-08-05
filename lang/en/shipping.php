<?php

declare(strict_types=1);

/*
| Shipping module strings. Presentation and refusal reasons only — behaviour
| lives in the Shipping actions.
|
| @see docs/modules/Shipping.md
*/

return [
    'singular' => 'Shipment',
    'plural' => 'Shipments',

    'errors' => [
        'not_found' => 'No such shipment.',
        'not_awaiting_handover' => 'This order has already been handed to a carrier.',
        'carrier_unavailable' => 'That carrier is not available.',
        'seller_cannot_deliver' => 'A seller cannot mark a shipment delivered; the buyer confirms it or the transit window does.',
        'never_deleted' => 'A shipment is never deleted.',
    ],

    'shipment' => [
        'order_number' => 'Order no',
        'status_label' => 'Status',
        'carrier' => 'Carrier',
        'tracking_number' => 'Tracking number',
        'tracking_number_hint' => 'The number the carrier gave you. The buyer tracks the parcel with it.',
        'shipped_at' => 'Shipped',
        'delivered_at' => 'Delivered',
        'delivered_via' => 'Delivery source',
        'seller' => 'Seller',
        'ship' => 'Mark shipped',
        'ship_confirm' => 'Enter the carrier and the tracking number. It cannot be changed afterwards — contact support if you get it wrong.',
        'shipped' => 'The order was marked shipped.',
        'empty' => 'Nothing to ship.',
        'empty_hint' => 'An order appears here once its payment is collected.',
    ],

    'cargo' => [
        'singular' => 'Carrier',
        'plural' => 'Carriers',
        'code' => 'Code',
        'code_hint' => 'Immutable machine code — set once, never edited.',
        'name' => 'Name',
        'tracking_url_template' => 'Tracking URL template',
        'tracking_url_hint' => 'Put {tracking_number} where the number goes. Leave empty if the carrier has no public tracking page.',
        'is_active' => 'Active',
        'sort_order' => 'Order',
        'never_deleted' => 'Carriers are never deleted; deactivate them.',
    ],
];
