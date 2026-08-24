# BUILD — Yedek e-posta sağlayıcısı (HTTP API) — transactional teslimat

**Sorun:** SES **sandbox'ta** (production access AWS tarafından reddedildi) → gerçek
müşteriye e-posta ulaşmıyor (şifre sıfırlama, doğrulama, sipariş/kargo). Host **SMTP
portlarını kapatıyor** (bu yüzden SES'e HTTP API ile geçmiştik) → yedek sağlayıcı da
**HTTP API** olmalı.

**Çözüm:** Bir HTTP-API sağlayıcısı ekle (öneri: **Resend**; alternatif: Postmark).
Mevcut SES config'i **silme** — SES onaylanırsa geri dönebiliriz.

Bu iş **backend**. Storefront'a dokunma.

---

## ⚠️ Kimlik bilgileri kuralı
API anahtarını **sunucudaki `.env`'e sahibi doğrudan yazar** — asistan/chat anahtarı görmez
(PayTR/SES'te olduğu gibi). İş emri anahtarı içermez.

## Adımlar (Resend)

1. **Driver:** `composer require resend/resend-laravel` (Laravel resmi driver).
2. **Config:** `config/mail.php` mailer'lara `resend`, `config/services.php`'ye
   `resend => ['key' => env('RESEND_API_KEY')]`. `MAIL_MAILER=resend`.
   - **From:** `noreply@raftabul.com` (veya `destek@raftabul.com`) — tutarlı, doğrulanmış alan.
3. **Alan doğrulama (DNS — sahibi Cloudflare'de ekler):** Resend panelinde `raftabul.com`
   ekle → verdiği **DKIM (+ SPF/return-path)** kayıtlarını **Cloudflare DNS**'e ekle →
   doğrula. (SPF/DMARC zaten var; Resend'in kendi DKIM'i eklenecek.)
4. **`.env` (sahibi sunucuda):** `RESEND_API_KEY=...`, `MAIL_MAILER=resend`,
   `MAIL_FROM_ADDRESS=noreply@raftabul.com`, `MAIL_FROM_NAME="Raftabul"`.
5. **(Opsiyonel, sağlam) Failover:** `MAIL_MAILER=failover` + `config/mail.php` failover
   dizisi `['resend', 'ses']` → önce Resend, olmazsa SES. (SES sandbox'ta gerçek alıcıya
   ulaşmaz ama zarar vermez; onay gelince otomatik ikinci hat olur.)
6. **Bounce/complaint:** Resend webhook'larını (bounce/complaint/delivered) etkinleştir;
   suppression'ı sağlayıcı yönetir. (İyi uygulama + reputation.)

## Test / kabul
1. Gerçek bir adrese **şifre sıfırlama** ve **e-posta doğrulama** gönder → **ulaşıyor**,
   **DKIM pass** (Gmail "show original" ile doğrula).
2. Bir **sipariş onayı** akışını tetikle → ulaşıyor.
3. **`php artisan queue:retry all`** (SES sandbox'ta biriken başarısız bildirimler artık
   Resend'den gider) — ya da yeni gönderimlerle doğrula.
4. Kuyruk çalışıyor (`raftabul-horizon`), `BaseNotification implements ShouldQueue` → 500 yok.

## Non-goals
- Pazarlama kampanyası değil — bu **transactional teslimat**. (Pazarlama iletileri için
  KVKK/ETK-İYS izni + ayrı akış; bkz. `.claude/skills/raftabul-email`.)
- Storefront değişikliği yok.
- SES'i kaldırma — yedek/failover olarak kalsın; SES onaylanırsa `MAIL_MAILER` geri çevrilir.

## Not (Postmark alternatifi)
İstenirse Postmark: `composer require symfony/postmark-mailer`, `MAIL_MAILER=postmark`,
`services.postmark.token`, Postmark panelinde Sender Signature + DKIM DNS. Saf
transactional teslimatta en iyi, ama pazarlama iletisine izin vermez (ayrı Broadcast stream
gerekir). Resend tek sağlayıcıda ikisini de yapabildiği için varsayılan öneri Resend.
