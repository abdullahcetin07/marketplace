<?php

declare(strict_types=1);

/*
| Organizasyon modülü dizeleri.
|
| @see App\Modules\Organization
*/

return [

    'registered' => 'Kuruluşunuz kaydedildi ve inceleme bekliyor.',
    'kyc_submitted' => 'Şirket bilgileriniz inceleme için gönderildi.',
    'ownership_transferred' => 'Sahiplik devredildi.',
    'invitation_sent' => 'Davet gönderildi.',
    'invitation_accepted' => 'Kuruluşa katıldınız.',
    'invitation_rejected' => 'Davet reddedildi.',
    'document_uploaded' => 'Belge yüklendi ve inceleme bekliyor.',
    'approved' => 'Kuruluş onaylandı.',
    'rejected' => 'Kuruluş reddedildi.',
    'suspended' => 'Kuruluş askıya alındı.',
    'reinstated' => 'Kuruluş yeniden etkinleştirildi.',
    'store_request_approved' => 'Mağaza açma talebi onaylandı.',
    'store_request_rejected' => 'Mağaza açma talebi reddedildi.',

    // Filament etiketleri.
    'singular' => 'Kuruluş',
    'legal_name' => 'Yasal ad',
    'plan' => 'Plan',
    'action' => [
        'approve' => 'Onayla',
        'reject' => 'Reddet',
        'suspend' => 'Askıya al',
        'reinstate' => 'Yeniden etkinleştir',
        'reason' => 'Gerekçe',
    ],
    'store_request' => [
        'singular' => 'Mağaza açma talebi',
        'name' => 'Mağaza adı',
    ],

    'invitation' => [
        'subject' => ':organization kuruluşuna katılmaya davet edildiniz',
        'intro' => ':organization sizi :role olarak katılmaya davet etti.',
        'action' => 'Daveti kabul et',
        'expiry' => 'Bu davetin süresi dolacaktır. Hâlâ geçerliyken kabul edin.',
        'no_account' => 'Henüz bir hesabınız yoksa önce hesap oluşturmanız istenir, ardından davet tamamlanır.',
    ],

];
