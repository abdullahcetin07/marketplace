# BUILD — Şablon-temelli ürün açıklaması üretici (backend)

**Amaç:** Satılabilir ~6.992 ürünün **boş** açıklamasını, ürünün **gerçek alanlarından**
türetilen **kısa + doğru + yasal** Türkçe açıklamayla doldurmak → `product.description`
dolunca **GMC feed** (ADR-086) açılır, Google Shopping devreye girer.

**LLM DEĞİL — deterministik kategori şablonu.** Uydurma içerik yok; yalnız üründe zaten
var olan bilgiden cümle kurar. Bu, **feed uygunluğu + doğru bir zemin**; zengin/organik
içerik değil (o ayrı bir veri kaynağı ister — bkz. §Sonra).

Bu iş **backend**. Storefront'a dokunma (description zaten render ediliyor).

---

## Mimari ve sınırlar

- **Authoring/update yolundan yaz — raw model write DEĞİL** (ADR-074/076 dersi). Description
  güncellemesi search index'i ve ilgili event'leri tetiklemeli; doğrudan `->update()` bunları
  atlar → ürün tabloda doğru, aramada eski kalır. Mevcut ürün güncelleme action'ını kullan.
- **Zaten published ürünlerde yalnız `description` alanı güncellenir** — ürünü yeniden
  moderasyona sokMA (moderasyon lifecycle'ı yeni ürün/variant içindir). Belirsizse: en az
  **search reindex** garanti et.
- **Hiçbir modül import edilmez** (`LayeringTest`). Bu tamamen Catalog içi bir bakım işi.
- **Chunk + queue** (6.992; feed/import gibi). Idempotent, tekrar çalıştırılabilir.

## Komut — `catalog:fill-descriptions`

- **Yalnız BOŞ açıklamalı** (trim sonrası boş) **published + sellable** ürünleri doldurur.
  **İnsan/önceden yazılmış açıklamayı ASLA ezme.** (`--only-empty` varsayılan; `--force` yok
  veya çok bilinçli.)
- Her ürün için kategorisine uygun şablonu seçer, değişken alanları doldurur, sağlık-beyanı
  taramasından geçirir, yazar.
- **Rapor (stdout + log):** doldurulan, atlanan (zaten dolu), atlanan (kritik alan eksik —
  ör. marka+kategori yoksa). Kritik alan eksikse **uydurma, atla ve say.**

## Şablon tasarımı (config: `config/product_descriptions.php`)

- **Kök kategoriye göre şablon haritası** + bir **varsayılan** şablon. Metin, ürünün
  değişken alanlarıyla kişiselleşir (aynı boş cümle değil):
  - Değişkenler: `{marka} {baslik} {hacim} {form} {kategori}`.
  - **Başlıktan regex ile bilinen jetonları çıkar, yalnız VARSA kullan:**
    `SPF\d+`, `\d+\s?(ml|mL|gr|g|kg|l)`, `\d+'?\s?l[iuü]` (adet), form sözcükleri
    (stick, krem, jel, losyon, serum, sabun, şampuan, maske, damla, tablet, kapsül, sprey).
    Yoksa cümle o jeton olmadan düzgün kurulmalı.
- **Sağlık beyanı YASAK** (bkz. `.claude/skills/raftabul-product-copy`): hiçbir şablonda
  "tedavi eder / iyileştirir / geçirir / önler / hastalığa iyi gelir" GEÇMEZ. Bir test,
  üretilen tüm çıktıyı bu kalıplara karşı tarar.
- **Kategoriye göre yasal ek:**
  - Kozmetik/kişisel bakım dalları → "Haricen kullanım içindir."
  - Takviye dalları (besin takviyeleri, vitaminler, mineraller, omega vb.) →
    "Takviye edici gıdadır, ilaç değildir; hastalıkların tedavisinde kullanılmaz."

**Örnek (kozmetik):**
> "{Marka} {Başlık}{, hacim}, {kategori} kategorisinde yer alan bir üründür{, SPF/form
> detayı}. Orijinal ürün, onaylı satıcılardan. Haricen kullanım içindir."

**Örnek (takviye):**
> "{Marka} {Başlık}{, hacim}, günlük {kategori} alımına katkı amacıyla sunulan takviye
> edici gıdadır. Kullanımdan önce etiketi okuyun. Takviye edici gıdadır, ilaç değildir;
> hastalıkların tedavisinde kullanılmaz."

(Şablonlar Türkçe ek uyumuna dikkat etmeli; hacim/SPF/form yoksa cümle bozulmamalı.)

## Config
- `product_descriptions.templates` = kök-kategori-slug → şablon
- `product_descriptions.default` = varsayılan şablon
- `product_descriptions.only_empty` = true

## Testler
1. Boş açıklamalı published+sellable ürün → doğru şablonla dolar; **ikinci çalıştırma
   EZMEZ** (idempotent).
2. İnsan-yazılı açıklama → **dokunulmaz**.
3. Takviye kategorisindeki ürün → "ilaç değildir" notu var; **hiçbir** üretilmiş metinde
   yasaklı sağlık kalıbı yok (string taraması).
4. Başlıktan SPF/hacim/form çıkarımı doğru; jeton yoksa cümle düzgün.
5. Doldurulan ürün **search index'te güncellenir** (reindex/event tetiklenir) — ≥2 satırlı
   fixture ile strict-mode lazy-load tuzağı doğrulanır.
6. Kritik alan (marka+kategori) eksik ürün → **atlanır ve raporlanır**, uydurma yok.
7. Modül import yok (LayeringTest); Catalog boundary korunur.

## Non-goals
- Zengin/organik-sıralama içeriği (veri kaynağı ister). Bu kısa/şablon metin **feed
  uygunluğu + doğru zemin**dir.
- LLM üretimi (bu iş deterministik).
- Storefront değişikliği.

## Sonra / bağımlılık
- Çalışınca feed'in "boş açıklama nedeniyle ayıklanan" sayısı düşer → `/feed/google-merchant.xml`
  dolar → GMC scheduled fetch anlamlı olur → Shopping.
- **Not (bilinçli takas):** açıklamalar hem feed'de hem **ürün sayfasında** görünür (tek
  kaynak `product.description`). Şablon oldukları için organik-ranking değeri sınırlıdır;
  organik/GEO tarafını **kategori FAQ (yapıldı) + yorumlar** taşır. Ürün başına zengin
  içerik, bir GTIN→içerik veri kaynağı geldiğinde bu alanların üzerine yazılabilir (o zaman
  `--force` ile yalnız şablon-üretimi olanları hedefleyen bir işaret düşünülebilir).
