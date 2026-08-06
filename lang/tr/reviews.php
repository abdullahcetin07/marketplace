<?php

declare(strict_types=1);

return [
    'singular' => 'Değerlendirme',
    'plural' => 'Değerlendirmeler',

    'errors' => [
        'not_eligible' => 'Bu ürünü değerlendirmek için önce satın alıp teslim almış olmanız gerekiyor.',
        'not_pending' => 'Bu değerlendirme zaten sonuçlandırılmış.',
        'already_reviewed' => 'Bu alışverişinizi zaten değerlendirdiniz.',
        'product_not_found' => 'Ürün bulunamadı.',
    ],

    'moderation' => [
        'publish' => 'Yayınla',
        'publish_confirm' => 'Değerlendirme, fotoğraflarıyla birlikte ürün sayfasında yayına alınacak.',
        'published_notice' => 'Değerlendirme yayınlandı',
        'reject' => 'Reddet',
        'reject_reason' => 'Ret gerekçesi',
        'reject_reason_hint' => 'Kayda geçer; alıcıya gösterilmez.',
        'rejected_notice' => 'Değerlendirme reddedildi',
        'empty' => 'Onay bekleyen değerlendirme yok.',
    ],

    'field' => [
        'product' => 'Ürün',
        'seller' => 'Satıcı',
        'author' => 'Değerlendiren',
        'rating' => 'Puan',
        'body' => 'Yorum',
        'photos' => 'Fotoğraflar',
        'status' => 'Durum',
        'submitted_at' => 'Gönderim',
        'has_photos' => 'Fotoğraflı',
    ],
];
