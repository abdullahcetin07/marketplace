# BUILD: `/urunler` SSR 500 — kök neden (sunucu tarafı)

**Tarih:** 2026-09-03 · **Bildiren:** owner (canlı, raftabul.com)
**Belirti:** `Application error: a server-side exception has occurred` — `Digest: 2264697604`

## Desktop tarafı ne yaptı (TAMAM, push'landı)

Storefront dayanıklılık kalkanı — commit **`bfb12c1`** (`origin/main`):
- `browseProducts` iç-fetch blip'inde **1 retry** (4xx retry edilmez).
- `/urunler` sayfası **try/catch** → beyaz-ekran yerine "tekrar dene" paneli (200).

Bu **maskeler ama kök nedeni çözmez.** Rebuild edince beyaz-ekran biter; asıl
arıza altta durur. Aşağısı senin (sunucu) işin.

## Kesin teşhis (dıştan ölçüldü)

| Test | Sonuç |
|---|---|
| Backend `GET /api/v1/products?per_page=24` — 30 EŞZAMANLI | **30/30 → 200** ✅ |
| Ana sayfa `/`, ürün sayfası, `/sayfa/*` — 20 eşzamanlı | **200** ✅ |
| Feed `/feed/meta-catalog.xml` | **200** ✅ |
| **`/urunler` SSR — 20 EŞZAMANLI** | **20/20 → 500** ❌ (hızlı throw, ~0.4s) |
| `/urunler` tek tek (sıralı) | ~%30 500, gerisi 200 |

**Backend/DB/API kusursuz sağlam.** Arıza yalnız **Next.js storefront SSR'ının
iç fetch yolunda**, ve **yük altında** ortaya çıkıyor.

## Neden sadece `/urunler`?

- SSR fetch'leri `apiUrl()` ile **`http://127.0.0.1`** (INTERNAL_API_URL yoksa
  varsayılan; port 80 = nginx) üzerinden gidiyor — dışarıdaki Cloudflare yolu
  DEĞİL. Bu yüzden dıştan curl sağlam görünüyor, SSR içten patlıyor.
- Ana sayfa çağrıları (`getBestSellers` vb.) hatayı **try/catch ile yutup `[]`'e
  düşüyor** → 200. Listeleme `browseProducts` non-200'de **throw** ediyordu → 500.
  (Kalkan bunu değiştirdi ama iç-fetch **neden takılıyor** sorusu duruyor.)

## ✅ KÖK NEDEN BULUNDU VE DÜZELTİLDİ (sunucu, 2026-09-03)

**Hiçbiri değildi: ne FPM doygunluğu, ne soket, ne `INTERNAL_API_URL`.**
Digest `2264697604`'ün Next log'undaki karşılığı:

```
⨯ Error [ApiError]: POST /api/v1/offers/prices failed
  status: 429
```

Logda **11.094 adet**. Yani arıza **kendi rate limiter'ımız**dı.

`storefront` limiti IP başına 300/dk ve **SSR aynı kutuda, `127.0.0.1:8081`
üzerinden** çağırıyor — dolayısıyla **her ziyaretçinin render'ı tek kovayı
paylaşıyordu**. Bir listeleme sayfası iki çağrı harcıyor (`/products` + toplu
`POST /offers/prices`), yani dakikada birkaç düzine ziyaretçi kovayı bitiriyor →
429 → sayfa 500. Hatanın **hızlı** (~0,4 sn) ve **yalnız yük altında** çıkması
tam olarak bunun imzası; backend/DB/FPM gerçekten sağlamdı (ölçümler doğruydu,
yorumu yanlıştı).

**Düzeltme:** SSR limitten muaf — ama **IP'ye veya header'a göre DEĞİL**. Bu
topolojide her istek PHP-FPM'e nginx'ten loopback ile gelir (soket adresi ayırt
etmez) ve header'ı istemci de gönderebilir. Ayırt edici bir **CGI parametresi**:
loopback vhost'u (dışarıdan erişilemez) `fastcgi_param INTERNAL_RENDER 1` set
eder; istemcinin aynı isimli header'ı `HTTP_INTERNAL_RENDER` olarak gelir ve
eşleşmez — bir test bunu çiviler.

**Taviz:** kaçak bir SSR döngüsü artık burada sınırlanmıyor. Bu doğru takas:
o bizim hatamız olur ve yük olarak görünür; limiter dışarıdan kazımayı
sınırlamak için var ve tarayıcıdan gelen her çağrı hâlâ gerçek IP'siyle sayılır.

Dosyalar: `app/Providers/AppServiceProvider.php`,
`tests/Feature/StorefrontRateLimitTest.php`,
`/etc/nginx/sites-enabled/raftabul-prod-internal` + `test.raftabul.com`
(yedekler `.bak-20260903`).

### Ayrıca yapılanlar (bu emrin sonundaki liste)
- Açıklama partileri **#5–#9 uygulandı** — katalogda 898 zenginleştirilmiş ürün.
- Storefront `bfb12c1` **iki ortamda** derlendi (WhatsApp/Asistan/H2 dahil).

---

## (Devir notu — kök neden bulunmadan önceki hipotezler)

## Sunucu tarafı yap: kök nedeni bul

1. **Next.js log'unda digest'i ara:** `2264697604` → gerçek stack + fetch hatası
   (büyük olasılıkla `fetch failed` / `UND_ERR_SOCKET` / `ECONNRESET` /
   `ETIMEDOUT` `http://127.0.0.1/api/v1/products` için). systemd journal:
   `journalctl -u <next-service> --since "1 hour ago" | grep -iE "2264697604|fetch failed|ECONN|socket|products"`
2. **En olası neden — PHP-FPM worker doygunluğu:** Next SSR aynı kutuda; eşzamanlı
   listeleme render'ları `127.0.0.1`'e paralel iç istek atıyor, `pm.max_children`
   düşükse iç istekler 502/timeout alıyor. Kontrol: FPM pool `pm.max_children`,
   `pm.status` (`max_children reached` sayacı), nginx error.log `upstream`.
   - Gerekirse `pm.max_children` yükselt (RAM'e göre), FPM reload.
3. **`INTERNAL_API_URL` doğru mu?** SSR'ın PHP'ye giden en kısa/sağlam yola işaret
   ettiğinden emin ol (örn. nginx yerine doğrudan FPM/loopback, keep-alive). Yanlış
   port/host eşzamanlı yükte drop veriyor olabilir.
4. **Next süreç bellek/keep-alive:** undici keep-alive havuzu düz-HTTP loopback'te
   yük altında soket düşürüyorsa, servisin worker/bellek limitini ve
   `keepAliveTimeout` uyumunu kontrol et.

## Doğrulama (fix sonrası)

```bash
# 20 eşzamanlı — HEPSİ 200 olmalı
for i in $(seq 1 20); do curl -s -o /dev/null -w "%{http_code}\n" https://raftabul.com/urunler & done | sort | uniq -c; wait
```

Ayrıca yapılacak: **#5–#9 açıklama partilerini apply et**
(`catalog:import-descriptions PRODUCT_DESCRIPTIONS_BATCH_{500,600,700,800,900}.md --apply`)
ve storefront rebuild (bfb12c1 + WhatsApp/Asistan/H2 değişiklikleri için).

Bittiğinde bu dosyayı güncelle/sil ve **commit + push** et.
