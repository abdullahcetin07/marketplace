# İş Emri — Google Ads Dönüşüm Takibi (purchase)

**Amaç:** Google Ads'in **satışa** optimize edebilmesi için `purchase` dönüşümünü Google
Ads'e tanıtmak. Şu an Arama kampanyası (`24202956630`, hesap `1240072274`) **tıklama
(Maximize Clicks)** optimize ediyor — çünkü dönüşüm takibi yok. Bu kurulunca teklifi
**Maximize Conversions / Target ROAS**'a çevirip Google harcamasını satışa yönlendiririz.

## Ön koşullar (hepsi HAZIR)
- **GA4 `purchase` olayı canlı** (ADR-085): storefront `/odeme/sonuc`'ta `purchase`
  dataLayer olayını basıyor — `value`, `currency`, `transaction_id` (+ artık `items`) ile.
- **GTM konteyneri canlı** + **Consent Mode v2 / KVKK** kurulu (banner `ad_storage`'ı
  günceller). **Storefront'ta yeni kod GEREKMEZ** — event zaten var.
- Google Ads hesabı: **`1240072274` (Rafta Bul, TRY)**.

---

## Yol A — GTM'de Google Ads dönüşüm etiketi (ÖNERİLEN)
Bidding için en sağlam yol: `transaction_id` ile tekilleştirme + Enhanced Conversions
opsiyonu. Meta Pixel'i GTM'e koymadık ama Google dönüşümü için GTM en temiz yer.

### 1) Google Ads'te dönüşüm eylemi oluştur (Owner)
Google Ads → **Hedefler → Dönüşümler → + Yeni dönüşüm eylemi → Web sitesi → Manuel kurulum
(Google Etiketi/GTM)**:
- **Kategori:** Satın alma (Purchase)
- **Değer:** "Her dönüşüm için farklı değer kullan" → para birimi **TRY**
- **Sayım:** **Her (Every)** — e-ticaret satışında her sipariş sayılır
- Kaydet → sana **Conversion ID** (`AW-XXXXXXXXX`) + **Conversion Label** verir. Bunları GTM'de
  kullanacağız. (ID public'tir; sırf label chat'e girmesin istiyorsan bana "hazır" de, GTM'de
  sen yapıştırırsın.)

### 2) GTM değişkenleri (zaten varsa atla)
- **DLV** `DLV - ecommerce.value` → `ecommerce.value`
- **DLV** `DLV - ecommerce.currency` → `ecommerce.currency`
- **DLV** `DLV - ecommerce.transaction_id` → `ecommerce.transaction_id`

### 3) İki etiket
- **Google Ads Conversion Linker** (Tag → *Conversion Linker*), tetikleyici **All Pages**.
  (Tıklama bilgisini çereze yazar; dönüşüm eşleşmesi için şart.)
- **Google Ads Conversion Tracking** (Tag → *Google Ads Conversion Tracking*):
  - Conversion ID + Label = 1. adımdakiler
  - **Conversion Value:** `{{DLV - ecommerce.value}}`
  - **Currency:** `{{DLV - ecommerce.currency}}`
  - **Transaction ID:** `{{DLV - ecommerce.transaction_id}}`  ← çift sayımı önler
  - Tetikleyici: **Custom Event**, event adı = `purchase`

### 4) 🔴 KVKK / Consent — ZORUNLU
Dönüşüm etiketi bir pazarlama etiketidir; **izin verilmeden ateşlenmemeli.**
- Her iki etikette **Consent Settings → "Require additional consent" = `ad_storage`**.
- Consent Mode v2 zaten kurulu → kullanıcı **"Kabul Et"** demeden ateşlenmez (Meta/GA4 ile
  aynı davranış).

### 5) Test + yayın
- GTM **Preview** + Google **Tag Assistant** → bir sandbox/gerçek ödeme sonrası `/odeme/sonuc`'ta
  dönüşüm ateşleniyor mu, `value`/`transaction_id` dolu mu?
- Google Ads → Dönüşümler → durum "Dönüşüm kaydediliyor" olana kadar (birkaç saat).
- Yeşilse GTM konteynerini **Submit/Publish**.

### 6) (Opsiyon) Enhanced Conversions
Eşleşmeyi artırır: checkout'taki e-postayı **hash'lenmiş** olarak gönderir. Google Ads dönüşüm
ayarında "Enhanced conversions" aç + GTM'de user-provided data değişkeni. KVKK açısından
hash'li ve consent'e bağlı olduğundan uygundur; ikinci fazda ekleyebiliriz.

---

## Yol B — GA4 import (en hızlı, kod/GTM yok)
GA4 zaten `purchase` topluyor; sadece bağla ve içe aktar:
1. **Google Ads → Araçlar → Bağlı hesaplar → Google Analytics (GA4) → Bağla** (ya da GA4
   Admin → Ürün bağlantıları → Google Ads).
2. **Google Ads → Dönüşümler → + Yeni → İçe aktar → GA4 → `purchase`** → içe aktar.
3. Bu dönüşümü **birincil (primary)** yap.
- Artısı: sıfır kod. Eksisi: GA4 attribution modeli + veri gecikmesi; Smart Bidding için
  A yolundaki native etiket biraz daha keskin.

**Öneri:** Hız istiyorsan **B** ile başla (bugün kurulur), sonra **A**'yı (native + enhanced)
ekleriz. İkisini aynı anda **birincil** yapma — çift sayım olur; biri primary, diğeri
"ikincil/gözlem".

---

## Kurulduktan sonra — teklifi satışa çevir
~**15-30 dönüşüm** birikince (Search'te birkaç gün):
- Kampanyayı **Maximize Clicks → Maximize Conversions**'a çevir (istersen **Pipeboard'dan ben
  yaparım**: `update_google_ads_campaign` / bidding), sonra veri oturunca **Target ROAS**.
- O noktada **Performance Max (Shopping)** de mantıklı olur (GMC feed + dönüşüm hazır olduğunda).

## Kim ne yapar
- **Owner:** Google Ads'te dönüşüm eylemi oluşturma + (A) GTM yayınlama ya da (B) hesap bağlama.
  Ben her adımı yönlendiririm; **Conversion Label/ID'yi sen yapıştırırsın** (chat'e girmesin).
- **Ben:** GTM etiket/değişken/consent ayarlarının tam reçetesi (yukarıda) + veri gelince
  Pipeboard'dan bidding'i satışa çevirme.
- **Storefront:** değişiklik yok — `purchase` event'i value/currency/transaction_id/items ile
  zaten basıyor.
