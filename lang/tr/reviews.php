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

    'request' => [
        'subject' => 'Aldığınız :product nasıldı?',
        'intro' => 'Sipariş ettiğiniz :product elinize geçeli birkaç gün oldu. Nasıl olduğunu merak ediyoruz — birkaç dakikanızı ayırıp değerlendirir misiniz?',
        'points' => 'Değerlendirmeniz yayınlandığında :points puan hesabınıza eklenir.',
        'action' => 'Değerlendir',
        'outro' => 'Yorumunuz, aynı ürüne bakan diğer alıcıların karar vermesine yardımcı olur. Bu tür e-postaları almak istemiyorsanız hesap ayarlarınızdan bildirim tercihlerinizi güncelleyebilirsiniz.',
    ],
];
