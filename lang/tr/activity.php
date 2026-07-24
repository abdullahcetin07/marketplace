<?php

declare(strict_types=1);

/*
| Activity timeline sentences, resolved by ActivityEntry::label().
|
| Keyed by ActivityType value, with a few extras for entries that store an
| explicit description. Written in the second person — a user reads these on
| their own security page.
|
| @see App\Modules\Activity\Domain\Models\ActivityEntry::label()
*/

return [

    'login' => 'Giriş yaptınız',
    'logout' => 'Çıkış yaptınız',
    'login_failed' => 'Başarısız giriş denemesi',
    'suspicious_login' => 'Hesabınızda şüpheli bir giriş denemesi tespit edildi',
    'password_changed' => 'Parolanız değiştirildi',
    'password_reset' => 'Parolanız sıfırlandı',
    'email_verified' => 'E-posta adresiniz doğrulandı',
    'profile_updated' => 'Profiliniz güncellendi',
    'permission_changed' => 'Yetkileriniz değiştirildi',
    'role_changed' => 'Rolünüz değiştirildi',
    'two_factor_enabled' => 'İki adımlı doğrulama açıldı',
    'two_factor_disabled' => 'İki adımlı doğrulama kapatıldı',
    'session_revoked' => 'Oturum sonlandırıldı',
    'device_trusted' => 'Cihaz güvenilir olarak işaretlendi',
    'settings_updated' => 'Ayarlar güncellendi',

    'account_created' => 'Hesap oluşturuldu',
    'password_reset_requested' => 'Parola sıfırlama talebi oluşturuldu',

];
