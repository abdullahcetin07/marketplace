# BUILD — Kademe 2: Meilisearch ile tipo-toleranslı, sıralı, öneren arama (ADR-090)

**Karar:** ADR-090 onaylandı — motor **Meilisearch**, **Scout** üzerinden (zaten kablolu).
Bu emir Kademe 1 fold'unu **silmez**, onu **fallback'e** indirir. Amaç: `seurm→serum`,
`uriaj→uriage` (tipo), **relevans sıralaması** (tam ad/çok satan üstte) ve **yazarken
öneri** (autocomplete). Kademe 1 zaten canlıda ve doğrulandı (şapka + Latin-1 + noktasız
ı + token-AND).

> **⚠️ Bu değişiklik ADR-090'ı ratifiye eder.** Merge ile birlikte AYNI değişiklikte:
> `docs/ADR-090-search-engine.DRAFT.md`'yi **kabul edilmiş ADR-090** olarak
> `Architecture_Decision_Record.md`'ye taşı **ve** `001_Architecture.md` amendment log'una
> satır ekle (ADR-003/018). Sprint/emir dokümanı ADR'yi ezmez; çelişki varsa dur.

## Onaylanan kararlar (ADR-090'ın kalan 3 sorusu — varsayılan, owner değiştirebilir)
2. **Meili↔Offer uzlaşması: tasarlandığı gibi.** Meili alakalı **Catalog id**'lerini sıralı
   döndürür → mevcut Offer-farkında sorgu bu kümeyi fiyat/stok/kullanıcı-sıralamasıyla süzer.
   Kullanıcı fiyata göre sıralamadıysa **Meili relevans sırası korunur**; sıraladıysa küme
   Meili'nin, sıra fiyatın. **Fiyat/stok Meili index'ine GİRMEZ** (CatalogBoundaryTest).
3. **Eş anlamlı sahipliği: v1'de sürüm-kontrollü config dosyası** (`config/catalog.php` içinde
   `search.synonyms`), `search:sync-settings` ile Meili'ye basılır. Admin UI **v2** (ayrı iş).
4. **"Motor down" gözlemlenebilirliği: yapısal log (warning) + health sinyali.** Fallback'e
   düşen her sorgu warning loglar; health-check arama motorunu `up/down` raporlar. Admin
   banner v2.

## İş — BACKEND

### 1. Altyapı
- Meilisearch'i serviste çalıştır (systemd/container). **Master key'i owner prod `.env`'e
  koyar** — bu repo anahtara dokunmaz.
- `SCOUT_DRIVER=meilisearch`, `SCOUT_QUEUE=true` → indexleme `search` kuyruğunda.
  **Kuyruk worker'ı özelliğin parçası** (yoksa index bayatlar; ADR-074 dersi).

### 2. Index ayarları (`Product::toSearchableArray` zaten var — sonlandır)
- **Aranan alanlar (öncelik):** başlık → marka → öne çıkan özellikler. **Fiyat/stok YOK.**
- **filterableAttributes:** kategori yolu, marka (facet). **Fiyat değil.**
- **sortableAttributes + ranking:** Meili defaultları (words→typo→proximity→attribute→
  exactness) + **özel kural**: `satış_adedi desc`, `stokta_olan` üste. (Satış adedi Catalog'a
  ait bir sinyal değilse, sıralama için türetilmiş/senkron bir alan kullan — fiyat değil.)
- **typoTolerance:** ≥5 harfte 1, ≥9 harfte 2.
- **synonyms:** config'ten (`güneş kremi↔güneş koruyucu↔spf`, `nemlendirici↔nemlendirme`,
  `uriaj↔uriage` vb.).
- Ayarları bir komutla senkron et: **`search:sync-settings`** (idempotent).
- **GTIN/SKU tam eşleşme** korunur — barkod fuzzy'ye kaçmaz (aşağıda).

### 3. `q` yolunu Scout'a bağla — `CatalogBrowse`
- Bugünkü `applyText` (fold'lu LIKE) yerine: `Product::search($q)` → **sıralı id listesi**.
- Bu id kümesini mevcut Offer-farkında listeleme sorgusuna ver; **fiyat/stok/sort/pagination
  filtrelenmiş küme üzerinde** çalışsın.
- **Sıra korunması:** kullanıcı sort seçmediyse Meili sırasını koru (id sırasıyla `ORDER BY`).
  Fiyat sortu seçtiyse fiyatla sırala.
- **GTIN/SKU exact short-circuit:** terim bir barkod/SKU'ya tam eşleşiyorsa Meili'yi atla,
  doğrudan o ürünü döndür (bugünkü davranış).

### 4. Fallback (dayanıklılık, ADR-084)
- Meili erişilemez/timeout → **Kademe 1 fold'lu `search_text` LIKE** yoluna düş (arama boşa
  düşmez, sadece tipo/sıralama kaybolur). Düşen her sorgu **warning loglar**; health `down`.

### 5. `suggest` endpoint (autocomplete verisi — storefront tüketir)
- **`GET /api/v1/search/suggest?q=`** → Meili önek araması → **en iyi ürünler + markalar +
  kategoriler** (her biri kapaklı, ör. 6/4/4). Hızlı, hafif (fiyat/stok Offer'dan iliştirilebilir
  ama zorunlu değil). ADR-009 zarfı.

### 6. Reindex / rollout sırası (money-critical, sıra önemli)
1. Kodu **motor kapalıyken** deploy et → fallback zaten çalışır (kesinti yok).
2. Meili'yi ayağa kaldır → `search:sync-settings` → **`scout:import`** (tam reindex).
3. Doğrula → canlı ölç. Mevcut `SyncProductSearchIndex` listener güncel tutar.
4. `catalog:refresh-search-text` (Kademe 1) fallback haystack'ini yine tazeler.

## Testler
1. **Tipo:** `seurm`→serum, `uriaj`→uriage, `depidem`→depiderm **> 0** ve doğru ürün üstte.
2. **Relevans:** `uriage` → tam ad/çok satan/stokta olan **üstte**; alakasız altta.
3. **Eş anlamlı:** `güneş koruyucu` ≈ `spf`; config'ten geldiği doğrulanır.
4. **Şapka/Latin-1 regresyonu:** `gunes`=`güneş`, `avene`=`avène` (Kademe 1 kazanımları korunur).
5. **suggest:** ürün+marka+kategori döndürür, kapak uygulanır, boş `q` boş döner.
6. **Fallback:** motor down simülasyonunda `q` **Kademe 1 LIKE**'a düşer + warning loglar + health `down`.
7. **Sınır:** Meili index'inde **fiyat/stok yok** (CatalogBoundaryTest); GTIN/SKU **exact** (fuzzy değil).
8. **Pagination:** Meili sırası + Offer filtresi arasında sayfa 2+ doğru (küme + sıra tutarlı).
9. ≥2 satırlı fixture (strict-mode). `SCOUT_DRIVER` testte uygun sürücüye ayarlı.

## Non-goals / dikkat
- **Storefront autocomplete dropdown'u AYRI iş (frontend/desktop oturumu).** Bu emir
  `suggest` endpoint'ini üretir; UI'yı ben (storefront) yaparım.
- **Semantik/vektör arama** yok (ADR-090 ertelendi).
- **Kişiselleştirilmiş sıralama** yok (katalog geneli).
- Fold **silinmez** — fallback.
- Fiyat/stok Offer'da kalır; Catalog index'ine sızmaz.
