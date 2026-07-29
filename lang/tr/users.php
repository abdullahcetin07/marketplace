<?php

declare(strict_types=1);

/*
| Yönetici panelindeki hesap kaynakları (Personel / Satıcılar / Müşteriler) için
| etiketler. Yalnızca sunum dizeleri — davranış Identity Action'larında yaşar.
|
| @see App\Modules\Identity\Presentation\Filament\Resources\AccountResource
*/

return [
    'singular' => 'Kullanıcı',
    'plural' => 'Kullanıcılar',

    // Aktör türüne göre ayrılmış üç alan — tek bir liste değil.
    'staff' => [
        'singular' => 'Personel',
        'plural' => 'Personel',
        'action' => [
            'create' => 'Personel ekle',
        ],
    ],
    'seller' => [
        'singular' => 'Satıcı',
        'plural' => 'Satıcılar',
    ],
    'customer' => [
        'singular' => 'Müşteri',
        'plural' => 'Müşteriler',
    ],

    'name' => 'Ad',
    'first_name' => 'Ad',
    'last_name' => 'Soyad',
    'email' => 'E-posta',
    'phone' => 'Telefon',
    'type' => 'Tür',
    'status' => 'Durum',
    'status_active' => 'Aktif',
    'status_suspended' => 'Askıya alınmış',
    'status_draft' => 'Taslak',
    'status_inactive' => 'Pasif',
    'status_pending' => 'Beklemede',
    'status_archived' => 'Arşivlenmiş',
    'two_factor' => '2FA',
    'last_login' => 'Son giriş',
    'last_login_ip' => 'Son giriş IP',
    'login_count' => 'Giriş sayısı',
    'registered' => 'Kayıt tarihi',
    'email_verified' => 'E-posta doğrulandı',
    'email_unverified' => 'Doğrulanmadı',

    'password' => 'Parola',
    'password_confirmation' => 'Parola (tekrar)',
    'password_help' => 'Personel parola politikası: en az 14 karakter, büyük/küçük harf, rakam ve sembol.',

    'roles' => 'Roller',
    'roles_none' => 'Rol yok',
    'roles_help' => 'Yalnızca personel rolleri. Bir satıcının ekip rolleri satıcının kendi panelinde yönetilir.',

    'section' => [
        'profile' => 'Profil',
        'security' => 'Güvenlik',
    ],

    'login_history' => [
        'title' => 'Giriş geçmişi',
        'at' => 'Tarih',
        'result' => 'Başarılı',
        'failure_reason' => 'Hata nedeni',
        'ip' => 'IP adresi',
        'browser' => 'Tarayıcı',
        'platform' => 'Platform',
        'location' => 'Konum',
        'empty' => 'Bu hesap için kayıtlı giriş denemesi yok.',
    ],

    'reason' => 'Gerekçe',
    'reason_help' => 'Denetim kaydına yazılır. Bu değişikliğin neden yapıldığını açıklayın.',

    'action' => [
        'suspend' => 'Askıya al',
        'suspend_confirm' => 'Hesap askıya alınır ve kullanıcı giriş yapamaz. Kayıt silinmez.',
        'reinstate' => 'Yeniden etkinleştir',
        'suspended_notice' => 'Hesap askıya alındı.',
        'reinstated_notice' => 'Hesap yeniden etkinleştirildi.',
    ],

    'reset_password' => 'Parolayı sıfırla',
    'disable_two_factor' => '2FA’yı kapat',
];
