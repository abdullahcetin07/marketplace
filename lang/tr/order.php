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
        'cancel_reason_hint' => 'Müşteriye gösterilir. "Stokta kalmadı" gibi kısa ve açık bir gerekçe yazın.',
    ],

    'notice' => [
        'cancelled' => 'Sipariş iptal edildi.',
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
