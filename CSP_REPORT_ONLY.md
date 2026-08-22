# CSP — Report-Only taslağı (raftabul.com)

**Amaç:** Content-Security-Policy'yi önce **Report-Only** modda yayınlamak → hiçbir şeyi
engellemeden ihlalleri toplamak → birkaç gün gözlemleyip rafine etmek → sonra enforce'a
(`Content-Security-Policy`) geçmek. **Ödeme akışını (PayTR) riske atmadan.**

Kapsanan kaynaklar: GTM + GA4 + Google Ads dönüşüm/remarketing, **PayTR iframe**
(`www.paytr.com`), Google Fonts, Next.js (inline hydration → `'unsafe-inline'`/`'unsafe-eval'`
şimdilik açık; ileride nonce ile sıkılaştırılır), same-origin API/görseller.

---

## Header

**Ad:** `Content-Security-Policy-Report-Only`
**Değer (tek satır — aşağıdaki `;`-ayrık direktiflerin tamamı tek satırda):**

```
default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://www.googletagmanager.com https://www.google-analytics.com https://ssl.google-analytics.com https://www.googleadservices.com https://www.google.com https://googleads.g.doubleclick.net https://pagead2.googlesyndication.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: blob: https://*.google-analytics.com https://*.analytics.google.com https://www.googletagmanager.com https://www.google.com https://www.google.com.tr https://googleads.g.doubleclick.net https://www.googleadservices.com https://pagead2.googlesyndication.com; font-src 'self' data: https://fonts.gstatic.com; connect-src 'self' https://www.googletagmanager.com https://*.google-analytics.com https://*.analytics.google.com https://www.google.com https://googleads.g.doubleclick.net https://www.googleadservices.com https://pagead2.googlesyndication.com; frame-src 'self' https://www.paytr.com https://*.paytr.com https://www.googletagmanager.com https://td.doubleclick.net https://www.google.com https://bid.g.doubleclick.net; frame-ancestors 'self'; base-uri 'self'; form-action 'self' https://www.paytr.com https://*.paytr.com; object-src 'none'; worker-src 'self' blob:
```

### Direktif gerekçeleri
- `script-src` — GTM/GA/Ads host'ları + Next.js inline hydration ve GTM için
  `'unsafe-inline' 'unsafe-eval'` (Report-Only aşamasında bilinçli; enforce öncesi nonce'a
  geçilebilir).
- `style-src 'unsafe-inline'` — storefront çok sayıda inline `style=""` kullanıyor + Google Fonts stylesheet.
- `img-src` — same-origin ürün görselleri (`/storage/...`), `data:`/`blob:`, GA/GTM/Ads pikselleri.
- `font-src 'self' data:` — Manrope self-hosted (next/font); `fonts.gstatic.com` yedek.
- `connect-src` — API/sanctum same-origin + GA4/GTM/Ads beacon'ları.
- `frame-src` — **PayTR ödeme iframe'i** (kritik) + GTM noscript + Ads dönüşüm iframe'leri.
- `frame-ancestors 'self'` — X-Frame-Options SAMEORIGIN ile uyumlu (bizi kimse frame'leyemez).
- `form-action 'self' + paytr` — form gönderimleri same-origin; PayTR yedek.
- `object-src 'none'`, `base-uri 'self'`, `worker-src 'self' blob:` — sağlamlaştırma.

---

## Nasıl yayınlanır (öneri: Cloudflare — rebuild gerektirmez, kolay iterasyon)

**Rules → Overview → mevcut "Security headers" (Response Header) kuralını düzenle →
"Set new header":**
- Header name: `Content-Security-Policy-Report-Only`
- Value: yukarıdaki tek-satır değer
- **Set static** → Deploy.

> Alternatif: storefront `next.config.js` `headers()` — ama bu rebuild ister ve iterasyonu
> yavaşlatır. İterasyon bitip enforce'a geçince oraya taşınabilir.

---

## Test (deploy sonrası — HİÇBİR ŞEY engellenmez, sadece raporlanır)

Tarayıcı **DevTools → Console**'da `[Report Only]` CSP ihlallerini izleyerek şu akışları gez:
1. Ana sayfa (GTM/GA yüklenir, JSON-LD)
2. Kategori sayfası (FAQ + ItemList)
3. Ürün sayfası (galeri, JSON-LD, GTM event'leri)
4. Sepete ekle → giriş → sepet
5. **`/odeme` — PayTR iframe'i yükleniyor mu** (en kritik test)
6. Ödeme sonucu sayfası
7. Consent banner "Kabul Et" → GA/Ads consent update

Konsolda `Content-Security-Policy-Report-Only` ile başlayan **"would have blocked"** satırlarını
not al. Beklenen: 0'a yakın; çıkanları ilgili direktife host olarak ekle.

### (Opsiyonel) Rapor toplayıcı
Manuel test yeterli değilse `report-to`/`report-uri` ile bir toplayıcıya (kendi endpoint,
report-uri.com, veya bir Cloudflare Worker) ihlalleri akıtabiliriz. v1 için DevTools yeterli.

---

## Enforce'a geçiş (birkaç gün temizlik sonrası)
İhlaller sıfırlandığında header adını **`Content-Security-Policy`** yap (aynı değer). 
İleride sıkılaştırma: `'unsafe-inline'`/`'unsafe-eval'` yerine Next.js **nonce** (middleware)
+ GTM'i nonce ile yükleme — ayrı bir iş.
