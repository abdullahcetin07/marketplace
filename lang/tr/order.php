<?php

declare(strict_types=1);

/*
| Sipariş modülü dizeleri. Yalnızca sunum ve kayıt gerekçeleri — davranış Order
| Action'larında yaşar.
|
| @see docs/modules/Order.md
*/

return [
    'singular' => 'Sipariş',
    'plural' => 'Siparişler',

    /*
    | İptal gerekçeleri. `expired` otomatik: müşteri sekmeyi kapattığında stoğu
    | geri veren süpürme işi bunu yazar (§3.3).
    */
    'cancel' => [
        'expired' => 'Ödeme adımı zaman aşımına uğradı; ayrılan stok serbest bırakıldı.',
    ],
];
