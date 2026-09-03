# İş Emri — Ürün Açıklaması Zenginleştirme (backend)

**Amaç:** İnce/şablon ürün açıklamalarını (denetim: ~50-60 kelime, "…kategorisinde yer
alan… formundadır", tüm katalogda near-duplicate) **özgün, doğru, yasal, SEO-Türkçe**
metinlerle değiştirmek. Bu, SEO denetiminin en büyük içerik kaldıracı — paylaşımlı katalog
sayesinde **tek sayfa/tek nokta** düzeltmesi (satıcı-duplicate yok).

**Bu bir Catalog modülü işi.** Açıklama `Product.description`'a yazılır ve **moderasyon
lifecycle'ından** geçer (ADR-038/039) — ham model çıktısı doğrudan publish EDİLMEZ.

---

## 0. Load-bearing kurallar (ihlali işi geçersiz kılar)

1. **Cümle KOPYALAMA yok.** Hiçbir kaynağın cümlesi alınmaz/spin'lenmez (telif + Google
   "scraped/spun content" spam cezası). Kaynaklardan **yalnız GERÇEKLER** çıkarılır
   (marka, seri, hacim, form, cilt tipi, INCI/içerik, kullanım, SPF, uyarı); metin
   **sıfırdan** yazılır. `humanizer`/spinner **kullanılmaz** — [[raftabul-product-copy]]
   skill'i zaten özgün üretir.
2. **Sağlık beyanı YASAK** (Kozmetik Yönetmeliği). "tedavi eder/iyileştirir/geçirir/durdurur"
   asla; "nemlendirmeye yardımcı/bakımına katkı/görünümünü destekler" serbest. Üretici her
   metni tarar; şüpheli cümleyi düşürür.
3. **Uydurma GERÇEK yok.** Bilinmeyen INCI/oran/kullanım **yazılmaz** — boş bırakılır.
   Yanlış beyan, boş açıklamadan pahalıdır. Kaynaklarda çelişki varsa **üretici** kazanır.
4. **Moderasyondan geçer** — authoring/moderation yolunu kullan, `Product.description`'a
   doğrudan yazma.

---

## 1. 🔑 Bilgiyi HANGİ kaynaklardan alsın (öncelik sırası)

Barkod (GTIN) ile aynı ürünü kaynaklarda bul, **yalnız olguları** çıkar. Öncelik:

| # | Kaynak | Neden | Not |
|---|---|---|---|
| **1** | **Üretici/marka RESMİ TR sitesi** — avene.com.tr, bioderma.com.tr, laroche-posay.com.tr, uriage.com.tr, vichy.com.tr, cerave.com.tr, isis-pharma… | **En güvenilir**: doğru INCI/içerik, kullanım, hacim. Source of truth. | Önce burayı dene; INCI ve kullanım buradan. |
| **2** | **Kendi katalog verimiz** | Marka, kategori, hacim, GTIN zaten var. | Ücretsiz, kesin. |
| **3** | **GS1 / barkod veritabanı** (GS1 Türkiye, barkod arama) | GTIN doğrulama + temel ürün kimliği. | Fact-level teyit. |
| **4** | **Büyük pazaryerleri — boşluk doldurma** (Trendyol, Hepsiburada) | Geniş spec taşır; üretici eksikse gerçekleri tamamlar. | **Yalnız gerçek**, cümle değil; ToS'a saygı, rate-limit. |
| **5** | Rakipler (dermoeczanem, narecza, sachane…) | Son çare | Üretici kadar otoriter değil + scraping/ToS hassas → **öncelik verme.** |

**Önerim:** #1 + #2 + #3 çoğu ürün için yeterli ve en temiz (üretici + kendi verimiz + GS1).
#4'e yalnız **eksik kalınca** in; #5'ten mümkünse hiç alma. Kaynak ne olursa olsun **fact-only
extraction** — gerçeğin telifi yoktur, cümlenin vardır.

**Scraping disiplini:** `robots.txt`/ToS'a uy, makul rate-limit, kaynak başına kimlik/UA
dürüst. Üretici siteleri en güvenli; pazaryeri/rakip scraping'i minimumda tut.

---

## 2. Akış (ürün başına)

1. **Gerçekleri topla** → yapılandırılmış fact-sheet: `{marka, seri, hacim, form, cilt/saç
   tipi, INCI/öne çıkan içerikler, kullanım, SPF, uyarılar}` (yukarıdaki öncelikle).
2. **Metni yaz** → [[raftabul-product-copy]] skill kurallarıyla: giriş (marka+tip+hacim) +
   öne çıkanlar (3-5, izinli dil) + kullanım + kime uygun/uyarı + (takviyeyse) yasal not.
   60-140 kelime, anahtar kelime ilk cümlede doğal, keyword-stuffing yok.
3. **Sağlık-beyanı + kopya taraması** → yasaklı kalıp var mı; cümle özgün mü.
4. **Moderasyona gönder** → onaylanınca publish; olaylar (search index vb.) tetiklensin.

---

## 3. Kapsam & mimari

- **Kapsam:** açıklaması ince/boş olan ürünler. **Pareto:** en çok trafik/dönüşüm alan
  SKU'lardan başla; uzun kuyruğu zaman içinde.
- **Idempotent + batch + kuyruk** (katalog import ADR-074 deseni): tekrar çalıştırılabilir,
  chunk'lı, per-row başarı/başarısızlık raporu. Zaten yazılmış/elle düzenlenmiş açıklamayı
  **ezme** (yalnız ince/boş olanları hedefle, ya da bir `enriched_at` işareti).
- **Authoring action'ları SÜR, model'e ham yazma** — moderasyon, slug/GTIN, event'ler es
  geçilmesin (ADR-074'ün load-bearing kuralıyla aynı).
- **⚠️ Kuyruk worker'ı olmadan inert** — `queue:work`/scheduler çalışmalı.

---

## 4. Pilot ÖNCE

Ölçeğe geçmeden **10-20 ürünlük pilot** (farklı markalardan): gerçek toplama + skill yazımı +
sağlık taraması + moderasyon akışını uçtan uca doğrula. Çıktı kalitesini (doğruluk, özgünlük,
yasallık, SEO) gör, sonra ölçekle. Pilotu istersen ben (desktop) elle yürütüp örnek 10 metin
çıkarabilirim — sen görüp onayla, sonra server toplu işi kurar.

## 5. Kim ne yapar
- **Server (backend/Catalog):** toplu iş — fact-gather adaptörü (kaynak öncelikli), skill-copy
  çağrısı, moderasyona kuyruklama, idempotency, rapor. Worker/scheduler.
- **Ben (desktop):** pilot 10 metin (GTIN→gerçek→skill→özgün), kalite şablonu; storefront
  gösterim zaten hazır ("Ürün Açıklaması" başlığı + JSON-LD).
- **İçerik-ops:** pilot onayı, sağlık-beyanı gözden geçirme, marka-dili kalibrasyonu.
- **Kısıt:** telif/ToS + sağlık-beyanı + uydurma-yok kuralları her adımda.
