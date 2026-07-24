<?php

declare(strict_types=1);

/*
| ADR-009 `message` alanı için başarı mesajları.
|
| Bunlar kullanıcıya gösterilen onay metinleridir; hata metinleri errors.php
| içindedir ve BaseException::translationKey() ile anahtarlanır.
|
| İstemciler `code` alanına göre dallanır, `message` alanına göre değil.
*/

return [

    'signed_in' => 'Giriş yapıldı.',
    'signed_out' => 'Çıkış yapıldı.',
    'profile_updated' => 'Profiliniz güncellendi.',
    'registered' => 'Hesabınız oluşturuldu.',

    /*
    | Bilinçli olarak belirsiz (ADR-025). Hesap var da olsa yok da olsa aynı
    | cümle döner — metin, gerçekten gönderim yapıldığını ima etmemelidir.
    */
    'password_reset_requested' => 'Bu e-posta adresine ait bir hesap varsa, parola sıfırlama yönergeleri gönderildi.',
    'password_reset' => 'Parolanız sıfırlandı. Lütfen yeni parolanızla giriş yapın.',
    'password_changed' => 'Parolanız değiştirildi.',

    // E-posta içerikleri — sıfırlama kodunun görüldüğü tek yer.
    'mail' => [
        'greeting' => 'Merhaba :name,',
        'reset_subject' => 'Parolanızı sıfırlayın',
        'reset_intro' => 'Hesabınız için bir parola sıfırlama talebi aldığımız için bu e-postayı alıyorsunuz.',
        'reset_button' => 'Parolayı sıfırla',
        'reset_expiry' => 'Bu bağlantı :minutes dakika içinde geçerliliğini yitirir ve yalnızca bir kez kullanılabilir.',
        'reset_ignore' => 'Parola sıfırlama talebinde bulunmadıysanız yapmanız gereken bir şey yok — parolanız değişmedi.',
        'changed_subject' => 'Parolanız değiştirildi',
        'changed_intro' => 'Parolanız az önce değiştirildi.',
        'changed_via_reset' => 'Parolanız az önce bir sıfırlama bağlantısı ile yenilendi.',
        'changed_sessions_revoked' => 'Güvenliğiniz için tüm oturumlarınız sonlandırıldı.',
        'changed_warning' => 'Bu işlemi siz yapmadıysanız hemen destek ekibiyle iletişime geçin — hesabınıza başka biri erişmiş olabilir.',
        'verify_subject' => 'E-posta adresinizi doğrulayın',
        'verify_intro' => 'Hesabınızın kurulumunu tamamlamak için lütfen e-posta adresinizi onaylayın.',
        'verify_button' => 'E-posta adresini doğrula',
        'verify_expiry' => 'Bu bağlantının süresi :minutes dakika içinde dolar.',
        'verify_ignore' => 'Bir hesap oluşturmadıysanız başka bir işlem yapmanıza gerek yok.',
        'otp_subject' => 'Giriş kodunuz',
        'otp_intro' => 'Girişi tamamlamak için bu tek kullanımlık kodu kullanın:',
        'otp_expiry' => 'Kodun süresi :minutes dakika içinde dolar ve yalnızca bir kez kullanılabilir.',
        'otp_ignore' => 'Giriş yapmaya çalışmadıysanız parolanızı biri ele geçirmiş olabilir — hemen değiştirin.',
        // Hesap sahibine şüpheli giriş uyarısı (Q6).
        'suspicious_subject' => 'Hesabınızda olağan dışı giriş etkinliği',
        'suspicious_intro' => 'Hesabınızda çok sayıda başarısız giriş denemesi tespit ettik.',
        'suspicious_action' => 'Bu siz değilseniz parolanızı hemen değiştirin ve henüz açmadıysanız iki adımlı doğrulamayı etkinleştirin.',
        'suspicious_reassure' => 'Bu denemeler başarılı olmadı. Parolanız hâlâ geçerli ve değiştirilmedi.',
        // Yöneticilere güvenlik uyarısı (Q6).
        'admin_alert_subject' => 'Güvenlik uyarısı: saldırı altındaki hesap',
        'admin_alert_intro' => 'Bir hesaba yönelik sürekli bir giriş saldırısı tespit edildi.',
        'admin_alert_detail' => 'Adres: :email — :ips farklı IP adresinden :count deneme.',
        'admin_alert_action' => 'Tam durum için güvenlik denetim kaydını inceleyin.',
    ],

    'email_verified' => 'E-posta adresiniz doğrulandı.',
    'verification_sent' => 'Doğrulama bağlantısı gönderildi.',

    // Yönetici arayüzü (Aşama 8).
    'user_updated' => 'Hesap güncellendi.',
    'admin_reset_sent' => 'Kullanıcıya parola sıfırlama bağlantısı gönderildi.',
    'admin_two_factor_disabled' => 'Hesap için iki adımlı doğrulama kapatıldı.',

    'sessions_revoked' => 'Diğer oturumlar sonlandırıldı.',
    'device_trusted' => 'Bu cihaz güvenilir olarak işaretlendi.',

    'two_factor_enabled' => 'İki adımlı doğrulama etkinleştirildi.',
    'two_factor_disabled' => 'İki adımlı doğrulama kapatıldı.',
    'two_factor_enrolment_started' => 'QR kodunu doğrulayıcı uygulamanızla tarayın, ardından bir kodla onaylayın.',
    'recovery_codes_generated' => 'Yeni kurtarma kodları oluşturuldu. Şimdi kaydedin — yalnızca bir kez gösterilir.',
    'email_otp_sent' => 'Bir koda ihtiyaç varsa e-posta adresinize gönderildi.',

];
