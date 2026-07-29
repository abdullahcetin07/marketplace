<?php

declare(strict_types=1);

/*
| Teklif modülü dizeleri. Yalnızca sunum ve denetim gerekçeleri — davranış
| Offer Action'larında yaşar.
|
| @see docs/modules/Offer.md
*/

return [
    'singular' => 'Teklif',
    'plural' => 'Teklifler',

    /*
    | Ürün yaşam döngüsü kaynaklı otomatik geçişlerin denetim gerekçesi (§3.5).
    | Satıcı "listem neden durdu?" diye sorduğunda izde bunu görür.
    */
    'cascade' => [
        'product_archived' => 'Ürün katalogdan kaldırıldığı için teklif otomatik olarak duraklatıldı.',
        'product_republished' => 'Ürün yeniden yayınlandığı için teklif otomatik olarak yeniden açıldı.',
    ],
];
