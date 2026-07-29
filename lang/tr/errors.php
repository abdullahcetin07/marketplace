<?php

declare(strict_types=1);

/*
| Domain error messages, keyed by BaseException::getErrorCode() and by the
| deny() reasons returned from policies.
|
| These strings are shown to end users. They must say what went wrong without
| revealing internal structure — "kayıt bulunamadı" is fine, "users tablosunda
| id 4213 yok" is not.
|
| @see App\Core\Domain\Exceptions\BaseException::userMessage()
*/

return [

    // Generic
    'generic' => 'Beklenmeyen bir hata oluştu. Lütfen tekrar deneyin.',
    'validation_failed' => 'Gönderilen bilgiler geçerli değil.',
    'unauthenticated' => 'Bu işlem için giriş yapmanız gerekiyor.',
    'forbidden' => 'Bu işlem için yetkiniz yok.',
    'missing_permission' => 'Bu işlem için gerekli yetkiye sahip değilsiniz.',
    'not_owner' => 'Yalnızca kendi kayıtlarınız üzerinde işlem yapabilirsiniz.',
    'not_found' => 'Kayıt bulunamadı.',
    'too_many_requests' => 'Çok fazla istek gönderdiniz. Lütfen biraz bekleyin.',

    // Identity
    'account_suspended' => 'Hesabınız askıya alınmış. Destek ekibiyle iletişime geçin.',
    'account_unverified' => 'Devam etmeden önce e-posta adresinizi doğrulamanız gerekiyor.',
    'invalid_credentials' => 'E-posta adresi veya parola hatalı.',
    'two_factor_required' => 'İki adımlı doğrulama kodu gerekli.',
    'two_factor_invalid' => 'Doğrulama kodu geçersiz.',
    'cannot_delete_self' => 'Kendi hesabınızı silemezsiniz.',
    'cannot_impersonate_self' => 'Kendi hesabınıza giriş yapamazsınız.',
    'cannot_impersonate_admin' => 'Bir yönetici hesabına giriş yapılamaz.',
    'cannot_modify_super_admin' => 'Süper yönetici hesabı üzerinde işlem yapamazsınız.',
    // Kendi seviyenizin üzerinde bir rol veremez veya alamazsınız.
    'cannot_grant_role' => 'Bu rolü veremezsiniz: :role',
    'session_not_found' => 'Oturum bulunamadı veya zaten sonlandırılmış.',
    // Her hata için tek bir gerekçe — tahmin edilen bir kod, adresin var olup
    // olmadığını doğrulayamasın.
    'reset_token_invalid' => 'Bu parola sıfırlama bağlantısı geçersiz veya süresi dolmuş. Lütfen yeni bir tane isteyin.',
    'email_verification_invalid' => 'Bu doğrulama bağlantısı geçersiz veya süresi dolmuş. Lütfen yeni bir tane isteyin.',
    'two_factor_already_enabled' => 'İki adımlı doğrulama zaten etkin. Yeniden kaydolmak için önce kapatın.',

    // Localization
    'default_language_undeletable' => 'Varsayılan dil silinemez. Önce başka bir dili varsayılan yapın.',
    'default_currency_undeletable' => 'Varsayılan para birimi silinemez. Önce başka bir para birimini varsayılan yapın.',
    'stale_exchange_rate' => 'Döviz kuru güncel değil. Fiyatlandırma yapılamıyor.',

    // Settings
    'setting_locked' => 'Bu ayar sistem tarafından kilitlenmiş ve değiştirilemez.',
    'setting_undeletable' => 'Ayarlar silinemez.',
    'setting_uncreatable' => 'Ayarlar arayüzden oluşturulamaz.',

    // Audit / Activity
    'audit_immutable' => 'Denetim kayıtları değiştirilemez veya silinemez.',
    'activity_immutable' => 'Aktivite kayıtları değiştirilemez veya silinemez.',

    // Search / Media
    'search_indexing_failed' => 'Arama dizini güncellenemedi.',
    'media_type_not_allowed' => 'Bu dosya türü kabul edilmiyor.',
    'media_too_large' => 'Dosya boyutu izin verilen sınırı aşıyor.',

    // Store
    'store_unavailable' => 'Bu mağaza mevcut değil.',

];
