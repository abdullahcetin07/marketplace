# BUILD — Google Merchant Center ürün akışı (backend)

**Amaç:** Google Merchant Center'ın (GMC) çekeceği bir **ürün akışı (product feed)**
üretmek → Google Shopping ücretsiz listeleme + Shopping/PMax reklamları. Tek satıcı
modeli (platform = AMIAY tek merchant, ADR-060): feed **buy box kazananının** fiyat +
stokunu yansıtır, satıcı ayrımı yok.

Bu iş **backend**. Storefront tarafı (desktop) ayrı; burada dokunulmaz.

---

## Mimari ve sınırlar (ÖNCE OKU)

- **Hiçbir modül import edilmez.** Feed, veriyi yalnızca **Core contract'ları** üzerinden
  okur — tıpkı `PublicProductController`'ın buy box'ı okuduğu gibi. `LayeringTest` yeşil
  kalmalı.
  - Katalog: `CatalogBrowseContract` (`searchPublishedProducts`, `productSummaries`,
    `variantSummaries`, `productClassifications`) + `CatalogQueryContract`.
  - Fiyat: `OfferQueryContract` (`sellableProductUuids`, `featuredOfferForProduct`,
    `buyBoxPricesFor`).
  - Stok: `InventoryQueryContract` (`availableFor`, `availableKeysAmong`).
- **Yerleşim önerisi:** Katalog `Presentation/Controllers/Api/Storefront/` altında yeni bir
  hafif controller + `Application` altında bir feed servisi/komutu. Bu, storefront public
  uçlarıyla aynı desendir ve `CatalogBoundaryTest`'i bozmaz — **Katalog şemasına fiyat/stok
  KOLONU eklenmiyor**; fiyat/stok yalnızca Core contract'ından okunuyor (izinli seam).
- **Para = minor units içeride; sınırda decimal string.** Feed fiyatı `"129.90 TRY"`
  (nokta ayraç, 2 hane). Float yok.
- **Fiyat KDV DAHİL** — storefront buy box'ta müşteriye gösterilen brüt fiyatın aynısı
  (ADR-055/061; TR pazarı KDV-dahil, feed'e ayrı `tax` düğümü EKLENMEZ).
- **Public identifier = UUID.** Feed `id` alanı **variant uuid**; iç integer `id` asla
  çıktıya sızmaz (bir test bunu doğrular).
- **Strict mode:** 20k satırı ilişki tembel-yüklemesiyle gezme. **Chunk** (ör. 500'lük)
  + toplu Core çağrıları (`productSummaries`, `buyBoxPricesFor`, `availableKeysAmong`) +
  eager-load. İki+ satırlı fixture ile test et (tek satır lazy-load tuzağını göstermez).

---

## Ne üretilecek

### 1) Derleme komutu — `feed:build-google-merchant`
- Tüm **satılabilir** ürünleri (`is_sellable`, ADR-079 + canlı buy box) gezer, her
  **satılabilir variant** için bir feed öğesi üretir (v1'de ürün başına genelde tek
  default variant).
- Çıktıyı **dosyaya** yazar: `storage/app/public/feeds/google-merchant.xml` (+ `.xml.gz`).
  Google fetch'i dosyayı okur — HTTP isteğinde 20k üretme yok, timeout riski yok.
- **Akışkan/stream yazım** (bellek şişmesin): başlığı yaz → chunk'ları döngüyle ekle →
  kapat. XML text'leri UTF-8 + kaçışlı (`& < >`).
- **Rapor (stdout + log):** toplam satılabilir, feed'e giren, ayıklanan (boş açıklama),
  ayıklanan (politika/kategori), GTIN'siz sayısı. Bu sayılar açıklama backfill'ini yönetir.

### 2) Servis rotası — `GET /feed/google-merchant.xml`
- Derlenmiş dosyayı `Content-Type: application/xml; charset=utf-8` ile döndürür (gzip kabul
  edilirse `.gz`). **Public** (tüm veri zaten storefront'ta halka açık).
- **Opsiyonel token:** `config/settings('feed.google.access_token')` doluysa `?key=` şart
  koş (Google scheduled fetch URL'e parametre kabul eder). Boşsa serbest.
- CSRF muaf (GET, oturumsuz).

### 3) Zamanlama
- `feed:build-google-merchant` **günlük** çalışır (ör. 04:15), `raftabul-scheduler`
  (`schedule:work`) üstünde — scheduler'ın çalıştığı doğrulanmış (ADR-072). `->onOneServer()`.
- Fiyat/stok günlük döner; v1 günlük yeterli. (Gerçek-zamanlı fiyat&stok supplemental feed'i
  gelecekte.)

---

## Alan eşleme (RSS 2.0, `g:` ad alanı)

Kök: `<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>…<item>…`

| Feed alanı | Kaynak | Not |
|---|---|---|
| `g:id` | variant uuid | Kararlı, public. İç id ASLA. |
| `g:item_group_id` | product uuid | Yalnızca ürünün >1 satılabilir variantı varsa; yoksa atla. |
| `title` | ürün başlığı | Çok variantsa variant etiketini ekle. Maks 150 karakter. |
| `description` | ürün açıklaması (localized) | **HTML strip**, düz metin. **Boşsa/zayıfsa (<30 karakter) öğe ATLANIR** ve raporlanır (GMC boş/zayıfı reddeder). |
| `link` | `{storefront_url}/{product.slug}` | `config('feed.google.storefront_url')`, default `https://raftabul.com`. Flat slug (ADR-059). |
| `g:image_link` | birincil ürün görseli (mutlak URL) | `HasMedia` `large`/gallery[0]. Mutlak (`https://raftabul.com/...`). Yoksa öğe atlanır (Google görsel şart). |
| `g:additional_image_link` | ek görseller | En çok 10. |
| `g:availability` | `InventoryQueryContract::availableFor(variant, buyBoxOrg)` | `>0` → `in_stock`, değilse `out_of_stock`. |
| `g:price` | buy box fiyatı (KDV dahil) | `buyBoxPricesFor`/`featuredOfferForProduct`. `"AMOUNT TRY"`, nokta, 2 hane. |
| `g:brand` | marka adı | |
| `g:gtin` | variant GTIN | Varsa yaz (güçlü eşleşme; katalog GTIN-anahtarlı). |
| `g:identifier_exists` | GTIN+marka varsa `yes`, yoksa `no` | GTIN yoksa `no` (veya `mpn` varsa onu ver). |
| `g:condition` | sabit `new` | |
| `g:product_type` | kategori breadcrumb ("Cilt Bakımı > Nemlendiriciler") | Serbest metin; bizim taksonomi. |
| `g:google_product_category` | (v1 opsiyonel) | Tam eşleme sonraya; boş bırak → Google otomatik atar. |
| `g:shipping` | TR, `"0.00 TRY"` | Kargo bedava (ADR-063). Panelden de ayarlanabilir; yine de açıkça yaz. |

> **TR KDV-dahil:** fiyat brüt olduğundan ayrı `tax` düğümü YOK.

---

## Politika kancası (sağlık/takviye)

- Google Shopping bazı **takviye/sağlık** ürünlerini kısıtlar. `config` ile
  **`feed.google.excluded_category_slugs`** (dizi) tanımla: bu kategori (ve alt) ürünleri
  feed'den çıkar. v1 boş; GMC bir kategoriyi işaretledikçe owner buraya ekler.
- İleride ürün/marka bazlı istisna gerekebilir; v1 kategori-slug yeterli.

---

## Config (yeni)

`config/feed.php` (veya settings):
- `feed.google.storefront_url` = `https://raftabul.com`
- `feed.google.access_token` = `''` (boşsa public)
- `feed.google.excluded_category_slugs` = `[]`
- `feed.google.min_description_length` = `30`

---

## Testler (Feature, pgsql yolu dahil)

1. **Derleme + sayım:** N satılabilir ürün → feed öğe sayısı = (satılabilir ∧ açıklaması
   yeterli ∧ görseli var); ayıklanan sayıları raporlanır.
2. **XML iyi biçimli** + `g:` ad alanı; her öğede zorunlu alanlar (`id,title,description,
   link,image_link,price,availability,brand,condition`).
3. **Fiyat KDV dahil brüt**, `"X.XX TRY"` (buy box ile birebir; float değil).
4. **`link` = storefront_url + slug**; **`image_link` mutlak**.
5. **GTIN** variantta varsa yazılır; `identifier_exists` doğru.
6. **Satılamaz + boş-açıklama + görselsiz ATLANIR**; `out_of_stock` doğru işaretlenir.
7. **İç integer id çıktı YOK** (yalnız uuid) — çıktı stringinde `id` kolon değeri aranır.
8. **Rota** dosyayı doğru content-type ile verir; token doluyken yanlış/eksik `?key=` → 403/404.
9. **Strict mode:** ≥2 satırlı fixture ile lazy-load throw etmediği (eager/batch) doğrulanır.

---

## Non-goals (v1 DIŞI)

- Satıcı bazlı ayrı feed (tek merchant).
- Gerçek-zamanlı fiyat&stok API feed'i (günlük dosya yeterli).
- `google_product_category` tam eşlemesi (sonra).
- Promosyon feed'i / Shopping kampanya kurulumu (Ads tarafı, ayrı).
- Storefront değişikliği (bu iş sadece backend + feed).

---

## Bağımlılık (owner/desktop paralel)

**Açıklamalar boş** → çok ürün "description" yüzünden ayıklanır/GMC'de reddedilir. Feed
canlıya çıkarken [[raftabul-product-copy]] ile **özgün Türkçe açıklama backfill'i** paralel
yürümeli (yasal sağlık-beyanı sınırlarında). Feed raporu "boş açıklama nedeniyle ayıklanan"
sayısını verir → önceliklendirmeyi bu yönlendirir.

---

## Owner panel adımları (feed hazır olunca — bilgi)

1. GMC → site sahipliğini claim/verify (Search Console doğrulaması varsa otomatik gelebilir).
2. İşletme bilgisi + **Kargo: TR ücretsiz** + iade/gizlilik politika linkleri.
3. **Feeds → Scheduled fetch** → URL: `https://raftabul.com/feed/google-merchant.xml`
   (token varsa `?key=…`), günlük çekim.
4. Sağlık/kozmetik politikalarını gözden geçir; reddedilen kategorileri
   `excluded_category_slugs`'a bildir.
5. GMC ↔ Ads zaten bağlı → onaylanan ürünlerle Shopping/PMax.
