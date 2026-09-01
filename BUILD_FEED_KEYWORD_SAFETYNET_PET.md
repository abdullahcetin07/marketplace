# BUILD — Feed güvenlik-ağı EK: pet-medikal kelimeler (GMC appeal temizliği)

**Bağlam:** `BUILD_FEED_KEYWORD_SAFETYNET`'in devamı. GMC appeal hazırlığında, **Pet Shop**
içine dağılmış birkaç **pet-medikal** ürün bulundu (cerrahi yakalık + pet takviyeleri).
Bunlar Google'ın sağlık/takviye politikasına takılabilir.

**⚠️ Pet Shop KÖKÜ KALIR — komple çıkarılmaz.** Canlı denetim: Pet Shop = **2.195 ürün**,
hayvan-bazlı kategoriler (Köpek/Kedi/Kuş/Akvaryum…), **ayrı veteriner/medikal alt kategorisi
YOK**. Sorunlu item **16 tane** (2.195'in %0,7'si) — mama/oyuncak/tımar/akvaryum çoğu
Google'da serbest ve ciddi gelir. Bu yüzden **kategori değil, ANAHTAR-KELİME** ile temizlik.

## Değişiklik — `feed.google.excluded_title_keywords`'e EKLE
Mevcut listeye (fold + kelime-sınırı mantığı **aynen**, `BUILD_FEED_KEYWORD_SAFETYNET`
kuralları geçerli) şunları ekle:
```
ameliyat boğazlığı
elizabeth yakalık
veteriner
multivitamin
```
Opsiyonel (pire/kene ilaçları = pestisit/veteriner sayılabilir; false-positive'i düşük):
```
pire tasması
parazit
```

**Kapsanan 16 item (canlı feed'den):** `Ameliyat Boğazlığı -Elizabeth Yakalık-`,
`Ameliyat Boğazlığı -Yakalık- No:1..7`, `Daisy Multivitamin Kedi`, `Fizzy Kemirgen
Multivitamin`, `Cute Faces Multivitamin Premiks`, `Daisy Veteriner Plus For Paws`.

## 🔴 KRİTİK — yanlış pozitif (kozmetiği öldürme)
- **`vitamin` (bare) EKLENMEZ** — "Uriage ... C Vitamini Serum", "Vitamin C %10 Serum" prime
  kozmetik; bare `vitamin` bunları öldürür. Sadece **`multivitamin`** ekle (takviyeye özgü).
- `multivitamin` için **yanlış-pozitif testi zorunlu**: eğer feed'de "multivitamin" geçen
  bir **kozmetik** varsa (ör. "Multivitamin Yüz Kremi"), o düşmemeli — çıkıyorsa `multivitamin`'i
  daha dar bir ifadeye çevir (ör. hayvan terimiyle eşle) ya da o item'ı beyaz-listele.
- `veteriner` güvenli (kozmetikte geçmez). `ameliyat boğazlığı` / `elizabeth yakalık` çok spesifik.

## Test
1. `Ameliyat Boğazlığı -Elizabeth Yakalık-` ve `No:1..7` feed'de **YOK**.
2. `Daisy Multivitamin Kedi`, `Fizzy Kemirgen Multivitamin`, `Daisy Veteriner Plus` **YOK**.
3. **Yanlış-pozitif kanıtı (zorunlu):** "Uriage Depiderm C Vitamini Serum", "Vitamin C %10 Serum"
   feed'de **VAR** (elenmedi). "multivitamin" içeren bir kozmetik varsa o da **VAR**.
4. **Pet Shop gıda/oyuncak/aksesuar regresyon YOK** — mama, tasma (normal), oyuncak, akvaryum
   ürünleri feed'de duruyor (yalnız 16 pet-medikal düştü, ~2.179 kaldı).
5. Şapka/Latin-1 + insan-sağlık kelimeleri (önceki güvenlik ağı) regresyon yok.
6. Fold + kelime-sınırı: "veteriner" → "Veteriner"/"veterıner"; `parazit` eklenirse "raftabul"
   gibi masum kelimeleri elemez.

## Non-goals
- **Pet Shop kökünü çıkarma** (yalnız 16 pet-medikal item, kelimeyle).
- Storefront değişmez.
- Server temizleyince ben feed'i tekrar tararım → **pet-medikal 0** + kozmetik regresyon yok
  doğrularım → GMC appeal açıklamasını güncelleriz (pet-medikal de kaldırıldı).
