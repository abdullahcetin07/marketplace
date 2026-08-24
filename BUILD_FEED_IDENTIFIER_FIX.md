# BUILD — Feed: `identifier_exists` GTIN varken 'yes' olmalı (ADR-086 düzeltme)

**Problem (canlı feed'de ölçüldü):** `/feed/google-merchant.xml`'deki 6.933 item'ın
**%100'ünde GTIN var**, ama **3.329 item (%48) `<g:identifier_exists>no</g:identifier_exists>`**
taşıyor — çünkü feed bu alanı **markanın varlığına** bağlamış (`brand ? yes : no` gibi).

**Neden yanlış:** GTIN **tek başına** geçerli bir benzersiz tanımlayıcıdır. Google'da
`identifier_exists`:
- **GTIN (veya brand+MPN) varsa → `yes`** (ya da alanı **hiç yazma** — default `yes`).
- **Yalnızca gerçekten GTIN/MPN/marka YOKSA → `no`.**

GTIN varken `no` demek Google'a "tanımlayıcı yok, GTIN'i dikkate alma" demektir →
**GTIN eşleşme avantajı** (yeni alan adında en güçlü kozumuz, ADR-086 notu) bu 3.329 üründe
**kaybolur**, ürünler Shopping'de zayıf eşleşir.

## Fix (feed builder)

`identifier_exists` mantığını **markadan** değil **tanımlayıcıdan** türet:

```
identifier_exists = (gtin !== null || (brand !== null && mpn !== null)) ? 'yes' : 'no'
// veya daha basit: GTIN varsa alanı HİÇ yazma (Google default 'yes').
```

Bizde tüm item'ların GTIN'i olduğundan `identifier_exists` **tüm feed'de `yes`/omit** olmalı;
`no` pratikte hiç çıkmamalı.

## Test
1. Feed'deki **GTIN'li her item** için `identifier_exists != 'no'` (yes ya da yok).
2. Markasız + GTIN'li item → `identifier_exists = yes` (veya alan yok) — regression pinlenir.
3. (Kurgusal) GTIN'siz + markasız item → `no` (o dal hâlâ doğru çalışıyor).

## Ek not — marka boşluğu (AYRI iş, bu emrin kapsamı değil)
Item'ların **%48'inde `g:brand` yok** (katalogda marka ilişkisi eksik). Google çoğu
kategoride markayı **ister**; markasız item'lar GMC'de reddedilebilir/kısıtlanabilir. GTIN
olduğu için eşleşme yine mümkün ama marka **backfill'i** (GTIN veritabanından ya da admin
importundan) ayrı bir **katalog veri işi**. GMC'de "marka eksik" uyarıları gelirse sebebi bu.

## Non-goals
- Marka backfill (ayrı katalog işi).
- Storefront değişikliği.
