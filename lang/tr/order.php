<?php

declare(strict_types=1);

/*
| Sipariş modülü dizeleri. Yalnızca sunum ve kayıt gerekçeleri — davranış Order
| Action'larında yaşar.
|
| @see docs/modules/Order.md
*/

return [

    'shipped' => [
        'subject' => ':number numaralı siparişiniz kargoya verildi',
        'greeting' => 'Siparişiniz yola çıktı!',
        'intro' => ':number numaralı siparişiniz :seller tarafından kargoya verildi.',
        'carrier' => 'Kargo firması: :carrier',
        'tracking' => 'Takip numarası: :number',
        'action_track' => 'Kargomu takip et',
        'action_orders' => 'Siparişlerimi görüntüle',
        'outro' => 'Takip bilgisi kargo firmasının sistemine düşene kadar birkaç saat geçebilir. Sorunuz olursa bu e-postayı yanıtlayabilirsiniz.',
    ],

    'confirmation' => [
        'subject' => 'Siparişiniz alındı',
        'greeting' => 'Siparişiniz için teşekkürler!',
        'intro' => 'Ödemeniz alındı ve siparişiniz satıcılara iletildi. Aşağıda ne aldığınızın dökümü var.',
        'order_line' => ':number · :seller',
        'order_total' => 'Bu siparişin tutarı: :total',
        'grand_total' => 'Toplam ödenen: :total',
        'shipping_to' => 'Teslimat adresi: :address',
        'action' => 'Siparişlerimi görüntüle',
        'outro' => 'Kargoya verildiğinde takip numarasıyla birlikte size tekrar yazacağız. Sorunuz olursa bu e-postayı yanıtlayabilirsiniz.',
        'unknown_seller' => 'Satıcı',
    ],
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

    'cancellation' => [
        'settled_by_cancellation' => 'Sipariş iptal edildiği için talep kapatıldı.',
        'singular' => 'İptal talebi',
        'plural' => 'İptal talepleri',
        'requested_at' => 'Talep tarihi',
        'buyer_reason' => 'Alıcının gerekçesi',
        'approve' => 'Onayla',
        'approve_confirm' => 'Siparişin tamamı alıcıya iade edilir, komisyon geri döner, stok size geri eklenir ve kargo kaydı kapatılır. Bu işlem geri alınamaz.',
        'approve_button' => 'Onayla ve iade et',
        'approved_notice' => 'İptal onaylandı',
        'approved_body' => 'Alıcıya iade yapıldı ve stok geri eklendi.',
        'reject' => 'Reddet',
        'reject_button' => 'Reddet',
        'rejected_notice' => 'Talep reddedildi',
        'decision_reason' => 'Ret gerekçesi',
        'decision_reason_hint' => 'Alıcı bu açıklamayı görecek.',
        'empty' => 'Bekleyen iptal talebi yok.',
    ],

    'return' => [
        'singular' => 'İade talebi',
        'plural' => 'İade talepleri',
        'requested_at' => 'Talep tarihi',
        'buyer_reason' => 'Alıcının gerekçesi',
        'units' => 'Adet',
        'code' => 'İade kodu',
        'code_hint' => 'Alıcı ürünü bu kodla gönderecek. Kargo firmanızın size verdiği iade kodunu yazın.',
        'cargo' => 'Kargo firması',
        'approve' => 'Onayla',
        'approve_hint' => 'Onay para iadesi DEĞİLDİR — alıcıya ürünü nasıl göndereceğini bildirir. Ücret iadesi, ürün elinize ulaştığında "İadeyi tamamla" ile yapılır.',
        'approve_button' => 'Onayla ve iade kodunu gönder',
        'approved_notice' => 'İade onaylandı',
        'approved_body' => 'Alıcıya iade kodu iletildi. Ürün elinize ulaştığında "İadeyi tamamla" deyin.',
        'reject' => 'Reddet',
        'reject_button' => 'Reddet',
        'rejected_notice' => 'Talep reddedildi',
        'decision_reason' => 'Ret gerekçesi',
        'decision_reason_hint' => 'Alıcı bu açıklamayı görecek.',
        'complete' => 'İadeyi tamamla',
        'complete_confirm' => 'Ürünü teslim aldığınızı onaylıyorsunuz. Seçilen adetlerin ücreti alıcıya İADE EDİLİR, komisyon geri döner ve stok size geri eklenir. Bu işlem geri alınamaz.',
        'complete_button' => 'Teslim aldım, iadeyi başlat',
        'completed_notice' => 'İade tamamlandı',
        'completed_body' => 'Ücret alıcıya iade edildi ve stok geri eklendi.',
        'complete_failed' => 'İade tamamlanamadı',
        'empty' => 'Bekleyen iade talebi yok.',
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
        'cancel_lines' => 'Gönderemiyorum',
        'cancel_lines_confirm' => 'Seçtiğiniz adetlerin ücreti alıcıya iade edilir, komisyon geri döner ve stok size geri eklenir. Kargoya verdikten sonra bu işlem yapılamaz.',
        'cancel_lines_button' => 'İptal et ve iade başlat',
        'cancel_lines_remaining' => 'Kalan adet: :count',
        'cancel_lines_reason_hint' => 'Alıcı bu açıklamayı görecek.',
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
        'lines_cancelled' => 'Satırlar iptal edildi',
        'lines_cancelled_body' => 'Alıcıya iade yapıldı ve stok geri eklendi.',
        'nothing_cancelled' => 'Hiçbir adet seçilmedi.',
    ],

    'empty' => [
        'heading' => 'Henüz sipariş yok',
        'description' => 'Bir müşteri tekliflerinizden birini satın aldığında siparişi burada görünür.',
    ],

    'errors' => [
        'already_cancelled' => 'Bu sipariş zaten iptal edilmiş.',
        'paid_needs_refund' => 'Ödemesi alınmış bir sipariş iade edilerek iptal edilir, bu işlemle değil.',
        'not_cancellable' => 'Bu sipariş artık iptal edilemez.',
        'cart_empty' => 'Sepetiniz boş.',
        'offer_not_sellable' => 'Sepetinizdeki ürünlerden biri artık satışta değil.',
        'insufficient_stock' => 'Sepetinizdeki ürünlerden biri için yeterli stok yok.',
        'address_not_found' => 'Bu adres bulunamadı.',
        'invalid_transition' => 'Bu sipariş bu şekilde durum değiştiremez.',
        'not_cancellable_by_request' => 'Bu sipariş artık iptal edilemez; kargoya verilmiş olabilir.',
        'cancellation_already_requested' => 'Bu sipariş için yanıt bekleyen bir iptal talebi zaten var.',
        'cancellation_already_decided' => 'Bu iptal talebi zaten yanıtlanmış.',
        'not_returnable' => 'Bu ürünler artık iade edilemez.',
        'return_window_closed' => 'Bu siparişin iade süresi doldu.',
        'return_already_requested' => 'Bu sipariş için başlatılmış bir iade talebi zaten var.',
        'return_already_decided' => 'Bu iade talebi zaten yanıtlanmış.',
        'return_not_approved' => 'Bu iade henüz onaylanmadı.',
        'group_not_placeable' => 'Bu alışveriş tamamlanmış ya da artık geçerli değil.',
        'missing_tax_rate' => 'Sepetinizdeki ürünlerden birinin KDV oranı tanımlı değil.',
        'invalid_quantity' => 'Bir üründen en az 1, en fazla :max adet sipariş edebilirsiniz.',
        'cart_full' => 'Sepetinizde en fazla :max farklı ürün olabilir.',
    ],

    /*
    | İptal gerekçeleri. `expired` otomatik: müşteri sekmeyi kapattığında stoğu
    | geri veren süpürme işi bunu yazar (§3.3).
    */
    'cancel' => [
        'expired' => 'Ödeme adımı zaman aşımına uğradı; ayrılan stok serbest bırakıldı.',
    ],
];
