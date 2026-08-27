# BUILD — Arama Türkçeleşsin: şapka-duyarsız + tipo toleranslı (Trendyol/HB gibi)

**Problem (canlı sitede ölçüldü):** kullanıcı ürünün **birebir adını** yazmadıkça
bulamıyor. En kritik: **Türkçe şapkasız yazınca 0 sonuç.** Çoğu insan "güneş" yerine
**"gunes"**, "şampuan" yerine "sampuan" yazar → hiçbir şey çıkmıyor.

**Kanıt (`/api/v1/products?q=`, `meta.total`):**

| Sorgu | Sonuç | Beklenen |
|---|---|---|
| `güneş` | 343 | — |
| **`gunes`** (şapkasız) | **0** ❌ | ~343 |
| `güneş kremi` | 145 | — |
| **`gunes kremi`** | **0** ❌ | ~145 |
| `uriage` | 101 | — |
| **`uriaj`** (Türkçe yazım) | **0** ❌ | ~101 (fuzzy) |
| **`urıage`** (noktasız ı) | **0** ❌ | ~101 |
| `serum` | 230 | — |
| **`seurm`** (tipo) | **0** ❌ | ~230 (fuzzy) |

## Kök neden — `app/Modules/Catalog/Infrastructure/Queries/CatalogBrowse.php::applyText()`

`q` araması **Scout değil**, bu metottaki DB sorgusu:
```
title_{locale} ILIKE '%term%'  OR  gtin = term  OR  sku = term
```
Üç kusur:
1. **Diakritik katlama YOK.** Metodun docblock'u (satır ~361-374) *"ILIKE Türkçeyi
   doğru katlar"* diyor — **yanlış.** ILIKE yalnız **harf büyüklüğünü** katlar
   (`istanbul`↔`İSTANBUL`), **şapka/diakritiği değil** (`gunes`↮`güneş`). Bu docblock
   düzeltilmeli.
2. **Tek `%term%` substring.** Çok kelimeli sorgu, başlıkta **bitişik** geçmek zorunda;
   token-AND yok. Marka/kategori/açıklama alanları hiç aranmıyor (marka çoğu başlıkta
   geçtiği için `uriage` tesadüfen çalışıyor).
3. **Tipo/fuzzy yok.**

## Fix — İKİ KADEME

### 🔴 Kademe 1 — HOTFIX: şapka-duyarsız arama (küçük, en yüksek etki, altyapı yok)

Amaç: `gunes`↔`güneş`, `urıage`↔`uriage`, tüm diakritik + harf büyüklüğü **eşitlensin.**
Yöntem: **hem sütunu hem sorguyu aynı "fold" biçimine indir**, öyle eşleştir.

**Türkçe fold eşlemesi (iki yönlü — çıktı ASCII):**
`ç→c  ğ→g  ı→i  İ→i  ö→o  ş→s  ü→u` (+ `lower`). "güneş" ve "gunes" ikisi de `gunes` olur.

- **PHP tarafı (needle):**
  ```php
  $fold = static fn (string $s): string => mb_strtolower(
      strtr($s, ['ç'=>'c','Ç'=>'c','ğ'=>'g','Ğ'=>'g','ı'=>'i','İ'=>'i',
                 'ö'=>'o','Ö'=>'o','ş'=>'s','Ş'=>'s','ü'=>'u','Ü'=>'u']), 'UTF-8');
  $needle = '%'.$fold($term).'%';
  ```
- **pgsql tarafı (sütun):** `translate(lower(title_tr), 'çğıöşü', 'cgiosu') LIKE ?`
  - `lower()` unicode-aware; kalan Türkçe harfleri `translate` ASCII'ye indirir. `İ`/`I`
    için `lower` sonrası çıkanı da eşle (test et: `urıage`→`uriage`, `İSTANBUL`→`istanbul`).
- **sqlite (test) tarafı:** `translate` yok; ya bir SQL fonksiyonu register et, ya da
  fold'u **kalıcı bir sütuna** yazıp (`title_tr_folded`) iki sürücüde de o sütunda ara.
  > **Not:** Mevcut kod tam da bu yüzden sürücüye göre dallanıyor. En temizi: **fold'lanmış
  > bir arama sütunu** (`search_text`) — başlık(lar) + **marka adı** + (istenirse kategori)
  > birleşik, fold'lu — indexleme sırasında doldur, aramayı orada yap. Sürücü farkı biter.

**Bu kademede ayrıca:**
- **Marka adını** aramaya kat (fold'lu `search_text`'e brand.name ekle).
- **Token-AND:** sorguyu boşluktan böl, her token için ayrı `LIKE` (hepsi geçmeli) →
  "leke serum" bitişik olmasa da bulur. GTIN/SKU **tam eşleşme** korunur (barkod fuzzy olmaz).

### 🟠 Kademe 2 — GERÇEK motor: tipo + önek + eş anlamlı + relevans (AYRI, ADR gerekir)

Kademe 1 şapkayı çözer ama **tipo** (`seurm`,`uriaj`) ve **relevans sıralaması** için gerçek
arama motoru gerekir. Platformda **Scout zaten kablolu** (`Product` `Searchable`,
`toSearchableArray()`, `SyncProductSearchIndex`) — `q` browse'unu Scout'a taşımak doğal.
- **Öneri:** Scout + **Meilisearch** veya **Typesense** — tipo toleransı, önek (yazarken
  öneri), eş anlamlı, diakritik-fold, relevans **hazır** gelir (Trendyol/HB hissi).
- pg_trgm alternatifi (GIN trigram + `similarity()`) altyapısız ama zayıf; motor tercih edilir.
- Bu bir **altyapı + mimari** kararı → **ADR** yaz (ADR-003 önceliği; `docs/` güncelle).
  Sprint/emir dokümanı ezmez (ADR-018): çelişki varsa dur, ADR çıkar.

## Testler (aynen bu sorgular geçmeli)
1. `gunes` **> 0** ve sonucu `güneş` ile **aynı** (fold doğrulaması). Aynısı `sampuan/şampuan`, `krem`, `nemlendirici`.
2. `urıage` (noktasız ı) → `uriage` ile aynı sonuç.
3. `GÜNEŞ`/`güneş`/`gunes`/`Gunes` → **hepsi eşit** sonuç.
4. `leke serum` (bitişik değil) → token-AND ile > 0.
5. Marka-yalnız sorgu (başlıkta geçmese bile) markadan bulur.
6. GTIN/SKU **tam** eşleşme fuzzy'ye kaçmadı (regression).
7. (Kademe 2) `seurm`→serum, `uriaj`→uriage tipo toleransı; relevans: tam ad üstte.
8. ≥2 satırlı fixture (strict-mode lazy-load).

## Non-goals / dikkat
- **Storefront'a dokunma** — `q`'yu zaten geçiyor; düzeltme backend'de görünür olur.
- `CatalogBoundaryTest`: aramaya fiyat/stok **sızdırma** (Offer/Inventory değil, Catalog).
- Reindex: Kademe 2 motor gelirse **reindex + queue worker** şart (yoksa arama boş).
- Docblock'taki yanlış "ILIKE Türkçeyi katlar" iddiasını düzelt.
