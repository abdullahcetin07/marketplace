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
    'plural' => 'Kuruluşlar',
    'legal_name' => 'Yasal ad',
    'display_name' => 'Ticari ad',
    'display_name_hint' => 'Boş bırakılırsa yasal ad kullanılır.',
    'slug' => 'Kısa ad',
    'slug_hint' => 'Yalnızca harf, rakam ve tire. Sonradan değiştirilemez.',
    'country' => 'Ülke',
    'currency' => 'Para birimi',
    'status' => 'Durum',
    'plan' => 'Plan',
    'action' => [
        'create' => 'Şirketini oluştur',
        'approve' => 'Onayla',
        'reject' => 'Reddet',
        'suspend' => 'Askıya al',
        'reinstate' => 'Yeniden etkinleştir',
        'reason' => 'Gerekçe',
    ],
    'empty' => [
        'heading' => 'Henüz bir şirketiniz yok',
        'description' => 'Satışa başlamak için önce yasal şirketinizi kaydedin. Onay sonrası mağaza açma talebi oluşturabilirsiniz.',
    ],
    'kyc' => [
        'title' => 'Şirket doğrulama (KYC)',
        'action' => 'Şirket bilgilerini gönder',
        'national_id' => 'Yetkili kişinin TC kimlik numarası',
        'national_id_hint' => 'Şifrelenerek saklanır ve bir daha gösterilmez.',
        'national_id_on_file' => 'Kayıtlı bir kimlik numarası var. Değiştirmek istemiyorsanız boş bırakın.',
        'metadata' => 'Ülkeye özel bilgiler',
        'metadata_key' => 'Alan',
        'metadata_value' => 'Değer',
        'submitted_at' => 'Gönderim tarihi',
        'not_submitted' => 'Henüz gönderilmedi',
        'tax_number' => 'Vergi numarası',
        'registration_number' => 'Ticaret sicil numarası',
        'authorized_person_name' => 'Yetkili kişi',
    ],
    'document' => [
        'plural' => 'Belgeler',
        'type' => 'Belge türü',
        'status' => 'Durum',
        'file' => 'Dosya',
        'file_hint' => 'PDF veya görsel, en fazla 10 MB. Gizli olarak saklanır ve yalnızca inceleme ekibiyle paylaşılır.',
        'review_notes' => 'İnceleme notu',
        'uploaded_at' => 'Yüklenme tarihi',
        'action' => [
            'upload' => 'Belge yükle',
            'view' => 'Aç',
            'download' => 'İndir',
            'approve' => 'Onayla',
            'request_revision' => 'Düzeltme iste',
            'reject' => 'Reddet',
        ],
        'review' => [
            'notes' => 'Satıcıya not',
            'notes_hint' => 'Satıcıya gösterilir. Neyin düzeltilmesi gerektiğini açıkça yazın.',
            'approved' => 'Belge onaylandı.',
            'needs_revision' => 'Belge düzeltme için geri gönderildi.',
            'rejected' => 'Belge reddedildi.',
        ],
        'empty' => [
            'heading' => 'Henüz belge yok',
            'description' => 'İncelemenin başlayabilmesi için vergi levhanızı, ticaret sicil belgenizi ve imza sirkülerinizi yükleyin.',
        ],
        'admin_empty' => [
            'heading' => 'Bu şirket henüz belge yüklemedi',
            'description' => 'İncelenecek bir kanıt yok. Şimdi onaylamak, belgesiz onaylamak demektir.',
        ],
    ],
    'bank' => [
        'title' => 'Ödeme hesabı',
        'action' => 'Ödeme hesabını tanımla',
        'account_holder' => 'Hesap sahibi',
        'bank_name' => 'Banka',
        'iban' => 'IBAN',
        'iban_hint' => 'Şifrelenerek saklanır. Sonrasında yalnızca son dört hanesi gösterilir.',
        'iban_on_file' => 'Kayıtlı hesap: :iban. Değiştirmek için IBAN’ı yeniden girin.',
        'currency' => 'Ödeme para birimi',
        'not_set' => 'Henüz tanımlanmadı',
    ],
    'bank_account_updated' => 'Ödeme hesabınız kaydedildi.',

    /*
    | Yönetici inceleme yüzeyi — kararı vermeden önce okunanlar. Şifreli alanlar
    | daima maskeli gösterilir: amaç doğrulamak, ifşa etmek değil.
    */
    'review' => [
        'company' => 'Şirket',
        'kyc' => 'Şirket doğrulama (KYC)',
        'bank' => 'Ödeme hesabı',
        'owner' => 'Sahip',
        'owner_email' => 'Sahip e-postası',
        'registered_at' => 'Kayıt tarihi',
        'verified_at' => 'Doğrulanma tarihi',
        'masked_hint' => 'Yalnızca son dört hane gösterilir; değer şifreli saklanır.',
        'not_provided' => 'Girilmedi',
    ],
    'store_request' => [
        'singular' => 'Mağaza açma talebi',
        'plural' => 'Mağaza açma talepleri',
        'name' => 'Mağaza adı',
        'slug' => 'Mağaza adresi',
        'slug_hint' => 'Yalnızca harf, rakam ve tire. Mağazanız /store/bu-adres üzerinden açılacak.',
        'organization_hint' => 'Mağazanın bağlı olacağı şirket.',
        'description' => 'Ne satacaksınız?',
        'reason' => 'İnceleme ekibine not',
        'reason_hint' => 'İsteğe bağlı. Talebi anlamalarına yardımcı olacak her şey.',
        'submitted_at' => 'Gönderim tarihi',
        'review_notes' => 'İnceleme notu',
        'created' => 'Taslak kaydedildi. Hazır olduğunuzda incelemeye gönderin.',
        'submitted' => 'Talebiniz incelemeye gönderildi.',
        'submit_failed' => 'Talep gönderilemedi',
        'cancelled' => 'Talep geri çekildi.',
        'cancel_failed' => 'Talep geri çekilemedi',
        'action' => [
            'create' => 'Mağaza talebi oluştur',
            'submit' => 'İncelemeye gönder',
            'submit_confirm' => 'Gönderildikten sonra talep inceleme ekibine iletilir ve düzenlenemez. Yine de geri çekebilirsiniz.',
            'cancel' => 'Geri çek',
        ],
        'empty' => [
            'heading' => 'Henüz mağaza talebiniz yok',
            'description' => 'Satışa başlamak için mağaza talebi oluşturun. İnceleme ekibi onayladığında mağazanız otomatik olarak açılır.',
        ],
    ],

    'invitation' => [
        'subject' => ':organization kuruluşuna katılmaya davet edildiniz',
        'intro' => ':organization sizi :role olarak katılmaya davet etti.',
        'action' => 'Daveti kabul et',
        'expiry' => 'Bu davetin süresi dolacaktır. Hâlâ geçerliyken kabul edin.',
        'no_account' => 'Henüz bir hesabınız yoksa önce hesap oluşturmanız istenir, ardından davet tamamlanır.',
    ],

];
