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
        ],
    ],
];
