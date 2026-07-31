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
    'lines' => 'Sipariş kalemleri',

    'section' => [
        'summary' => 'Sipariş özeti',
        'shipping' => 'Teslimat ve fatura adresi',
        'cancellation' => 'İptal',
    ],

    'field' => [
        'number' => 'Sipariş no',
        'status' => 'Durum',
        'placed_at' => 'Tarih',
        'line_count' => 'Kalem',
        'items_total' => 'Ürün toplamı',
        'tax_total' => 'KDV',
        /*
        | Fiyatlar KDV DAHİL (ADR-042). Bu not olmazsa satıcı iki sayıyı toplar ve
        | müşterinin ödediğinden fazlasını görür.
        */
        'tax_total_hint' => 'Ürün toplamının içindedir; üzerine eklenmez.',
        'grand_total' => 'Genel toplam',
        'billing_address' => 'Fatura adresi',
        'cancelled_at' => 'İptal tarihi',
        'cancelled_by' => 'İptal eden',
        'reason' => 'Gerekçe',
        'customer' => 'Müşteri',
        'seller' => 'Satıcı',
        'checkout_group' => 'Sepet no',
        'checkout_group_hint' => 'Aynı sepetten çıkan tüm siparişleri bulmak için bunu aramaya yapıştırın.',
    ],

    'line' => [
        'product' => 'Ürün',
        'quantity' => 'Adet',
        'unit_price' => 'Birim fiyat',
        'tax_rate' => 'KDV oranı',
        'tax' => 'KDV tutarı',
        'total' => 'Satır toplamı',
    ],

    'action' => [
        'cancel' => 'Siparişi iptal et',
        'cancel_confirm' => 'Sipariş iptal edilecek ve ayrılan stok serbest bırakılacak. Bu işlem geri alınamaz.',
        /*
        | SATICIYA UYARI (ADR-057). İptal yalnızca siparişi durdurmuyor: satıcı
        | "karşılayamıyorum" dediği için o varyantın stoğu SIFIRLANIYOR. Sonradan
        | keşfedilecek bir sürpriz olmasın diye onay ekranı bunu açıkça söylüyor.
        */
        'cancel_confirm_seller' => 'Sipariş iptal edilecek ve bu ürün için STOĞUNUZ SIFIRLANACAK — yeniden stok girene kadar satışa çıkmaz. Bu işlem geri alınamaz.',
        'cancel_confirm_button' => 'İptal et ve stoğu sıfırla',
        'cancel_reason_hint' => 'Müşteriye gösterilir. "Stokta kalmadı" gibi kısa ve açık bir gerekçe yazın.',
        'zero_seller_stock' => 'Satıcı kaynaklı: stoğu da sıfırla',
        'zero_seller_stock_hint' => 'Satıcının o üründe gerçekten stoğu yoksa işaretleyin. Varsayılan olarak yalnızca ayrılan stok serbest bırakılır ve ürün satışta kalır.',
    ],

    /*
    | Kim iptal etti (ADR-057). Dört farklı iş olayı aynı satırla bitiyor; satıcı
    | bildirimi, dolandırıcılık sinyali ve terk edilme metriği bunları ayırt
    | etmek zorunda.
    */
    'cancelled_by' => [
        'customer' => 'Müşteri',
        'seller' => 'Satıcı',
        'admin' => 'Yönetici',
        'expiry' => 'Süre doldu',
    ],

    'notice' => [
        'cancelled' => 'Sipariş iptal edildi.',
        'stock_zeroed' => 'Bu ürün için stoğunuz sıfırlandı. Satışa devam etmek için teklif formundan yeni adet girin.',
    ],

    'empty' => [
        'heading' => 'Henüz sipariş yok',
        'description' => 'Bir müşteri tekliflerinizden birini satın aldığında siparişi burada görünür.',
    ],

    'errors' => [
        'already_cancelled' => 'Bu sipariş zaten iptal edilmiş.',
        'not_cancellable' => 'Bu sipariş artık iptal edilemez.',
    ],

    /*
    | İptal gerekçeleri. `expired` otomatik: müşteri sekmeyi kapattığında stoğu
    | geri veren süpürme işi bunu yazar (§3.3).
    */
    'cancel' => [
        'expired' => 'Ödeme adımı zaman aşımına uğradı; ayrılan stok serbest bırakıldı.',
    ],
];
