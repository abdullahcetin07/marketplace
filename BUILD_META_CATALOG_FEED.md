# İş Emri — Meta (Facebook/Instagram) Katalog Feed'i (dinamik/Advantage+ reklam)

**Amaç:** Meta'da **dinamik ürün reklamı** (bir ziyaretçiye baktığı/sepete attığı ürünü
katalogdan otomatik gösteren reklam) için Meta Commerce Manager'a bir **ürün feed'i**
yayınlamak. Storefront'taki Meta Pixel zaten canlı ve doğrulandı (content_ids/value/eventID
gidiyor); eksik olan tek şey, pixel'in gördüğü ürünlerle eşleşecek bir **katalog**.

Bu **backend (Catalog modülü) işi.** Storefront'ta değişiklik YOK — pixel `content_ids`'i
zaten **ürün uuid** basıyor; feed de ürün uuid ile hizalanınca eşleşme tamamlanır.

---

## 0. Load-bearing karar — `id` = ÜRÜN uuid (varyant değil)

Meta, dinamik reklamda pixel'in gönderdiği `content_ids`'i katalog item `id`'siyle eşler.

| | Kaynak | id alanı |
|---|---|---|
| Meta Pixel `content_ids` | `dataLayer` (storefront) | **ürün uuid** (`line.product_id`) |
| **Meta feed `id` (bu iş)** | bu feed | **ürün uuid** ← ZORUNLU |
| GMC feed `g:id` (mevcut) | `GoogleMerchantFeed` | varyant uuid (Google için, değişmez) |

Yani Meta feed'i, GMC feed'inin **`g:id` = ürün uuid** varyantıdır. GMC feed'i **aynen
kalır** (Google tarafı varyant uuid'yi kullanıyor, dokunma). Bu ayrım
[GoogleMerchantFeed.php:337](app/Modules/Catalog/Application/Services/GoogleMerchantFeed.php)
satırındaki tek karar.

---

## 1. Ne üretilecek

`GoogleMerchantFeed`'i (RSS 2.0 + `g:` namespace — **Meta bu formatı doğrudan ingest eder**)
neredeyse birebir yeniden kullan. **Satır başına bir ÜRÜN** (mevcut feed zaten öyle:
default variant üzerinden, [GoogleMerchantFeed.php:418](app/Modules/Catalog/Application/Services/GoogleMerchantFeed.php) `variantFor`).

İçerik farkları GMC'ye göre yalnızca:
- **`g:id` = `$product->uuid`** (varyant uuid DEĞİL).
- **`g:item_group_id` YOK** — grup id artık ürün seviyesiyle çakışır; ürün-seviyesi
  katalogda gerek yok, çıkar.
- Diğer HER ŞEY aynı: `title`, `description`, `link` (flat slug, ADR-059), `g:image_link`
  + `g:additional_image_link`, `g:availability`, `g:price` (**KDV-dahil**, minor→decimal
  string + currency, ADR-005/055/061), `g:brand`, `g:gtin` + `g:identifier_exists`,
  `g:condition=new`, `g:product_type` (breadcrumb), `g:shipping` = 0.00 (v1 kargo bedava,
  ADR-063).

> Uygulama önerisi (server kararı): `GoogleMerchantFeed`'e bir **mod** ekle
> (`idStrategy: variant|product`, `emitItemGroup: bool`, hedef `path`), ya da ortak bir
> base'e çıkar. **GMC feed davranışı bit-bit değişmemeli** — mevcut testleri kırma.

---

## 2. Route

Yeni dosyayı `/feed/meta-catalog.xml` altında yayınla — GMC ile aynı desen
([routes/web.php:94](routes/web.php)). **nginx `/feed` bloğu zaten var** (web.php:86 notu),
yeni dosya aynı bloktan servis edilir → **ek nginx işi yok.**

```php
Route::get('/feed/meta-catalog.xml', [MetaCatalogFeedController::class, 'show'])
    ->name('feed.meta-catalog');
```

Controller, GMC controller'ıyla aynı: dosyayı diskten okuyup verir, iş yapmaz; dosya yoksa
404 ([GoogleMerchantFeedController](app/Modules/Catalog/Presentation/Controllers/Api/Storefront/GoogleMerchantFeedController.php)).

---

## 3. Command + schedule

`feed:build-meta-catalog` (GMC'nin `feed:build-google-merchant` eşi,
[routes/console.php:229](routes/console.php)). Ya iki feed'i tek command'de üret, ya ayrı
command + ayrı schedule satırı. Aynı gece penceresine koyabilirsin.

**⚠️ Scheduler çalışıyor olmalı** — GMC feed'i gibi bu da queue/scheduler'a bağlı; worker
yoksa feed yaşlanır (ADR-072 dersi).

---

## 4. Config (`config/feed.php`)

- **İstisnaları PAYLAŞ, kopyalama:** `feed.google.excluded_category_slugs` +
  `feed.google.excluded_title_keywords` **aynen Meta'ya da uygulanır.** Meta'nın sağlık/
  takviye/medikal/cinsel sağlık politikası Google kadar (bazı yerde daha) katı; iki liste
  ayrışırsa biri onaysız kalır. Aynı `isExcluded` + `isExcludedByTitle` + `TurkishFold`
  mantığı kullanılır.
- **Ekle:** `feed.meta.path` = `'feeds/meta-catalog.xml'`. `storefront_url` GMC'dekiyle
  aynı (`feed.google.storefront_url`).

---

## 5. Güvenlik rayları (GMC'den aynen)

- **Boş feed iyisini ezmez** — 0 item yazılırsa eski dosya korunur, rapor `published=false`
  ([GoogleMerchantFeed.php:177](app/Modules/Catalog/Application/Services/GoogleMerchantFeed.php)).
- **Temp dosya + rename** (yarım XML Meta fetch'ini bozmasın).
- **Chunk'lı okuma**, iki cross-module çağrı (fiyat `OfferQueryContract`, stok
  `InventoryQueryContract`) chunk başına bir kez — strict-mode lazy-load YOK.
- **Sadece satılabilir ürün** (`status=Published` + `is_sellable` + buy box fiyatı olan).
  Offer'ı olmayan ürün feed'e girmez.
- **Availability:** Google formatı değerleri (`in_stock`/`out_of_stock`) — Meta, Google-
  format feed'de bunları eşler.
- **Dahili integer `id` asla feed'de görünmez** (ADR-005 §7) — bir test bunu doğrulasın.

---

## 6. Testler

- `LayeringTest` + `CatalogBoundaryTest` **yeşil kalır** — modül import yok, Catalog'a fiyat/
  stok kolonu eklenmez (fiyat/stok yine Core contract'larından).
- Yeni: feed item `id`'si = **ürün uuid** (varyant uuid DEĞİL) — 2+ satırlı fixture ile
  (strict-mode lazy-load ancak 2+ satırda tetiklenir, CLAUDE.md notu).
- Sağlık istisnalarının Meta feed'ine de uygulandığı (kategori + başlık anahtar kelime).
- `g:item_group_id` üretilmediği.
- Boş-feed-iyisini-ezmez korumasının Meta yolunda da çalıştığı.

`make check` geçmeli.

---

## 7. Owner adımları (Meta Commerce Manager) — feed yayınlandıktan sonra

1. **Commerce Manager → Katalog.** Mevcut 3 katalogdan birini seç ya da **yeni temiz bir
   katalog** aç (tür: **E-ticaret**). Tek bir kataloğu kullanacağız; diğerlerini şimdilik
   bırak (silme — geri alınamaz).
2. **Veri Kaynakları → Ürün Ekle → Toplu (Data Feed) → Planlı feed (Scheduled feed).**
3. Feed URL: `https://raftabul.com/feed/meta-catalog.xml`. Çekim sıklığı: **Günlük**
   (feed gece güncelleniyor).
4. **Ülke/para birimi:** Türkiye / **TRY**.
5. **Pixel'i kataloğa bağla:** Katalog → Ayarlar → **Etkinlik kaynakları (Event sources)**
   → bu kataloğu **Meta Pixel'e bağla.** Dinamik eşleşme bunu gerektirir.
6. Feed işlenince ürün sayısını kontrol et (feed'deki `written` sayısıyla uyumlu olmalı).

---

## 8. Doğrulama

- `curl -sSI https://raftabul.com/feed/meta-catalog.xml` → 200 + `application/xml`.
- `curl -s https://raftabul.com/feed/meta-catalog.xml | head -40` → ilk `<item>`'ın
  `<g:id>` değeri bir **ürün** uuid mi (bir üründe pixel'in bastığıyla aynı mı) kontrol et.
- Meta Commerce Manager feed durumu: 0 hata / kabul edilen ürün sayısı makul.
- Dinamik reklam kurulunca: **Etkinlik Yöneticisi → katalog eşleşme oranı** yüksek olmalı
  (content_ids ↔ feed id hizalı olduğu için).

---

## Not — neden ayrı feed, pixel'i değiştirmek yerine
Alternatif (varyant uuid'ye geçip pixel'i ona çevirmek) storefront'u karmaşıklaştırır:
`view_item`'da hangi varyant belli değil (buy box offer seçer). Pixel'in doğal granülaritesi
**ürün**; feed'i ürün-seviyesi yapmak en temiz ve ileri-uyumlu yol. Çok-varyantlı ürünler
(faz 2) yine tek ürün satırıyla temsil edilir.
