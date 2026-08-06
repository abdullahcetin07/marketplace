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

    'OrganizationStatus' => [
        'pending' => 'İnceleme Bekliyor',
        'approved' => 'Onaylandı',
        'rejected' => 'Reddedildi',
        'suspended' => 'Askıya Alındı',
        'archived' => 'Arşivlendi',
    ],

    /*
    | Şirket içi roller (ADR-030). Platform (Spatie) rolleriyle karıştırılmasın:
    | bunlar yalnızca bir kuruluşun içinde anlam taşır. Sahip yalnızca devirle
    | değişir (ADR-029).
    */
    'OrganizationRole' => [
        'owner' => 'Sahip',
        'manager' => 'Yönetici',
        'finance' => 'Finans',
        'warehouse' => 'Depo',
        'support' => 'Destek',
        'marketing' => 'Pazarlama',
        'editor' => 'Editör',
        'viewer' => 'Görüntüleyici',
    ],

    'OrganizationMemberStatus' => [
        'active' => 'Aktif',
        'suspended' => 'Askıya Alındı',
    ],

    'InvitationStatus' => [
        'pending' => 'Bekliyor',
        'accepted' => 'Kabul Edildi',
        'rejected' => 'Reddedildi',
        'expired' => 'Süresi Doldu',
        'cancelled' => 'Geri Çekildi',
    ],

    'StoreOpeningRequestStatus' => [
        'draft' => 'Taslak',
        'pending' => 'İnceleme Bekliyor',
        'approved' => 'Onaylandı',
        'rejected' => 'Reddedildi',
        'cancelled' => 'İptal Edildi',
    ],

    'OrganizationDocumentStatus' => [
        'pending' => 'İnceleme Bekliyor',
        'approved' => 'Onaylandı',
        'needs_revision' => 'Düzeltme Gerekiyor',
        'rejected' => 'Reddedildi',
    ],

    'OrganizationDocumentType' => [
        'tax_certificate' => 'Vergi levhası',
        'trade_registry' => 'Ticaret sicil belgesi',
        'signature_circular' => 'İmza sirküleri',
        'id_document' => 'Kimlik belgesi',
        'other' => 'Diğer',
    ],

    /*
    | App\Modules\Offer\Domain\Enums\OfferStatus ve Sprint-0 yer tutucusu
    | App\Shared\Enums\OfferStatus burada birlikte çözümlenir. İki küme
    | birleşimi: `suspended` modül enum'una (§2.2) aittir; `draft`, `pending`,
    | `rejected`, `expired` yalnızca yer tutucununkilerdir.
    |
    | `out_of_stock` DA yalnızca yer tutucunundur. Modül enum'unda böyle bir
    | durum YOKTUR ve olmayacaktır — stokta olmama `stock_quantity = 0`'dan
    | türetilir (ADR-043/045). Etiket yer tutucu için burada durur; teklif
    | durumu olarak okunmamalıdır.
    */
    'OfferStatus' => [
        'draft' => 'Taslak',
        'pending' => 'Onay Bekliyor',
        'active' => 'Yayında',
        'paused' => 'Duraklatıldı',
        'suspended' => 'Askıya Alındı',
        'out_of_stock' => 'Stokta Yok',
        'rejected' => 'Reddedildi',
        'expired' => 'Süresi Doldu',
        'withdrawn' => 'Geri Çekildi',
    ],

    /*
    | Sipariş durumları (ADR-054). Üç durum, çünkü ödeme ve kargo başka
    | sprintlerin işi: `pending` stok AYRILMIŞ demek, `awaiting_payment` stok
    | DÜŞÜLMÜŞ ve ödeme bekleniyor demek.
    */
    'OrderStatus' => [
        'pending' => 'Ödeme adımında',
        'awaiting_payment' => 'Ödeme bekliyor',
        'paid' => 'Ödendi',
        'delivered' => 'Teslim edildi',
        'refunded' => 'İade edildi',
        'cancelled' => 'İptal edildi',
    ],

    /*
    | Stok hareketinin GEREKÇESİ (ADR-050). Satıcının "stoğum neden değişti?"
    | sorusunu yanıtlayan sütun budur: üç adet düşmüş olması satıldığı anlamına
    | da, birinin sepetinde beklediği anlamına da gelebilir — hangisi olduğunu
    | yalnızca tür söyler.
    */
    'StockMovementType' => [
        'seller_adjustment' => 'Satıcı stok girişi',
        'reserved' => 'Ayrıldı',
        'released' => 'Serbest bırakıldı',
        'committed' => 'Satıldı',
        'restocked' => 'İade ile geri girdi',
    ],

    'ReservationStatus' => [
        'active' => 'Ayrılmış',
        'released' => 'Serbest bırakıldı',
        'committed' => 'Satışa dönüştü',
        'restocked' => 'İade edildi',
    ],

    /*
    | Keyed by the enum's SHORT class name, so the module-owned
    | App\Modules\Catalog\Domain\Enums\ProductStatus and the Sprint-0
    | placeholder App\Shared\Enums\ProductStatus resolve here together. The
    | union of both case sets: `needs_revision` is the Catalog lifecycle's
    | (§2.6), `unpublished` is the placeholder's and the Catalog enum has no
    | such state.
    */
    'ProductStatus' => [
        'draft' => 'Taslak',
        'pending_review' => 'Değerlendirmede',
        'needs_revision' => 'Düzeltme Bekliyor',
        'published' => 'Yayında',
        'unpublished' => 'Yayından Kaldırıldı',
        'rejected' => 'Reddedildi',
        'archived' => 'Arşivlendi',
    ],

    'AttributeType' => [
        'select' => 'Seçim',
        'text' => 'Metin',
        'number' => 'Sayı',
        'boolean' => 'Evet/Hayır',
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
        'shipping' => 'Kargo',
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

    'CancellationRequestStatus' => [
        'pending' => 'Yanıt bekliyor',
        'approved' => 'Onaylandı',
        'rejected' => 'Reddedildi',
    ],

    'ReviewStatus' => [
        'pending_review' => 'Onay bekliyor',
        'published' => 'Yayında',
        'rejected' => 'Reddedildi',
    ],

    'ShipmentStatus' => [
        'pending' => 'Hazırlanıyor',
        'shipped' => 'Kargoda',
        'delivered' => 'Teslim edildi',
        'returned' => 'İade edildi',
        'cancelled' => 'İptal edildi',
    ],

    'DeliveredVia' => [
        'buyer' => 'Alıcı onayladı',
        'transit_sweep' => 'Kargo süresi doldu',
        'carrier' => 'Kargo firması bildirdi',
    ],
];
