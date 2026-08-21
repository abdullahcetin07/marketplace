---
name: raftabul-cro
description: Conversion-rate optimization for the Raftabul storefront (Next.js under storefront/) — audit and improve product, category, cart, and checkout pages for more add-to-carts and completed orders, measured against the GA4 ecommerce funnel already wired (view_item → add_to_cart → begin_checkout → purchase). Use when asked to improve conversion, reduce drop-off, review a page's UX for selling, or interpret funnel data. Adapts coreyhaines31/marketingskills `cro` to Raftabul.
---

# Raftabul CRO (Dönüşüm Optimizasyonu)

Amaç: daha çok **sepete ekleme** ve **tamamlanan sipariş**. Raftabul'un kendi
gerçeklerine göre çalış — genel CRO klişesi değil.

## Elimizdeki ölçüm (tahmin etme, veriye bak)

GA4 e-ticaret hunisi kurulu: **`view_item → add_to_cart → begin_checkout →
purchase`** (ADR-085, GTM `GTM-58RLQJ86`, GA4 `G-C70T228R99`). Bir değişiklik
önermeden önce **hunideki en büyük düşüşü** sor: nerede kopuyor? Öneriyi o adıma
odakla. "Şunu güzelleştirelim" değil, "şu adımdaki %X düşüşü şu yüzden".

## Raftabul'a özgü kaldıraçlar

- **Buy box** hesaplanır (ADR-045): en iyi teklif otomatik. Rekabet + "diğer
  satıcılar" görünürlüğü dönüşümü etkiler.
- **Tüm siparişlerde kargo bedava** — bunu üst barda/ürün/sepette görünür tut; en güçlü
  dürtülerden biri, gizlenmesin.
- **Puan (Aldıkça Kazan, ADR-081):** ürün/sepet/ödeme'de "bu üründen X puan kazan"
  motivasyon. `ProductCampaigns` zaten var — kullan.
- **Guest sepeti YOK (ADR-056):** anonim kullanıcı "Sepete ekle"de girişe düşer. Bu
  bir sürtünme; giriş/kayıt ekranını hızlı + "neden üye olmalıyım" (puan, takip) ile
  gerekçelendir. `next=` ile ürüne geri dönüşü koru.
- **Yorum & Soru-Cevap** (Reviews/Questions): sosyal kanıt. Ürün sayfasında görünür,
  boşsa "ilk yorumu sen yaz" — güven artırır.
- **Onaylı satıcı / orijinal ürün** rozeti: dermokozmetikte güven = dönüşüm.

## Mobil öncelik

Trafiğin çoğu mobil. Her öneriyi **375px**'te düşün: buy box + fiyat + "Sepete ekle"
katlama üstünde (above the fold) mi? Mobil "Hemen al" barı sabit mi? Dokunma
hedefleri ≥44px mi? (Bu depoda mobil düzenler `sm:` kırılımıyla ayrı — bkz. son
mobil carousel/bottom-sheet işleri.)

## Yöntem (audit iş akışı)

1. **Hedef sayfa + huni adımı** belirle (veri varsa GA4'ten, yoksa mantıkla).
2. Sayfayı gerçek gözle oku (gerekirse `storefront` dev server + mobil viewport ile).
3. **Sürtünme / güven / netlik / dürtü** dört başlıkta somut bulgular çıkar:
   - Sürtünme: fazla adım, belirsiz buton, zorunlu giriş, yavaş yük.
   - Güven: satıcı/iade/kargo/orijinallik/yorum sinyalleri.
   - Netlik: fiyat (KDV dahil), stok, kargo, teslim süresi görünür mü.
   - Dürtü: kargo bedava, puan, kıtlık (gerçekse), sosyal kanıt.
4. Bulguları **etki × efor** ile sırala. En yüksek etkiyi öne al.
5. Uygulanacaksa `storefront-engineer` kurallarıyla değiştir (marka token'ları, `tsc`,
   money = `formatMoney`, degrade-to-empty). Ölçülebilir olsun (hangi GA4 adımı).

## Kırmızı çizgiler

- **Karanlık kalıp yok** (sahte kıtlık/geri sayım, gizli ücret, onay-kutusu tuzağı).
  KVKK + marka güveni buna izin vermez.
- **Fiyatı asla parse etme/gösterme kuralını bozma** (money.ts) — CRO bahane değil.
- Sağlık ürününde abartılı/yasadışı fayda vaadi yok (bkz. [[raftabul-product-copy]]).
- Öneri = hipotez. Mümkünse A/B veya en azından GA4'te önce/sonra ile doğrula.
