---
name: raftabul-product-copy
description: Write ORIGINAL Turkish product descriptions for the Raftabul dermocosmetics/health marketplace — SEO-aware, brand-voiced, and strictly within Turkish health-claim law (Sağlık Beyanı / Kozmetik Yönetmeliği). Use when generating or rewriting product descriptions, titles, or bullet features for catalogue items (especially the empty-description backfill), one GTIN at a time or in batches. Adapts coreyhaines31/marketingskills `copywriting` to Raftabul's context.
---

# Raftabul Ürün Açıklaması (Copywriting)

Raftabul (raftabul.com, işleten: AMIAY) bir **dermokozmetik / sağlık / kişisel
bakım pazaryeri**. Katalog paylaşımlı (bir ürün, çok satıcı), açıklama **ürüne**
aittir — satıcıya değil. Bu skill boş/zayıf açıklamaları **özgün**, satışa dönük ve
**yasal** Türkçe metne çevirir.

## ⚖️ MUTLAK KURAL — Sağlık beyanı yasağı (önce bunu oku)

Türkiye'de kozmetik ve takviye edici gıdalar **hastalık tedavi/önleme iddiası
yapamaz** (Kozmetik Yönetmeliği, Sağlık Beyanı Yönetmeliği, TİTCK). Bir açıklama
şu kalıpları **ASLA** kullanmaz:

- ❌ "tedavi eder", "iyileştirir", "geçirir", "önler", "hastalığa iyi gelir"
- ❌ "egzama/sedef/akne/mantar **tedavisi**", "saç dökülmesini **durdurur**"
- ❌ "bağışıklığı güçlendirir", "hastalıklardan korur" (takviyede hastalık iddiası)
- ❌ doktor/ilaç yerine geçme iması, "%100 etkili", "kesin sonuç"

Bunun yerine **izin verilen kozmetik/bakım dili**:
- ✅ "nemlendirmeye yardımcı", "cildin görünümünü destekler", "kuruluk hissini azaltır"
- ✅ "temizler", "besler", "ferahlatır", "pürüzsüz görünüm", "bakımına katkı sağlar"
- ✅ takviye: "günlük [vitamin] alımına katkıda bulunur" (yalnızca izinli beyanlar)

Emin değilsen **iddiayı düşür**, kozmetik faydaya çevir. Şüpheli bir cümleyi
metinden çıkar ve notunda belirt. Bu kural SEO'dan da satıştan da önce gelir.

## İkinci kural — kopya YASAK

Trendyol, Hepsiburada, üretici sitesi veya başka bir kaynaktan metin **birebir
alınmaz** (telif + Google ceza). Ürün özelliklerinden (marka, içerik, hacim,
kullanım) yola çıkıp **sıfırdan özgün** yaz. Kaynak metin varsa yalnızca gerçekleri
(içerik listesi, hacim) referans al, cümleleri değil.

## Marka sesi

Güvenilir, sıcak, uzman ama sade. "Onaylı satıcılardan orijinal ürün." Abartısız,
net, Türkçe karakterler doğru (ı/İ/ş/ç/ğ/ü/ö). Emoji yok. Ünlem cimri.

## Çıktı yapısı (her ürün için)

1. **Kısa giriş (1–2 cümle):** ürün nedir, kime/ne için. Marka + tip + hacim geçsin.
2. **Öne çıkanlar (3–5 madde):** içerik/fayda, izinli dille. Somut (hacim, cilt tipi,
   kullanım sıklığı).
3. **Kullanım (1–2 cümle):** nasıl/ne zaman uygulanır (biliniyorsa).
4. **Kime uygun / içerik notu:** cilt tipi, "haricen kullanım", varsa uyarı.
5. Takviyede: **"Takviye edici gıdadır, ilaç değildir. Hastalıkların tedavisinde
   kullanılmaz."** benzeri yasal not; hamile/çocuk/hekim uyarısı uygunsa.

## SEO

- Ürün adını + ana kategoriyi ilk cümlede doğal geçir (başlık zorlaması yok).
- Anahtar kelime doldurma (keyword stuffing) yok — akıcı Türkçe kazanır.
- Uzunluk: 60–140 kelime tipik; ince ürün için kısa da olur. Boş doldurma yok.
- Başlık/`title` verilecekse: `{Marka} {Ürün} {Ayırt edici özellik/hacim}` — 60 karakter civarı.

## İş akışı

1. Ürünün verisini oku (marka, kategori, içerik, hacim, varsa GTIN). Uydurma **yok**:
   bilmediğin içeriği/oranı yazma.
2. Kategoriye göre izinli fayda dilini seç (yukarıdaki liste).
3. 5 bölümü yaz, sağlık-beyanı taraması yap (yasaklı kalıp var mı?).
4. Kopya taraması: cümleler özgün mü?
5. Toplu işte: her satır için `{gtin/uuid, baslik?, aciklama, not?}` döndür; şüpheli/eksik
   veriyi `not`'ta işaretle, atlanacaksa sebebini yaz — sessizce uydurma.

## Sınırlar

Fiyat/stok yazma (bunlar Offer, açıklama değil). Satıcı adı geçirme (paylaşımlı katalog).
Bir üründe emin olamadığın tıbbi/içerik iddiasını **eksik bırak**, işaretle — yanlış
beyan, boş açıklamadan pahalıdır.
