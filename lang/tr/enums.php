<?php

declare(strict_types=1);

/*
| Enum labels, resolved by App\Shared\Enums\Concerns\HasEnumHelpers::label().
| Keyed by the enum's short class name, then by case value.
|
| Country, Currency and Language are NO LONGER HERE — they became lookup
| tables in Sprint 1, and their display names live in the `name` /
| `native_name` columns so an operator can edit them.
| @see docs/001_Architecture.md §"Enums vs lookup tables"
*/

return [

    'Status' => [
        'draft' => 'Taslak',
        'active' => 'Aktif',
        'inactive' => 'Pasif',
        'pending' => 'Beklemede',
        'suspended' => 'Askıya Alındı',
        'archived' => 'Arşivlendi',
    ],

    'UserType' => [
        'admin' => 'Yönetici',
        'seller' => 'Satıcı',
        'customer' => 'Müşteri',
    ],

    'StoreStatus' => [
        'pending' => 'Beklemede',
        'under_review' => 'İncelemede',
        'approved' => 'Onaylandı',
        'active' => 'Aktif',
        'suspended' => 'Askıya Alındı',
        'rejected' => 'Reddedildi',
        'closed' => 'Kapatıldı',
    ],

    'OfferStatus' => [
        'draft' => 'Taslak',
        'pending' => 'Onay Bekliyor',
        'active' => 'Yayında',
        'paused' => 'Duraklatıldı',
        'out_of_stock' => 'Stokta Yok',
        'rejected' => 'Reddedildi',
        'expired' => 'Süresi Doldu',
        'withdrawn' => 'Geri Çekildi',
    ],

    'ProductStatus' => [
        'draft' => 'Taslak',
        'pending_review' => 'İncelemede',
        'published' => 'Yayında',
        'unpublished' => 'Yayından Kaldırıldı',
        'rejected' => 'Reddedildi',
        'archived' => 'Arşivlendi',
    ],

    'NotificationType' => [
        'database' => 'Uygulama İçi',
        'mail' => 'E-posta',
        'sms' => 'SMS',
        'push' => 'Anlık Bildirim',
        'broadcast' => 'Canlı Yayın',
    ],

    'MediaType' => [
        'image' => 'Görsel',
        'document' => 'Belge',
        'video' => 'Video',
        'audio' => 'Ses',
        'archive' => 'Arşiv',
        'other' => 'Diğer',
    ],

    'ActivityType' => [
        'login' => 'Giriş yapıldı',
        'logout' => 'Çıkış yapıldı',
        'login_failed' => 'Başarısız giriş denemesi',
        'password_changed' => 'Parola değiştirildi',
        'password_reset' => 'Parola sıfırlandı',
        'email_verified' => 'E-posta doğrulandı',
        'profile_updated' => 'Profil güncellendi',
        'permission_changed' => 'Yetkiler değiştirildi',
        'role_changed' => 'Rol değiştirildi',
        'two_factor_enabled' => 'İki adımlı doğrulama açıldı',
        'two_factor_disabled' => 'İki adımlı doğrulama kapatıldı',
        'session_revoked' => 'Oturum sonlandırıldı',
        'device_trusted' => 'Cihaz güvenilir olarak işaretlendi',
        'settings_updated' => 'Ayarlar güncellendi',
    ],

    'SettingGroup' => [
        'general' => 'Genel',
        'company' => 'Şirket',
        'email' => 'E-posta',
        'sms' => 'SMS',
        'media' => 'Medya',
        'seo' => 'SEO',
        'localization' => 'Yerelleştirme',
        'security' => 'Güvenlik',
        'performance' => 'Performans',
        'system' => 'Sistem',
    ],

    'SettingType' => [
        'string' => 'Metin',
        'integer' => 'Sayı',
        'boolean' => 'Evet/Hayır',
        'json' => 'JSON',
        'text' => 'Uzun Metin',
    ],

    'TextDirection' => [
        'ltr' => 'Soldan sağa',
        'rtl' => 'Sağdan sola',
    ],

    'SymbolPosition' => [
        'before' => 'Tutardan önce',
        'after' => 'Tutardan sonra',
    ],

];
