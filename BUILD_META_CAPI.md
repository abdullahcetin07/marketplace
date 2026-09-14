# Meta Conversions API (server-side Purchase) — iş emri

**Neden:** Storefront'un tarayıcı Meta Pixel'i **KVKK çerez onayından sonra** ateşliyor;
onaylamayan ziyaretçide hiç ateşlemiyor. Canlı ölçüm (son 14 gün, 2 aktif kampanya):
**~1.951 tık → 10 landing page view, 0 purchase.** Yani Meta trafiğin ~%99'unu görmüyor ve
optimize ettiği olayı (satın alma) hemen hiç almıyor. CAPI, aynı Purchase'ı **sunucu taraflı**,
PayTR callback'inde (paranın kaynağı), tarayıcı onayından bağımsız gönderir.

**Dedup:** Event `event_id = payment uuid` taşır — tarayıcı pixel'inin `/odeme/sonuc`'ta
gönderdiği `eventID`'nin aynısı. Meta ikisini tek dönüşüme indirger (onaylı kullanıcı iki kez
sayılmaz, onaysız kullanıcı yine sayılır).

## Kod — bu commit'te yazıldı (INERT, kaydedilmedi)

| Dosya | Rol |
|---|---|
| `config/marketing.php` | `meta` bloğu: enabled (default **false**), pixel_id, access_token, api_version, test_event_code |
| `app/Modules/Marketing/Domain/DTOs/PurchaseConversionDTO.php` | Payload DTO (minor units; content_ids = **ürün uuid**) |
| `app/Modules/Marketing/Domain/Contracts/ConversionsApiContract.php` | Gönderici arayüzü |
| `app/Modules/Marketing/Infrastructure/MetaConversionsApiClient.php` | Graph `/{pixel}/events` POST; SHA-256 e-posta; decimal string; 2xx=başarı |
| `app/Modules/Marketing/Application/Jobs/SendMetaConversionJob.php` | Kuyruk işi (callback Graph'ı beklemesin); BaseJob |
| `app/Modules/Marketing/Application/Listeners/SendMetaPurchase.php` | `PaymentSucceeded`'i **class-string** dinler; OrderQueryContract ile e-posta + satırları toplar |
| `app/Modules/Marketing/MarketingServiceProvider.php` | Contract binding + class-string listener kaydı |

**Mimari:** Marketing **hiçbir modülü import etmez** — `PaymentSucceeded` class-string ile
dinlenir (`use` yok), her şey `OrderQueryContract` (Core port) üzerinden okunur. `LayeringTest`
iki yönde de yeşil kalmalı. Para minor units (ADR-005); decimal string yalnız HTTP ucunda.

## Sunucuda yapılacaklar (Docker gerektiği için burada yapılmadı)

1. **`make check`** — phpstan/lint/test. Yeni modülde tip/uyum hatası çıkarsa düzelt (bende
   çalıştıramadım). Beklenen sorun alanları: `data_get` dönüşlerinin cast'leri, BaseJob DI.
2. **Provider'ı kaydet:** `bootstrap/providers.php`'e Payment'tan **sonra** ekle:
   ```php
   App\Modules\Marketing\MarketingServiceProvider::class,
   ```
3. **Modül dokümanı + index** (CLAUDE.md konvansiyonu): `docs/modules/Marketing.md` oluştur ve
   `app/Modules/README.md` index'ine ekle. (Bir ADR gerekmiyor — yeni bir dışa-bağlı entegrasyon,
   mevcut bir kararı değiştirmiyor; yine de Marketing.md'de "neyi neden" + maliyet yazılmalı.)
4. **Test yaz:** listener'ın `PaymentSucceeded`'de doğru DTO ürettiğini (email + content_ids =
   ürün uuid + value = amountMinor), disabled iken hiçbir şey dispatch etmediğini; client'ın
   disabled/tokensız iken POST atmadığını. HTTP fake ile.
5. **Env (prod):**
   ```
   META_PIXEL_ID=2082722212251736
   META_CAPI_TOKEN=<Events Manager → Conversions API → Generate access token>
   META_API_VERSION=v21.0
   META_CAPI_ENABLED=true          # test bitene kadar false tut
   META_CAPI_TEST_EVENT_CODE=TESTxxxx   # doğrulama sırasında; prod'da boş
   ```

## Doğrulama (Meta Events Manager → Test Events)

1. `META_CAPI_TEST_EVENT_CODE`'u set et, `META_CAPI_ENABLED=true`, `queue:work` çalışıyor olsun.
2. Gerçek/test ödeme yap → PayTR callback → `SendMetaPurchase` → job → CAPI POST.
3. Test Events'te **Purchase** görünmeli; `event_id` = payment uuid; value/currency doğru;
   `Server` kaynaklı. Tarayıcı pixel'i de aynı `eventID` ile atarsa **"Deduplicated"** etiketi
   çıkar — istediğimiz bu.
4. Sağlıklıysa test_event_code'u kaldır, prod'a al.

## v2 (opsiyonel, eşleşme kalitesi) — fbp/fbc yakalama

Callback'te tarayıcı yok; şu an sadece e-posta hash'i match key. Attribution'ı güçlendirmek için
checkout anında (tarayıcı varken) `_fbp`/`_fbc` çerezlerini + IP/UA'yı payment satırına stash'le,
CAPI event'ine `fbp`/`fbc`/`client_ip_address`/`client_user_agent` olarak ekle. Ayrı iş; MVP'yi
bloke etmez.

## Not
- Değişkenler doğru: content_ids = **ürün uuid** — Meta katalog feed'inin `g:id`'siyle birebir
  (BUILD_GOOGLE_MERCHANT_FEED / Meta target), dynamic ads eşleşir.
- CAPI KVKK açısından: sunucuda kesinleşmiş bir işlem (paid order) kaydı; yine de owner'ın
  gizlilik/consent yaklaşımına uygunluğu teyit edilmeli.
