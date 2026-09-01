# Meta (Facebook/Instagram) Pixel — GTM kurulumu

Storefront'ta **dataLayer + GTM + KVKK Consent Mode** zaten kurulu (ADR-085). Meta
Pixel'i **GTM'e** ekleyip mevcut GA4 ecommerce event'lerini Meta standart event'lerine
bağlıyoruz. **Kod değişikliği yok** — hepsi GTM arayüzünde. Owner yapar; **Pixel ID
chat'e girmez**, GTM'e sen girersin.

## Ön koşul (Meta Business Manager — sen)
1. **Business Manager** hesabı + **Sayfa** (var: /raftabul + @raftabulcom).
2. **Reklam Hesabı** (ödeme yöntemi ekli).
3. **Veri Kaynağı → Pixel/Dataset oluştur** → **Pixel ID**'yi al (16 haneli sayı).

## dataLayer (zaten var — referans)
Storefront şu event'leri basıyor (`lib/analytics.ts`):
```
{ event: 'view_item'      , ecommerce: { currency, value, items:[{item_id,...}] } }
{ event: 'add_to_cart'    , ecommerce: { currency, value, items:[{item_id,...}] } }
{ event: 'begin_checkout' , ecommerce: { currency, value, items:[{item_id,...}] } }
{ event: 'purchase'       , ecommerce: { currency, value, transaction_id, items:[{item_id,...}] } }
```

## GTM'de kur (adım adım)

### A. Değişkenler (Variables)
1. **Constant** `META_PIXEL_ID` = *(senin Pixel ID'n)*.
2. **Data Layer Variable** — `DLV - ecommerce.value` (Data Layer Variable Name: `ecommerce.value`).
3. **DLV** `DLV - ecommerce.currency` → `ecommerce.currency`.
4. **DLV** `DLV - ecommerce.items` → `ecommerce.items`.
5. **DLV** `DLV - ecommerce.transaction_id` → `ecommerce.transaction_id`.
6. **Custom JavaScript** `JS - meta content_ids` (Meta content_ids/contents üretir):
   ```javascript
   function () {
     var items = {{DLV - ecommerce.items}} || [];
     return items.map(function (i) { return String(i.item_id); });
   }
   ```
7. **Custom JavaScript** `JS - meta contents`:
   ```javascript
   function () {
     var items = {{DLV - ecommerce.items}} || [];
     return items.map(function (i) {
       return { id: String(i.item_id), quantity: i.quantity || 1 };
     });
   }
   ```

### B. Base Pixel etiketi (Custom HTML) — PageView
- **Tag → Custom HTML:**
  ```html
  <script>
  !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
  n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
  document,'script','https://connect.facebook.net/en_US/fbevents.js');
  fbq('init', {{META_PIXEL_ID}});
  fbq('track', 'PageView');
  </script>
  ```
- **Trigger:** All Pages (ya da Initialization) — **AMA consent'e bağla** (aşağıya bak).

### C. Event etiketleri (Custom HTML) — 4 adet
Her biri **Custom HTML**, ilgili GA4 event'inde tetiklenir. Her etiket **Base Pixel'den
SONRA** çalışmalı (Tag Sequencing: "Base Pixel" setup tag olarak seçilebilir) — ya da
base All Pages'te olduğu için önce yüklenmiş olur.

| GA4 event | Meta event | Custom HTML gövdesi |
|---|---|---|
| `view_item` | `ViewContent` | `fbq('track','ViewContent',{content_type:'product',content_ids:{{JS - meta content_ids}},value:{{DLV - ecommerce.value}},currency:{{DLV - ecommerce.currency}}});` |
| `add_to_cart` | `AddToCart` | `fbq('track','AddToCart',{content_type:'product',content_ids:{{JS - meta content_ids}},contents:{{JS - meta contents}},value:{{DLV - ecommerce.value}},currency:{{DLV - ecommerce.currency}}});` |
| `begin_checkout` | `InitiateCheckout` | `fbq('track','InitiateCheckout',{content_type:'product',content_ids:{{JS - meta content_ids}},contents:{{JS - meta contents}},value:{{DLV - ecommerce.value}},currency:{{DLV - ecommerce.currency}}});` |
| `purchase` | `Purchase` | `fbq('track','Purchase',{content_type:'product',content_ids:{{JS - meta content_ids}},contents:{{JS - meta contents}},value:{{DLV - ecommerce.value}},currency:{{DLV - ecommerce.currency}}}, {eventID:{{DLV - ecommerce.transaction_id}}});` |

Gövdeyi `<script>fbq(...)</script>` içine sar.

- **Trigger'lar:** her biri **Custom Event**, Event name = `view_item` / `add_to_cart` /
  `begin_checkout` / `purchase`.
- `eventID` (Purchase) — ileride **Conversions API** eklenirse tarayıcı+sunucu event'i
  **tekilleştirir** (çift sayım olmaz). Şimdilik zararsız.

### D. 🔴 KVKK / Consent — ZORUNLU
Meta pixel bir **pazarlama** etiketidir; **izin verilmeden ateşlenmemeli.**
- GTM'de her Meta etiketinde **Consent Settings → "Require additional consent for tag to
  fire"** = `ad_storage` (ve/veya `ad_user_data`, `ad_personalization`).
- Consent Mode v2 zaten kurulu (banner `ad_storage`'ı günceller). Böylece kullanıcı
  **"Kabul Et" demeden pixel yüklenmez** — mevcut Google kurulumuyla aynı davranış.
- Alternatif: base+event etiketlerini, banner'ın consent event'ini tetikleyici yaparak da
  bağlayabilirsin; ama Consent Settings yolu daha temiz.

## Test (yayınlamadan)
1. GTM **Preview** + **Meta Pixel Helper** (Chrome eklentisi).
2. Ana sayfa → **PageView** (yalnız consent verildikten sonra).
3. Ürün → **ViewContent** (content_ids dolu, value/currency doğru).
4. Sepete ekle → **AddToCart**; ödeme → **InitiateCheckout**; başarılı ödeme → **Purchase**
   (value = sipariş tutarı, transaction_id `eventID`'de).
5. Consent reddedilince **hiçbiri ateşlenmiyor** — doğrula.
6. Yeşilse GTM'i **Submit/Publish**.

## Sonra (opsiyon, v2)
- **Conversions API (CAPI)** — sunucu-taraflı Purchase/AddToCart (iOS + ad-blocker kaybını
  kapatır). Ayrı iş; `eventID` şimdiden hazır olduğu için tekilleştirme sorunsuz olur.
- **Katalog** (Advantage+ katalog reklamı) — GMC feed'imizi Meta Katalog'a da bağlayabiliriz
  (dinamik ürün reklamı); GMC onayından sonra bakarız.

## Not
- Kod/storefront değişmez — dataLayer zaten hazır.
- **Reklam politikası:** kozmetik serbest; takviye + sağlık iddiası + "kişisel özellik"
  hedefleme kısıtlı → kreatifte kozmetik öne çıkar, iddia yok.
