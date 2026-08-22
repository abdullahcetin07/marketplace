# BUILD — Teslimat sonrası "yorum bırak" daveti (backend)

**Amaç:** Teslim edilen siparişlerden bir süre sonra müşteriye **tek seferlik**,
KVKK-uyumlu bir **yorum daveti** e-postası göndermek → yorumlar birikir → ürün
`aggregateRating` dolar → **Google yıldızlı sonuç (rich result)** + AI/EEAT sinyali.
SEO audit'inin en yüksek kaldıracı buydu: yorum verisi var ama akmıyor.

Bu iş **backend**. Storefront (desktop) ayrı; dokunulmaz.

---

## Mimari ve sınırlar

- **Hiçbir modül import edilmez.** Tetikleyici + okuma Core contract'ları ve
  **class-string event** ile. `LayeringTest` yeşil kalmalı.
- **Tetikleyici olay:** `ShipmentDelivered` (Shipping emit ediyor, ADR-064; Payment zaten
  class-string ile tüketiyor — aynı desen).
- **Sweep tercih et, uzun-gecikmeli job DEĞİL** (ADR-072 dersi). Teslimat anında değil,
  **gecikmeli** davet — alıcı ürünü kullansın.

## Ne yapılacak

### 1) Günlük sweep — `reviews:request-pending` (veya `notifications:review-requests`)
- **`delivered_at + settings('reviews.request_delay_days')`** (default **3**) geçmiş,
  **henüz yorumlanmamış** ve **daha önce davet gönderilmemiş** teslim edilmiş order line'ları bulur.
- Her (müşteri, ürün) için **tek** davet gönderir, "gönderildi" işaretler.
- **Idempotent:** `review_requests(order_line_uuid UNIQUE, sent_at)` küçük tablosu — bir kez.
  (Ledger idempotency deseni: hem kontrol hem unique constraint.)
- Okuma:
  - Teslim edilmiş satırlar + tarih: `OrderQueryContract::deliveredPurchaseLines()` +
    `ShipmentQueryContract::deliveredBefore()` (ikisi de mevcut — Reviews/Loyalty kullanıyor).
  - Yorum var mı: `ReviewQueryContract` (order_line için yorum mevcut mu). Varsa davet **atla**.
- **Scheduler:** günlük (ör. 10:00), `raftabul-scheduler` (`schedule:work`), `->onOneServer()`.
  Scheduler'ın çalıştığı doğrulanmış (ADR-072).

### 2) Bildirim — yeni `NotificationType::ReviewRequested`
- Kanal: **e-posta (SES)**; istenirse in-app da. `BaseNotification implements ShouldQueue`
  (kuyruklu — SES prod erişimi gelince ulaşır; şimdilik queue'da bekler, 500 yok).
- İçerik (Türkçe, marka sesi): "Aldığın **{ürün}** nasıldı? Birkaç dakikanı ayır,
  **değerlendir** — hem başkalarına yardım et hem **puan kazan**." (Loyalty "Yorum Yap
  Kazan", ADR-082 ile tutarlı.)
- **CTA link:** o teslim edilen satırın değerlendirme akışı — `/hesap/siparislerim`
  (veya varsa doğrudan ürünün değerlendirme sayfası). `NEXT_PUBLIC_SITE_URL` tabanlı.
- Alt bilgi: işleten AMIAY, iletişim, **abonelikten çık** linki.

## ⚖️ KVKK / ETK
- Bu **kendi siparişi hakkında hizmet-bağlantılı** bir bildirim (deneyim daveti), saf
  kampanya değil — ama sınırda. Güvenli taraf: **tek seferlik**, bildirim tercihlerine
  saygılı (pazarlama iletisinden çıkmış kullanıcıya gönderme), her iletide **kolay çıkış**.
- Bkz. [[raftabul-email]] işlemsel-vs-pazarlama ayrımı.

## Config (yeni)
- `reviews.request_delay_days` = 3
- (ops.) `reviews.request_enabled` = true — kapatma anahtarı.

## Testler
1. Teslim + delay geçmiş + yorumsuz satır → **tek** davet; ikinci sweep **tekrar
   göndermez** (idempotent, unique).
2. Zaten yorumlanmış satır → davet **yok**.
3. Delay dolmamış teslimat → davet **yok**.
4. Pazarlama-iletisinden çıkmış / bildirimi kapalı kullanıcı → davet **yok**.
5. `NotificationType::ReviewRequested` kuyruğa düşer; SES sandbox'ta 500 yok.
6. Hiçbir modül import edilmiyor (LayeringTest); okuma Core contract'larından.
7. Scheduler kaydı mevcut (`schedule:list`'te görünür).

## Non-goals
- Yorum başına birden çok hatırlatma (v1 tek davet; "2. hatırlatma" gelecekte).
- SMS (v1 e-posta).
- Storefront değişikliği (değerlendirme akışı zaten var; bu yalnızca daveti yollar).

## Bağımlılık / sonraki
- Davet → yorum → **`aggregateRating`**: yorum verisi biriktikçe, storefront ürün
  JSON-LD'sine aggregateRating eklenmesi (ayrı storefront işi, paralel yürüyor) yıldızları
  SERP'e taşır. Bu iki parça birlikte "yıldızlı sonuç" kaldıracını tamamlar.
- SES **prod erişimi** onaylanınca davetler gerçek müşteriye ulaşır (`queue:retry`).
