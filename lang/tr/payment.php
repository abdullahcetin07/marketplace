<?php

declare(strict_types=1);

/*
| Payment module strings. Presentation and audit reasons only — behaviour lives
| in the Payment actions.
|
| @see docs/modules/Payment.md
*/

return [
    'singular' => 'Ödeme',
    'plural' => 'Ödemeler',
    'reference' => 'Ödeme referansı',
    'amount' => 'Tutar',
    'status_label' => 'Durum',
    'paid_at' => 'Ödendi',

    'status' => [
        'pending' => 'Bekliyor',
        'paid' => 'Ödendi',
        'failed' => 'Başarısız',
        'expired' => 'Süresi doldu',
        'refunded' => 'İade edildi',
        'partially_refunded' => 'Kısmen iade edildi',
    ],

    'errors' => [
        'group_not_found' => 'Böyle bir sipariş grubu bulunamadı.',
        'nothing_to_pay' => 'Bu sipariş grubunda ödenecek bir tutar yok.',
        'already_settled' => 'Bu sipariş grubunun ödemesi zaten alınmış.',
        'gateway_unavailable' => 'Ödeme sağlayıcısına şu anda ulaşılamıyor. Lütfen tekrar deneyin.',
        'gateway_rejected' => 'Ödeme sağlayıcısı isteği reddetti.',
        'payout_amount_invalid' => 'Ödeme tutarı sıfırdan büyük olmalı.',
        'payout_exceeds_balance' => 'Bu tutar satıcının bakiyesini aşıyor.',
        'payout_already_settled' => 'Bu ödemenin sonucu zaten kaydedilmiş.',
        'not_refundable' => 'Bu ödemede iade edilecek bir tahsilat yok.',
        'nothing_to_refund' => 'Bu siparişlerin iadesi zaten yapılmış.',
        'return_window_closed' => 'Bu sipariş için iade süresi dolmuş ya da henüz teslim edilmemiş.',
        'not_cancellable' => 'Bu sipariş artık iptal edilemez — kargoya verilmiş ya da size ait değil.',
    ],

    'commission' => [
        'singular' => 'Komisyon kuralı',
        'plural' => 'Komisyon kuralları',
        'label' => 'Açıklama',
        'label_hint' => 'Bir yıl sonra bu satırın neden var olduğunu anlatan not.',
        'rate' => 'Oran',
        'rate_hint' => 'Yüzde olarak girin: 15 → %15.',
        'scopes' => 'Kapsam',
        'scopes_hint' => 'Boş bırakılan alan "hepsi" demektir. Dördü de boşsa bu, platform varsayılanıdır. En çok alanı dolu olan kural kazanır.',
        'seller' => 'Satıcı organizasyonu (UUID)',
        'category' => 'Kategori (UUID)',
        'category_hint' => 'Bir üst kategoriye konan kural, altındakileri de kapsar.',
        'brand' => 'Marka (UUID)',
        'product' => 'Ürün (UUID)',
        'priority' => 'Öncelik',
        'priority_hint' => 'Yalnızca AYNI kapsam sayısına sahip kurallar arasında berabereliği bozar; kapsam özgüllüğünü asla yenemez.',
        'specificity' => 'Özgüllük',
        'is_active' => 'Aktif',
        'is_default' => 'Platform varsayılanı',
        'never_deleted' => 'Komisyon kuralları silinmez; pasifleştirilir.',
    ],

    'ledger' => [
        'singular' => 'Bakiye hareketi',
        'plural' => 'Bakiye hareketleri',
        'balance' => 'Bakiye',
        'type' => [
            'sale_credit' => 'Satış alacağı',
            'commission_debit' => 'Komisyon kesintisi',
            'payout_debit' => 'Ödeme (havale)',
            'refund_debit' => 'İade kesintisi',
            'refund_commission_credit' => 'İade komisyon iadesi',
            'payout_reversal_credit' => 'Başarısız ödeme iadesi',
        ],
    ],

    'payout' => [
        'singular' => 'Satıcı ödemesi',
        'plural' => 'Satıcı ödemeleri',
        'seller' => 'Satıcı organizasyonu (UUID)',
        'seller_hint' => 'Ödeme yapılacak satıcı. Kullanılabilir bakiye yanda görünür.',
        'available' => 'Ödenebilir',
        'on_hold' => 'Teslimat bekleyen',
        'amount' => 'Tutar (TL)',
        'amount_hint' => 'Bakiyeyi aşamaz. Yazılım parayı TAŞIMAZ — havaleyi bir insan yapar.',
        'note' => 'Not',
        'note_hint' => 'Örn. "Temmuz toplu ödeme".',
        'status_label' => 'Durum',
        'status' => [
            'pending' => 'Gönderilecek',
            'paid' => 'Gönderildi',
            'failed' => 'Başarısız',
        ],
        'reference' => 'Banka referansı',
        'failure_reason' => 'Ret gerekçesi',
        'created_at' => 'Oluşturma',
        'settle' => 'Sonucu kaydet',
        'outcome' => 'Banka ne dedi?',
        'outcome_paid' => 'Havale gönderildi',
        'outcome_failed' => 'Havale reddedildi (bakiye iade edilir)',
        'detail_hint' => 'Bankanın verdiği referans ya da ret gerekçesi.',
        'settled' => 'Ödemenin sonucu kaydedildi.',
        'decided_by' => 'Kararı veren',
        'decided_by_any' => 'Hepsi',
        'automatic' => 'Otomatik',
        'manual' => 'Elle',
        'never_deleted' => 'Satıcı ödemeleri silinmez; başarısız olarak işaretlenir.',
    ],

    'payment' => [
        'never_deleted' => 'Ödeme kaydı silinmez; bankanın yaptığı işlemin kaydıdır.',
    ],

    'refund' => [
        'singular' => 'İade',
        'plural' => 'İadeler',
        'action' => 'İade et',
        'orders' => 'İade edilecek siparişler',
        'orders_hint' => 'Boş bırakılırsa bu ödemedeki tüm siparişler iade edilir.',
        'reason' => 'İade gerekçesi',
        'reason_hint' => 'Bir yıl sonra bu iadenin neden yapıldığını anlatan not.',
        'confirm' => 'Para PayTR üzerinden alıcıya geri gönderilecek, satıcının bakiyesi düşecek ve stok geri girecek. Bu işlem geri alınamaz.',
        'done' => 'İade yapıldı.',
    ],
];
