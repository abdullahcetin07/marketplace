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

    'tokens' => [
        'title' => 'API Anahtarları',
        'subheading' => 'Kendi sisteminizden toplu fiyat/stok göndermek için anahtar oluşturun.',
        'create' => 'Yeni anahtar',
        'name' => 'Anahtar adı',
        'name_hint' => 'Hangi sistem kullanacak? Örn. "ERP", "Stok robotu".',
        'created' => 'Anahtar oluşturuldu — bu değeri şimdi kopyalayın, bir daha gösterilmeyecek:',
        'revoke' => 'İptal et',
        'revoked' => 'Anahtar iptal edildi',
        'existing' => 'Mevcut anahtarlar',
        'empty' => 'Henüz anahtar oluşturmadınız.',
        'last_used' => 'Son kullanım',
        'never_used' => 'hiç kullanılmadı',
        'how_heading' => 'Nasıl kullanılır',
        'note' => [
            'once' => 'Anahtar YALNIZCA bir kez gösterilir; sistem yalnızca özetini saklar. Kaybederseniz yenisini oluşturup eskisini iptal edin.',
            'scope' => 'Anahtar sizin adınıza çalışır ve yalnızca KENDİ mağazanızın tekliflerini yazabilir.',
            'endpoints' => 'Uç noktalar: /sync (fiyat+stok), /stock (yalnızca stok), /withdraw (satıştan kaldır).',
        ],
    ],

    'feed' => [
        'batch_too_large' => 'Tek seferde en fazla :max ürün gönderebilirsiniz; listeyi bölün.',
    ],

    'field' => [
        'product' => 'Ürün',
        'product_hint' => 'Yayındaki katalogda arayın. Ürün yoksa önce "ürün aç" akışını kullanın.',
        'variant' => 'Varyant',
        'variant_hint' => 'Sattığınız tam varyant (renk, beden…). Fiyat ve stok bu varyanta aittir.',
        'store' => 'Mağaza',
        'store_hint' => 'Teklifin görüneceği mağaza. Yalnızca aktif mağazalarınız listelenir.',
        'seller' => 'Satıcı',
        'price' => 'Fiyat',
        'price_hint' => 'KDV dâhil, alıcının ödediği tutar.',
        'list_price' => 'Piyasa fiyatı',
        'list_price_hint' => 'İsteğe bağlı. Üstü çizili gösterilir; satış fiyatından düşük olamaz.',
        'stock' => 'Stok',
        'stock_hint' => 'Elinizdeki adet. 0 girerseniz teklif "tükendi" olur; fiyatınız ve sıranız korunur.',
        /*
        | Yazdığınız sayı stoğun ÜSTÜNE EKLENMEZ, yerine geçer (ADR-048). Beşten
        | üçünü satmış bir satıcı "tamamlayayım" diye 5 yazarsa elinde olmayan
        | stoğu geri açmış olur; bunu ancak karşılayamadığı siparişte fark eder.
        */
        'stock_hint_live' => 'Şu an satılabilir: :available. Buraya yazdığınız sayı mevcut stoğun yerine geçer, üstüne eklenmez.',
        'available' => 'Satılabilir',
        'declared' => 'Beyan edilen',
        'status' => 'Durum',
        'listed_at' => 'Yayına alındı',
        'buy_box_rank' => 'Buy-box sırası',
        'buy_box_price' => 'Buy-box fiyatı',
        'suspended_at' => 'Askıya alınma',
        'status_before' => 'Önceki durum',
        'reason' => 'Gerekçe',
        'reason_hint' => 'Denetim kaydına yazılır.',
        'suspend_reason_hint' => 'Zorunlu. Satıcıya ve denetim kaydına gerekçe olarak geçer.',
    ],

    /*
    | ADR-057 — satıcı karşılayamadığı bir siparişi iptal ettiğinde stoğu
    | sıfırlanır ve denetim kaydına bu gerekçe yazılır. Olmasaydı satıcının kendi
    | kaydında kimsenin yapmadığı bir düzenleme görünürdü.
    */
    'stock' => [
        'zeroed_by_seller_cancellation' => ':order numaralı sipariş karşılanamadığı için iptal edildi; bu ürün için stok sıfırlandı.',
        // Teklif listesindeki canlı sütun 0 gösterdiğinde. "0" değil "Tükendi",
        // çünkü satıcının okuması gereken şey sayı değil durum.
        'sold_out' => 'Tükendi',
    ],

    /*
    | Buy box her okumada hesaplanır, saklanmaz (ADR-045). Satıcının bu sayfada
    | aradığı iki bilgi: kaçıncıyım ve neyi geçmem gerekiyor.
    */
    'buy_box' => [
        'rank_of' => ':rank / :total',
        'you_are_winning' => 'Buy-box sizde',
    ],

    'section' => [
        'listing' => 'Teklif',
        'suspension' => 'Askıya alma kaydı',
    ],

    'create' => [
        'what' => 'Ne satıyorsunuz?',
        'what_hint' => 'Ürün paylaşılan katalogdan seçilir — kendi kopyanızı oluşturmazsınız.',
        'terms' => 'Fiyat ve stok',
    ],

    'action' => [
        'create' => 'Katalogdan seç & sat',
        'pause' => 'Duraklat',
        'pause_confirm' => 'Teklif satıştan kalkar ama silinmez; fiyatınız ve sıranız korunur.',
        'resume' => 'Yeniden yayınla',
        'withdraw' => 'Yayından kaldır',
        'withdraw_confirm' => 'Teklif kalıcı olarak kaldırılır. Aynı varyantı daha sonra yeniden listeleyebilirsiniz.',
        'suspend' => 'Askıya al',
        'suspend_confirm' => 'Teklif her yerden kaldırılır. Satıcı kendi kaldıramaz; yalnızca yönetici geri alabilir.',
        'reinstate' => 'Askıyı kaldır',
        'reinstate_confirm' => 'Teklif askıdan önceki durumuna döner — otomatik olarak yayına alınmaz.',
    ],

    'notice' => [
        'paused' => 'Teklif duraklatıldı.',
        'resumed' => 'Teklif yeniden yayında.',
        'withdrawn' => 'Teklif yayından kaldırıldı.',
        'suspended' => 'Teklif askıya alındı.',
        'reinstated' => 'Teklifin askısı kaldırıldı.',
    ],

    'empty' => [
        'heading' => 'Henüz teklifiniz yok',
        'description' => 'Katalogdan bir ürün seçip fiyat ve stok girin; teklifiniz anında yayına girer.',
    ],

    /*
    | Ürün yaşam döngüsü kaynaklı otomatik geçişlerin denetim gerekçesi (§3.5).
    | Satıcı "listem neden durdu?" diye sorduğunda izde bunu görür.
    */
    'cascade' => [
        'product_archived' => 'Ürün katalogdan kaldırıldığı için teklif otomatik olarak duraklatıldı.',
        'product_republished' => 'Ürün yeniden yayınlandığı için teklif otomatik olarak yeniden açıldı.',
    ],
];
