# BUILD — Feed: "Sağlık ve Medikal" kökünü hariç tut (GMC sağlık politikası reddi)

**Sebep:** GMC ürünleri onaylamadı (muhtemel: sağlık/tıbbi politika). "Sağlık Ve Medikal"
kökü, Google'ın **yasakladığı** içerikle dolu: cinsel sağlık (prezervatif, performans/
geciktirici, afrodizyak), **tıbbi cihazlar** (nebulizatör, tansiyon aleti, termometre,
ortopedik), zayıflama-diyet, yara-yanık/tedavi ürünleri. Bunlar Shopping'de zaten olamaz
ve **hesap-seviyesi askıyı** tetikler — takviyelerle aynı hikâye.

## Güvenlik kontrolü (yapıldı, canlı ağaçtan)
Kozmetik kategoriler **ayrı kökler** — Sağlık'ın **altında değil**:
`cilt-bakimi`, `gunes-kremleri`, `kisisel-bakim`, `makyaj`, `sac-bakimi`, `anne-ve-bebek`.
Yani `saglik-ve-medikal`'i çıkarmak bu Shopping kategorilerine **dokunmaz** (Sağlık altındaki
tek kozmetik-benzeri "Afrodizyak Parfüm" = 0 ürün).

## Değişiklik (küçük, mevcut mekanizma)
- Feed exclude listesine (`excluded_category_slugs` — takviyelerin çıkarıldığı yer) **ekle:**
  ```
  saglik-ve-medikal
  ```
- Alt-ağaç dahil hariç tutulmalı (subtree). ~**1450 ürün** feed'den düşer, ~3900 kozmetik kalır.
- **Yalnız FEED** — storefront/katalog değişmez; ürünler sitede satılmaya devam eder.

## Test
1. Feed'de (`/feed/google-merchant.xml`) `saglik-ve-medikal` **ve alt kategorilerinden** hiç
   item yok (prezervatif, nebulizatör, tansiyon aleti, zayıflama vb. çıkmıyor).
2. Kozmetik kökleri (`cilt-bakimi`, `gunes-kremleri`, `makyaj`, `sac-bakimi`, `kisisel-bakim`,
   `anne-ve-bebek`) feed'de **var** (regresyon yok).
3. Mevcut `besin-takviyeleri` hariç tutması **korunuyor** (bozulmadı).
4. Item sayısı önce/sonra loglanır (~5363 → ~3900 beklenir).

## Non-goals / not
- Storefront değişmez.
- **⚠️ Bu gerekli ama yeterli olmayabilir.** GMC'nin **tam ret gerekçesini** (Ürünler →
  Teşhis, veya e-posta) görmeden kesin diyemeyiz. İki olası ek sorun:
  - **Marka eksikliği** — feed'in ~%48'inde `g:brand` yok; Google çoğu kategoride ister
    (ayrı katalog-veri işi, BUILD_FEED_IDENTIFIER_FIX notu).
  - **Hesap-seviyesi** (misrepresentation / yeni-site güven) ise kategori çıkarmak çözmez.
- İleride Sağlık altındaki **güvenli** alt kategoriler (ör. kulak tıkacı) ayrı bir incelemeyle
  geri eklenebilir; şimdilik tüm kök çıkar → onay al.
