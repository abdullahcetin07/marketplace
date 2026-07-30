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

    'section' => [
        'pool' => 'Stok durumu',
    ],

    /*
    | ÜÇ SAYININ AÇIKLAMASI, ipuçlarıyla birlikte. "Elde 10, ayrılmış 3,
    | satılabilir 7" cümlesi bu modülün var oluş sebebi; satıcı bu üçlüyü
    | anlamadan "sitede yok yazıyor ama depomda var" sorusunu çözemez.
    */
    'field' => [
        'product' => 'Ürün',
        'variant' => 'Varyant',
        'seller' => 'Satıcı',
        'on_hand' => 'Elde',
        'on_hand_hint' => 'Teklif formunda girdiğiniz adet.',
        'reserved' => 'Ayrılmış',
        'reserved_hint' => 'Devam eden siparişler için tutulan adet.',
        'available' => 'Satılabilir',
        'available_hint' => 'Elde − ayrılmış. Vitrinde satılabilen adet budur.',
        'low_stock_threshold' => 'Kritik stok seviyesi',
        'low_stock_threshold_hint' => 'Satılabilir adet bu sayıya düştüğünde uyarılırsınız. Boş bırakırsanız uyarı gönderilmez.',
        'no_threshold' => 'Uyarı yok',
        'updated_at' => 'Son değişiklik',
    ],

    'filter' => [
        'low_stock' => 'Kritik seviyede',
        'out_of_stock' => 'Tükendi',
        'has_reservations' => 'Ayrılmış adedi olanlar',
    ],

    'action' => [
        'set_threshold' => 'Kritik seviye belirle',
    ],

    'notice' => [
        'threshold_set' => 'Kritik stok seviyesi güncellendi.',
    ],

    'empty' => [
        'heading' => 'Henüz stok kaydı yok',
        'description' => 'Bir ürün için teklif açtığınızda stok kaydınız burada görünür.',
    ],

    'errors' => [
        'stock_is_not_edited' => 'Stok buradan düzenlenmez. Adedi teklif formundan değiştirin.',
    ],

    /*
    | Hareket defterine yazılan otomatik gerekçeler. Satıcı "stoğum neden
    | değişti?" diye sorduğunda geçmişte bunu okur.
    */
    'movement' => [
        'plural' => 'Stok hareketleri',
        'empty' => 'Henüz stok hareketi yok',
        'at' => 'Tarih',
        'type' => 'Hareket',
        'on_hand_delta' => 'Elde değişimi',
        'reserved_delta' => 'Ayrılmış değişimi',
        'reference' => 'Referans',
        'note' => 'Gerekçe',
        'mirrored_from_offer' => 'Teklif formunda girilen stok bilgisinden güncellendi.',
        'offer_withdrawn' => 'Teklif yayından kaldırıldığı için stok sıfırlandı.',
    ],
];
