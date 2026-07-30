<?php

declare(strict_types=1);

/*
| Catalog module UI strings.
|
| Turkish is the primary locale (config/app.php). These are the labels an
| operator and a seller read; the catalog's own CONTENT — category names,
| product titles — is not here, it lives in per-locale columns on the rows
| themselves (Catalog.md §13.5). This file is copy; that is data.
*/

return [

    'category' => [
        'singular' => 'Kategori',
        'plural' => 'Kategoriler',
        'name' => 'Ad',
        'parent' => 'Üst kategori',
        'parent_none' => 'Ana kategori (üstü yok)',
        'slug' => 'Kısa ad',
        'slug_hint' => 'Boş bırakılırsa addan üretilir. Değiştirmek genel adresi değiştirir.',
        'path' => 'Yol',
        'depth' => 'Derinlik',
        'position' => 'Sıra',
        'is_active' => 'Aktif',
        'is_leaf' => 'Alt kategorisi yok',
        // ADR-047 — ürün eklenip eklenemeyeceğini artık ağaç şekli değil bu
        // bayrak belirler. Bayraklı bir kategorinin alt kategorisi olabilir.
        'accepts_products' => 'Ürün eklenebilir',
        'accepts_products_hint' => 'Açıkken bu kategoriye doğrudan ürün eklenebilir. Alt kategorisi olsa bile açık bırakılabilir.',
        'accepts_products_locked' => 'Bu kategoride ürünler var; kapatmadan önce onları başka bir kategoriye taşıyın.',
        'products_count' => 'Ürün',
        'attributes' => 'Özellik şeması',
        'empty' => [
            'heading' => 'Henüz kategori yok',
            'description' => 'Kataloğun ilk dalını oluşturun. Ürünler yalnızca alt kategorilere eklenebilir.',
        ],
        'action' => [
            'archive' => 'Arşivle',
            'archive_confirm' => 'Kategori pasife alınır, silinmez. Bağlı ürünler etkilenmez.',
            'delete' => 'Sil',
            'delete_confirm' => 'Bu kategori kalıcı olarak silinir. Yalnızca ürünü ve alt kategorisi olmayan bir kategori silinebilir.',
        ],
        'notify' => [
            'archived' => 'Kategori arşivlendi.',
            'deleted' => 'Kategori silindi.',
        ],
    ],

    'attribute' => [
        'singular' => 'Özellik',
        'plural' => 'Özellikler',
        'code' => 'Kod',
        'code_hint' => 'Değişmez makine adı (ör. renk). Etiket sonradan değiştirilebilir, kod değiştirilemez.',
        'name' => 'Etiket',
        'type' => 'Tür',
        'is_required' => 'Zorunlu',
        'is_required_hint' => 'Bu kategoride zorunlu. Yayımlanma anında denetlenir, taslakta değil.',
        'is_variant_defining' => 'Varyant belirler',
        'is_variant_defining_hint' => 'Yalnızca "seçim" türü varyant ekseni olabilir — kartezyen çarpım sonlu bir küme ister.',
        'is_filterable' => 'Filtrelenebilir',
        'is_active' => 'Aktif',
        'position' => 'Sıra',
        'values' => 'Değerler',
        'values_count' => 'Değer',
        'value' => 'Değer',
        'value_hint' => 'Değişmez makine adı (ör. kirmizi).',
        'label' => 'Etiket',
        'empty' => [
            'heading' => 'Henüz özellik yok',
            'description' => 'Renk ve Beden gibi özellikler kategorilere bağlanır ve varyantları belirler.',
        ],
    ],

    'brand' => [
        'singular' => 'Marka',
        'plural' => 'Markalar',
        'name' => 'Ad',
        'slug' => 'Kısa ad',
        'is_active' => 'Aktif',
        'logo' => 'Logo',
        'logo_hint' => 'İsteğe bağlı. Kare, saydam arka planlı bir logo en iyi sonucu verir. En fazla 2 MB.',
        'empty' => [
            'heading' => 'Henüz marka yok',
            'description' => 'Satıcılar marka seçer, marka oluşturamaz — iki farklı yazım her marka filtresini ikiye böler.',
        ],
    ],

    /*
    | KDV dilimleri (ADR-056). Enum değil tablo: dilimler kararnameyle değişir,
    | sürümle değişmez — Türkiye Temmuz 2023'te %8 → %10 ve %18 → %20 geçişini
    | günler içinde yaptı.
    */
    'tax_rate' => [
        'singular' => 'KDV dilimi',
        'plural' => 'KDV dilimleri',
        'code' => 'Kod',
        'code_hint' => 'Sistem anahtarı; sonradan değiştirilemez. Örn. kdv-20.',
        'name' => 'Ad',
        'name_hint' => 'Satıcının ürün formunda göreceği etiket. Örn. "KDV %10 (İndirimli)".',
        'rate' => 'Oran',
        'rate_hint' => 'Yüzde olarak girin: %20 için 20. Fiyatlar KDV dahildir; bu oran satırdaki KDV\'yi ayırmak için kullanılır.',
        'is_active' => 'Aktif',
        'is_active_hint' => 'Yürürlükten kalkan dilim silinmez, pasife alınır: yeni ürünlerde seçilemez ama mevcut ürünler için geçerliliğini korur.',
        'products_count' => 'Ürün sayısı',
        'empty' => [
            'heading' => 'Henüz KDV dilimi yok',
            'description' => 'Ürün formu bir dilim ister — dilim tanımlanmadan satıcı ürün açamaz.',
        ],
    ],

    'product' => [
        'singular' => 'Ürün',
        'plural' => 'Ürünler',
        'open' => 'Ürün aç',
        'title' => 'Ürün adı',
        'description' => 'Açıklama',
        'category' => 'Kategori',
        'category_hint' => 'Yalnızca alt kategoriler seçilebilir — özellik şeması oradan gelir.',
        'brand' => 'Marka',
        'brand_none' => 'Markasız',
        'tax_rate' => 'KDV dilimi',
        'tax_rate_hint' => 'Ürünün tabi olduğu KDV oranı — ticari bir seçim değil, malın sınıfı. Moderatör kontrol eder.',
        'tax_rate_missing' => 'Dilim seçilmemiş',
        'gtin' => 'Barkod (GTIN)',
        'gtin_hint' => 'Varsa girin: aynı ürünün katalogda ikinci kez açılmasını engeller.',
        'slug' => 'Kısa ad',
        'status' => 'Durum',
        'organization' => 'Kuruluş',
        'organization_hint' => 'Ürün bu kuruluş adına önerilir. Onaylandığında ortak kataloğa girer.',
        'proposed_by' => 'Öneren',
        'submitted_at' => 'Gönderildi',
        'published_at' => 'Yayımlandı',
        'moderated_at' => 'Değerlendirildi',
        'moderation_reason' => 'Gerekçe',
        'images' => 'Görseller',
        'attributes' => 'Özellikler',
        'variants' => 'Varyantlar',
        'variants_count' => 'Varyant',

        'shared_notice' => 'Katalog ortaktır: onaylanan ürün platforma ait olur ve başka satıcılar da aynı ürünü satabilir. Fiyat ve stok burada yoktur — onlar tekliftir.',

        'empty' => [
            'heading' => 'Henüz ürün önermediniz',
            'description' => 'Katalogda olmayan bir ürünü açın. Kategori yöneticisi onayladıktan sonra yayına girer.',
            'queue_heading' => 'Kuyruk boş',
            'queue_description' => 'Değerlendirme bekleyen ürün yok.',
        ],

        'action' => [
            'submit' => 'Değerlendirmeye gönder',
            'submit_confirm' => 'Ürün kategori yöneticisine gönderilir. Gönderdikten sonra düzenleyemezsiniz.',
            'publish' => 'Onayla ve yayımla',
            'publish_confirm' => 'Ürün ortak kataloğa girer.',
            'reject' => 'Reddet',
            'request_revision' => 'Düzeltme iste',
            'archive' => 'Yayından kaldır',
            'archive_confirm' => 'Ürün listeden kaldırılır ama silinmez.',
            'generate_variants' => 'Varyantları oluştur',
            'add_variant' => 'Varyant ekle',
        ],

        'reason' => 'Gerekçe',
        'reason_reject_hint' => 'Satıcı bu metni görür. Neden kabul edilemediğini açıkça yazın.',
        'reason_revision_hint' => 'Satıcı bu metni görür ve düzeltip yeniden gönderir. Neyi düzelteceğini yazın.',

        'notify' => [
            'drafted' => 'Ürün taslağı oluşturuldu.',
            'updated' => 'Ürün güncellendi.',
            'submitted' => 'Ürün değerlendirmeye gönderildi.',
            'published' => 'Ürün yayımlandı.',
            'rejected' => 'Ürün reddedildi.',
            'revision_requested' => 'Düzeltme istendi.',
            'archived' => 'Ürün yayından kaldırıldı.',
            'variants_generated' => ':count varyant oluşturuldu.',
            'failed' => 'İşlem tamamlanamadı.',
        ],
    ],

    'variant' => [
        'singular' => 'Varyant',
        'plural' => 'Varyantlar',
        'sku' => 'Stok kodu (SKU)',
        'sku_hint' => 'Boş bırakılırsa otomatik üretilir.',
        'barcode' => 'Barkod',
        'combination' => 'Kombinasyon',
        'is_default' => 'Varsayılan',
        'position' => 'Sıra',
        'axes' => 'Varyant eksenleri',
        'axes_hint' => 'Seçtiğiniz değerlerin tüm kombinasyonları oluşturulur. İstemediklerinizi sonra silebilirsiniz.',
        'no_axes' => 'Bu kategoride varyant ekseni tanımlı değil — tek bir varsayılan varyant oluşturulur.',
        'empty' => [
            'heading' => 'Henüz varyant yok',
            'description' => 'Her ürünün en az bir varyantı olmalı — satılan birim odur.',
        ],
    ],

    'moderation' => [
        'queue' => 'Ürün onay kuyruğu',
        'queue_singular' => 'Onay bekleyen ürün',
    ],

];
