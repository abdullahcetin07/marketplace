# Cloudflare CSP — Google Customer Reviews izinleri (rev5)

**Sorun:** Storefront'a Google Customer Reviews eklendi — sipariş-sonrası opt-in anketi
(`/odeme/sonuc`) ve site geneli satıcı-puanı **rozeti (badge)**. İkisi de
`https://apis.google.com/js/platform.js`'i yükleyip Google domainlerinden iframe/görsel
çekiyor. **Cloudflare'deki Content-Security-Policy** (rev4) bu alan adlarına izin vermediği
için tarayıcı `platform.js`'i ve rozet iframe'ini bloke eder → rozet hiç görünmez, opt-in
kartı açılmaz. CSP **nginx/Next'te değil, Cloudflare katmanında** set ediliyor — bu yüzden
**repo'dan düzeltilemez, Cloudflare panelinden** düzeltilir.

## Owner adımları (Cloudflare paneli)

1. Cloudflare → **raftabul.com** → **Rules → Transform Rules → Modify Response Header**.
2. `Content-Security-Policy` başlığını **Set** eden kuralı aç.
3. Değeri, aşağıdaki **yeni tam string** (rev5) ile değiştir (tek satır, paste-ready).
4. **Deploy** → 1–2 dk sonra doğrula:
   `curl -sSI https://raftabul.com/ | grep -i content-security` → `apis.google.com` ve
   `gstatic.com` geçmeli.

## Değişen dört direktif (rev4 → rev5)

| Direktif | Eklenen | Neden |
|---|---|---|
| `script-src`  | `https://apis.google.com` | GCR yükleyici `platform.js` |
| `frame-src`   | `https://apis.google.com` | opt-in anketi + rozet iframe relay'i (`www.google.com` zaten vardı) |
| `img-src`     | `https://www.gstatic.com https://ssl.gstatic.com` | rozet yıldız/logolarının görselleri |
| `connect-src` | `https://apis.google.com` | widget'ın config/olay fetch'i |

> Sıkı tutuldu: yalnız `apis.google.com` + `gstatic` alt alanları; wildcard yok. Rozetin
> anket iframe'i `https://www.google.com/shopping/customerreviews/…`'ten gelir ve
> `www.google.com` **zaten** `frame-src`/`img-src`'de mevcut.

## Yeni tam CSP değeri (Cloudflare'e yapıştır — tek satır)

```
default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://www.googletagmanager.com https://www.google-analytics.com https://ssl.google-analytics.com https://www.googleadservices.com https://www.google.com https://googleads.g.doubleclick.net https://pagead2.googlesyndication.com https://static.cloudflareinsights.com https://www.paytr.com https://*.paytr.com https://connect.facebook.net https://apis.google.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: blob: https://*.google-analytics.com https://*.analytics.google.com https://analytics.google.com https://www.googletagmanager.com https://www.google.com https://www.google.com.tr https://*.doubleclick.net https://googleads.g.doubleclick.net https://www.googleadservices.com https://pagead2.googlesyndication.com https://www.facebook.com https://www.gstatic.com https://ssl.gstatic.com; font-src 'self' data: https://fonts.gstatic.com; connect-src 'self' https://www.googletagmanager.com https://*.google-analytics.com https://*.analytics.google.com https://analytics.google.com https://www.google.com https://googleads.g.doubleclick.net https://*.doubleclick.net https://www.googleadservices.com https://pagead2.googlesyndication.com https://cloudflareinsights.com https://static.cloudflareinsights.com https://connect.facebook.net https://www.facebook.com https://apis.google.com; frame-src 'self' https://www.paytr.com https://*.paytr.com https://www.googletagmanager.com https://td.doubleclick.net https://www.google.com https://bid.g.doubleclick.net https://apis.google.com; frame-ancestors 'self'; base-uri 'self'; form-action 'self' https://www.paytr.com https://*.paytr.com; object-src 'none'; worker-src 'self' blob:
```

## Doğrulama (CSP düzeldikten + storefront deploy edildikten sonra)

1. Herhangi bir sayfa → DevTools **Console**'da CSP "blocked" satırı **olmamalı**
   (`apis.google.com`, `gstatic.com` için).
2. **Network**'te `platform.js` **200** dönmeli (bloke değil).
3. Gerçek/test ödeme → `/odeme/sonuc`'ta Google'ın **opt-in kartı** görünmeli.
4. **Rozet:** mağaza yeterli yorum biriktirene kadar Google rozeti göstermez — CSP doğruyken
   bile hemen görünmeyebilir; bu normaldir (yorum eşiği Google tarafında).

## Not
- Bu, CSP politikasının **rev5: Google Customer Reviews** revizyonu. `CSP_REPORT_ONLY.md`
  revizyon geçmişine işlenmeli (o dosyayı server oturumu yönetiyor — çakışmayı önlemek için
  rev5 kaydını server ekler; bu dosya paste-ready değeri taşır).
- Referans rev4 değeri: `BUILD_CSP_META_PIXEL.md` (2026-09-01 canlı CSP).
- Storefront tarafı hazır ve doğru: `components/GoogleReviewsBadge.tsx` (site geneli,
  BOTTOM_LEFT), `components/GoogleCustomerReviews.tsx` (opt-in), ortak yükleyici
  `lib/googlePlatform.ts`. CSP + deploy dışında kod değişikliği gerekmez.
