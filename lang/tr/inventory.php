<?php

declare(strict_types=1);

/*
| Stok modülü dizeleri. Yalnızca sunum ve denetim gerekçeleri — davranış
| Inventory Action'larında yaşar.
|
| @see docs/modules/Inventory.md
*/

return [
    'singular' => 'Stok',
    'plural' => 'Stok',

    /*
    | Hareket defterine yazılan otomatik gerekçeler. Satıcı "stoğum neden
    | değişti?" diye sorduğunda geçmişte bunu okur.
    */
    'movement' => [
        'mirrored_from_offer' => 'Teklif formunda girilen stok bilgisinden güncellendi.',
        'offer_withdrawn' => 'Teklif yayından kaldırıldığı için stok sıfırlandı.',
    ],
];
