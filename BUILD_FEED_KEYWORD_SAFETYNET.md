# BUILD — Feed: içerik-anahtar güvenlik ağı (yanlış-kategorili tıbbi ürünler)

**Bağlam:** `BUILD_FEED_EXCLUDE_HEALTH` (kök elemesi) sağlık ağacını çıkardı. Ama bağımsız
canlı denetim, **sağlık kökünde OLMAYAN ama içeriği tıbbi** 2 item buldu (regex dar; daha
fazlası olabilir):
- **"Lamiderm Yara ve Yanık Kremi"** → `Cilt Bakımı > Cilt Bakım Kremleri` (tedavi ürünü, kozmetik altına filelanmış) — **gerçek risk**
- **"Bebedor Banyo Termometresi"** → `Anne ve Bebek` (banyo termometresi; tıbbi değil ama "termometre" Google sınıflandırıcısını tetikleyebilir) — düşük risk

Kategori elemesi **gerekli ama yeterli değil**. GMC appeal'ini temiz feed'le kullanmak için
**item-seviyesinde ikinci bir ağ** gerekiyor.

## Değişiklik — feed builder'a başlık-anahtar elemesi (kategori elemesinin YANINA)
- Yeni config: **`feed.excluded_title_keywords`** (liste, sürüm-kontrollü — `excluded_category_slugs`
  gibi tunable). Bir item'ın **başlığı** (istenirse product_type) bu terimlerden birini içeriyorsa
  feed'den **hariç tut** — hangi kökte olduğuna bakmadan.
- **Eşleşme Türkçe-fold + kelime-sınırı olmalı** (yanlış pozitifi önlemek için):
  - **Kademe 1'in `TurkishFold`'unu yeniden kullan** (Catalog/Domain/Support) → `yanik`=`yanık`,
    `agiz`=`ağız` ikisi de yakalanır.
  - **Kelime-sınırı zorunlu** — substring eşleşme FELAKET olur: `yara` → `yaratıcı`'yı,
    `aft` → `kaftan`/`raftabul`'u yakalar. Fold + `\b` (ya da boşluk/uç) ile eşle.

## ⚠️ KRİTİK — kozmetiği YANLIŞ eleme (yanlış pozitif tuzakları)
Bu terimler **feed'in en değerli ürünlerini** öldürür; listeye **KOYMA:**
- ❌ **`vitamin`** (bare) — "Uriage ... **C Vitamini** Serum", "Vitamin C %10 Serum" = prime kozmetik. Elenmemeli.
- ❌ `krem`, `serum`, `bakım`, `onarıcı`, `leke`, `nemlendir` — hepsi meşru kozmetik.
- Test bunu **kanıtlamalı** (aşağıda).

## Başlangıç anahtar listesi (dar, net tıbbi — server tune eder)
```
yara ve yanık      (tek başına "yara" değil — "yara ve yanık" / "yanık kremi" ibaresi)
yanık kremi
aft                (kelime-sınırıyla — aft/aftöz; kaftan/raftabul DEĞİL)
nebulizatör
tansiyon aleti
termometre
ortopedik
prezervatif · kayganlaştırıcı · geciktirici · afrodizyak · performans arttırıcı
zayıflama · diyet hapı
medikal
```
> `tedavi` başlıkta nadiren geçer ama geçerse tıbbidir — dar biçimde (başlıkta) eklenebilir;
> açıklamada arama, yanlış pozitif riski yüksek olduğu için **hayır**. `takviye` zaten kök
> elemesinde; belt-and-braces istersen ekle. **`vitamin` asla.**

## Test
1. **"Lamiderm Yara ve Yanık Kremi"** → feed'de **YOK** (Cilt Bakımı altında olsa bile).
2. **"Bebedor Banyo Termometresi"** → `termometre` ile **YOK** (düşük-değer, kabul edilebilir).
3. **YANLIŞ POZİTİF KANITI (zorunlu):** "Uriage Depiderm **C Vitamini** Serum", "Skins Derm
   Vitamin C %10 Serum", "Onarıcı Krem" → feed'de **VAR** (elenmiyor).
4. **Fold:** başlıkta `yanik` (şapkasız) yazan bir item da yakalanır (`yanık` ile aynı).
5. **Kelime-sınırı:** `aft` kuralı `raftabul`/`kaftan` içeren bir başlığı **elemez**.
6. Feed item sayısı önce/sonra loglanır; elenen item'lar **listelenir** (yanlış pozitif gözden geçirilebilsin).

## Non-goals / not
- **Yalnız FEED** — storefront/katalog değişmez; ürünler sitede satılır.
- Kategori kökü elemesi (`excluded_category_slugs`) **kalır** — bu onu tamamlar, değiştirmez.
- **Marka açığı ayrı iş:** canlı feed'de `g:brand` kapsamı **%54** (2.553/4.762) — ~%46 markasız.
  GMC çoğu kategoride marka ister; reddin ikinci sebebi bu olabilir. **Marka backfill** ayrı bir
  katalog-veri işi (GMC gerekçesi "marka eksik" derse önceliklendir).
- Server temizleyince ben feed'i tekrar tararım → **0 sızıntı** doğrularım → **sonra GMC appeal.**
