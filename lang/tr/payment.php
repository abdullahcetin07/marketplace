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
];
