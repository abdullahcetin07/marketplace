---
name: raftabul-reel
description: >-
  Raftabul için markalı Instagram reel'i (dikey video) üretir — Remotion ile
  koddan render. "pazartesi reel'i yap", "reel hazırla", "şu ürünlerle bir reel
  çıkar" gibi isteklerde kullan. Metin/strateji için raftabul-social ile,
  açıklama diliyle raftabul-product-copy ile birlikte çalışır.
---

# Raftabul Reel (Remotion video stüdyosu)

Markalı dikey reel'leri **koddan** render eder — sahte önizleme değil, gerçek MP4.
Proje: **`marketing/reels/`** (repo kökünde). Bir reel = **veri** (`src/reels.ts`);
tasarım/animasyon şablonu (`src/Reel.tsx`) her reel'de aynı marka dilini uygular.

## Ne üretir

1080×1920, 30 fps, **sessiz** MP4. Yapı: **1 kanca + N adım + 1 CTA** sahnesi (her
sahne 3 sn). Üstte otomatik ilerleme çubuğu, altta marka barı, ürünler beyaz kartta
süzülür/pop yapar. Adım görselsiz olursa büyük numara rozetiyle çizilir.

## İş akışı

1. **Konu + sütun** seç (raftabul-social'ın 5 sütunu: vitrin/eğitim/kampanya/güven/
   sosyal kanıt). Kanca → adımlar → CTA metnini yaz.
2. **⚖️ Sağlık-beyanı taraması** (raftabul-product-copy): "tedavi/iyileştirir/geçirir"
   YOK; "nemlendirmeye/korumaya yardımcı, ferahlatır" VAR. Emin değilsen iddiayı düşür.
3. **Ürün görselleri — yalnız kendi kataloğumuzdan.** Canlı API'den bul + indir:
   ```bash
   curl -s "https://raftabul.com/api/v1/products?category=cilt-bakimi&per_page=12" -H "Accept: application/json"
   # ya da ?q=<arama> — her kartta .image alanı var
   curl -s -o marketing/reels/public/products/<ad>.webp "<image-url>"
   ```
   **Telifli stok/model/kadın fotoğrafı İNDİRİLMEZ** (telif). Lifestyle görseli
   gerekiyorsa kullanıcıdan kendi/lisanslı görselini iste, `public/`'e koy, referans ver.
4. **`src/reels.ts`'e entry ekle** (id + scenes). Görseller `products/<ad>.webp` olarak
   referanslanır (staticFile).
5. **Render + doğrula:**
   ```bash
   cd marketing/reels && npm run render -- <id> out/<id>.mp4
   # ilk kurulumda: npm install; esbuild postinstall gerekirse: node node_modules/esbuild/install.js
   ```
   Birkaç kareyi `npx remotion still src/index.ts <id> out/f.png --frame=N` ile görsel
   kontrol et (Türkçe karakter, görsel bindi mi, taşma var mı). Sonra MP4'ü kullanıcıya gönder.
6. **🎵 Müzik gömme** — sessiz gönder; "Instagram'da yüklerken uygulamanın müzik
   kütüphanesinden trend bir ses ekle" diye hatırlat (lisanslı, ücretsiz, erişimi artırır).
7. **Yayını kullanıcı yapar** — otomatik paylaşım yok.

## Marka

Turuncu `#fb5607`, warm ink neutrals (`#1C1917`/`#7A6E64`), warm off-white `#FAF7F2`,
Manrope. Token'lar `src/Reel.tsx` başında; değiştirmen gerekmez, sadece veri yaz.

## Sınırlar

- Fiyat/stok göstereceksen **canlı** oku (`/api/v1/offers/prices`), reel'e sabit yazma —
  eskir. (Şablona canlı fiyat rozeti eklemek ayrı iş.)
- Şablonu (`Reel.tsx`) her reel için değiştirme; yeni bir görsel dil gerekiyorsa ayrı
  bir composition/şablon ekle, mevcudu bozma.
- Video içine telifli müzik/stok video/stok foto koyma. Ürünler + koddan tasarım güvenli alan.
