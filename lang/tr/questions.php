<?php

declare(strict_types=1);

return [
    'singular' => 'Soru',
    'plural' => 'Sorular',

    'errors' => [
        'no_seller' => 'Bu ürünü şu an satan bir mağaza yok, soru iletilemedi.',
        'not_pending' => 'Bu soru zaten cevaplanmış.',
        'product_not_found' => 'Ürün bulunamadı.',
    ],

    'answer' => [
        'action' => 'Cevapla',
        'body' => 'Cevabınız',
        'body_hint' => 'Cevabınız ürün sayfasında herkese açık görünecek.',
        'submitted' => 'Cevabınız yayınlandı.',
        'empty' => 'Cevap bekleyen soru yok.',
    ],

    'moderation' => [
        'hide' => 'Gizle',
        'hide_reason' => 'Gizleme gerekçesi',
        'hide_reason_hint' => 'Kayda geçer; ne soran ne satıcı görür.',
        'hidden_notice' => 'Soru gizlendi',
        'unhide' => 'Yeniden göster',
        'unhide_confirm' => 'Soru gizlenmeden önceki haline döner.',
        'unhidden_notice' => 'Soru yeniden görünür',
        'empty' => 'Soru yok.',
    ],

    'field' => [
        'product' => 'Ürün',
        'seller' => 'Satıcı',
        'asker' => 'Soran',
        'body' => 'Soru',
        'answer' => 'Cevap',
        'status' => 'Durum',
        'asked_at' => 'Soruldu',
        'answered_at' => 'Cevaplandı',
        'hidden' => 'Gizli',
    ],
];
