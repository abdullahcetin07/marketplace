# BUILD — Müşteri şifre kuralını gevşet (satıcı/admin sıkı kalsın)

**Problem:** Storefront müşterisi için şifre kuralı çok sıkı → kayıt/şifre-sıfırlamada
sürtünme. Mevcut `StrongPassword::customer()`: **min 12 + mixedCase + numbers +
uncompromised(3)**. Bir alışverişçi için 12 karakter + büyük/küçük harf zorunluluğu fazla.

Bu iş **backend** (Identity/Shared). Storefront'a dokunma — sunucu tarafı doğrulama
otoritedir; storefront zaten API hatasını gösteriyor.

## Değişiklik — `app/Shared/Rules/StrongPassword.php`

Şu an `customer()` **hem müşteri hem satıcı** için kullanılıyor. **Ayır:**

1. **`customer()` (GEVŞET) — müşteri:**
   ```
   Password::min(8)->letters()->numbers()->uncompromised(3)
   ```
   - 12 → **8**, `mixedCase()` **kaldırıldı**.
   - `uncompromised(3)` **KALSIN** — yalnız bilinen-ihlal şifreleri (ör. "12345678") engeller,
     kullanıcıyı yormaz, en yüksek getirili koruma.
   - `letters()+numbers()`: en az bir harf + bir rakam (basit ama "00000000"ı eler).

2. **`seller()` (YENİ, SIKI KALIR) — satıcı:** bugünkü müşteri kuralının aynısı:
   ```
   Password::min(12)->mixedCase()->numbers()->uncompromised(3)
   ```

3. **`for(UserType $type)` yönlendirmesini güncelle:**
   - `Admin`/staff → `staff()` (mevcut, 14 + semboller) — dokunma.
   - `Seller` → **`seller()`** (yeni).
   - `Customer` → `customer()` (gevşetilmiş).

> Bu bir **güvenlik politikası gevşetmesi** — bilinçli, müşteri sürtünmesini azaltmak için.
> Satıcı ve admin **etkilenmez**. `uncompromised` korunduğu için zayıf-ama-yaygın şifreler
> yine engelli.

## Etki
- **Kayıt** (`register`) ve **şifre sıfırlama** (`password/reset`) müşteri için 8 karakterle geçer.
- Var olan şifreler etkilenmez (kural yalnız yeni şifre belirlemede uygulanır).

## Testler
1. Müşteri kaydı: `"parola12"` (8, harf+rakam, ihlal değil) → **geçer**; `"kisa1"` (5) → reddedilir.
2. Bilinen-ihlal şifre (ör. `"password1"` HIBP'de) → reddedilir (uncompromised çalışıyor).
3. **Satıcı** kaydı/sıfırlaması hâlâ 12 + mixedCase ister (regression yok) — `seller()` testi.
4. Admin `staff()` değişmedi.
5. `StrongPassword::for(UserType::Customer|Seller|Admin)` doğru kurala yönlendiriyor.

## Non-goals
- Storefront değişikliği (API hatası zaten gösteriliyor; istenirse ayrıca client-side ipucu
  eklenebilir — ayrı iş).
- Şifre gücü göstergesi/zxcvbn (v1 dışı).
