# Cloudflare CSP — Meta Pixel izinleri (rev4)

**Sorun:** Storefront kodu doğru (pixel `connect.facebook.net`'ten yüklenmeye çalışıyor,
bundle'da doğrulandı) ama **Cloudflare'deki Content-Security-Policy** bu alan adına izin
vermediği için tarayıcı `fbevents.js`'i bloke ediyor → `fbq` tanımlanmıyor → Pixel Helper
hiçbir olay görmüyor. CSP **nginx/Next'te değil, Cloudflare katmanında** set ediliyor
(origin yanıtında CSP yok, yalnız Cloudflare üzerinden gelende var) — bu yüzden **repo'dan
düzeltilemez, Cloudflare panelinden** düzeltilir.

## Owner adımları (Cloudflare paneli)

1. Cloudflare → **raftabul.com** → **Rules → Transform Rules → Modify Response Header**.
2. `Content-Security-Policy` başlığını **Set** eden kuralı aç.
3. Değeri, aşağıdaki **yeni tam string** ile değiştir (tek satır, paste-ready).
4. **Deploy** → 1–2 dk sonra `curl -sSI https://raftabul.com/ | grep -i content-security`
   ile `connect.facebook.net` ve `www.facebook.com`'un geçtiğini teyit et.

## Değişen üç direktif

| Direktif | Eklenen |
|---|---|
| `script-src`  | `https://connect.facebook.net` |
| `img-src`     | `https://www.facebook.com` |
| `connect-src` | `https://connect.facebook.net https://www.facebook.com` |

> `img-src`: pixel olayları `www.facebook.com/tr/?...` beacon'ıyla gider.
> `connect-src`: yeni `fbevents.js` config/olay için fetch/sendBeacon kullanır.
> Sıkı tutuldu (`www.facebook.com`, wildcard değil). CAPI (sunucu-taraflı) CSP gerektirmez.

## Yeni tam CSP değeri (Cloudflare'e yapıştır — tek satır)

```
default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://www.googletagmanager.com https://www.google-analytics.com https://ssl.google-analytics.com https://www.googleadservices.com https://www.google.com https://googleads.g.doubleclick.net https://pagead2.googlesyndication.com https://static.cloudflareinsights.com https://www.paytr.com https://*.paytr.com https://connect.facebook.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: blob: https://*.google-analytics.com https://*.analytics.google.com https://analytics.google.com https://www.googletagmanager.com https://www.google.com https://www.google.com.tr https://*.doubleclick.net https://googleads.g.doubleclick.net https://www.googleadservices.com https://pagead2.googlesyndication.com https://www.facebook.com; font-src 'self' data: https://fonts.gstatic.com; connect-src 'self' https://www.googletagmanager.com https://*.google-analytics.com https://*.analytics.google.com https://analytics.google.com https://www.google.com https://googleads.g.doubleclick.net https://*.doubleclick.net https://www.googleadservices.com https://pagead2.googlesyndication.com https://cloudflareinsights.com https://static.cloudflareinsights.com https://connect.facebook.net https://www.facebook.com; frame-src 'self' https://www.paytr.com https://*.paytr.com https://www.googletagmanager.com https://td.doubleclick.net https://www.google.com https://bid.g.doubleclick.net; frame-ancestors 'self'; base-uri 'self'; form-action 'self' https://www.paytr.com https://*.paytr.com; object-src 'none'; worker-src 'self' blob:
```

## Doğrulama (CSP düzeldikten sonra, tarayıcıda)

1. Banner'da hiçbir şey seçmeden gez → **hiçbir olay yok**.
2. **Kabul Et** → `PageView`.
3. Ürün → `ViewContent` (content_ids dolu) · Sepete ekle → `AddToCart` · Ödeme → `InitiateCheckout`.
4. Gerçek ödeme → `Purchase` (value = tutar, content_ids dolu — b7bd3f9 sonrası, eventID = transaction_id).

## Not
- Bu, CSP politikasının **rev4: Meta Pixel** revizyonu. `CSP_REPORT_ONLY.md`'deki
  revizyon geçmişine işlenmeli (server oturumu o dosyayı yönetiyor — çakışmayı önlemek
  için rev4 kaydını server ekliyor).
- Referans olarak alınan canlı CSP değeri: `curl -sSI https://raftabul.com/` (2026-09-01).
