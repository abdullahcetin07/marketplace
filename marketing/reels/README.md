# Raftabul Reels — kod'dan Instagram reel stüdyosu

Markalı dikey reel'leri **Remotion** ile koddan render eder. Bir reel = **veri**
(`src/reels.ts`); yeni reel eklemek = yeni bir entry. Tasarım/animasyon şablonu
(`src/Reel.tsx`) her reel'de aynı marka dilini uygular.

## Kullanım

```bash
npm install                 # ilk sefer (esbuild postinstall gerekirse: node node_modules/esbuild/install.js)
npm run studio              # tarayıcıda canlı önizleme (tüm reel'ler)
npm run render -- monday out/monday.mp4   # tek reel render
npm run monday              # kısayol
npm run friday
```

Çıktı: `out/<id>.mp4` — **1080×1920, 30 fps, sessiz.**

## Yeni reel eklemek

`src/reels.ts` içindeki `REELS` dizisine bir entry ekle:

```ts
{
  id: 'sali',                       // render hedefi: npm run render -- sali out/sali.mp4
  scenes: [
    { kind: 'hook', eyebrow: '...', line1: '...', line2: '...', sub: '... ↓', image: 'products/x.webp' },
    { kind: 'step', n: '1', title: '...', desc: '...', image: 'products/y.webp' }, // image opsiyonel
    { kind: 'cta',  eyebrow: 'RAFTABUL', line1: '...', line2: '...', sub: '...', images: ['products/x.webp'] },
  ],
}
```

Sahne sayısı videonun süresini ve üstteki ilerleme çubuğunu otomatik belirler
(her sahne 3 sn). `step` görselsiz olursa büyük numara rozetiyle çizilir.

## Kurallar (bunlara uy)

- **🎵 Müzik gömme.** Reel sessiz render edilir; müzik Instagram'da yüklerken
  uygulamanın kütüphanesinden eklenir (lisanslı, ücretsiz, erişimi artırır).
- **🖼️ Görseller yalnız kendi kataloğumuzdan.** `public/products/` içindekiler
  canlı API'den indirilen gerçek ürünler. Telifli stok/model fotoğrafı **indirilmez**;
  lifestyle görseli gerekiyorsa marka kendi/lisanslı görselini verir.
- **⚖️ Sağlık beyanı yok.** "nemlendirmeye/korumaya yardımcı" evet; "tedavi/iyileştirir"
  hayır (bkz. `raftabul-social` + `raftabul-product-copy` skilleri).
- Marka: turuncu `#fb5607`, warm ink neutrals, Manrope. Token'lar `src/Reel.tsx` başında.

## Ürün görseli eklemek

```bash
curl -s -o public/products/<ad>.webp "https://raftabul.com/storage/.../...-preview.webp"
```
`GET https://raftabul.com/api/v1/products?category=<slug>` ya da `?q=<arama>` ile
ürünleri + `image` alanını bul.
