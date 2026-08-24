# BUILD — Bozuk kök kategorileri onar (ADR-074 çift-slug artığı)

**Problem:** ADR-074 toplu içe aktarımından kalma **adı iki kez birleşmiş, ağacın tepesinde
duran bozuk KÖK kategoriler** var. Slug ve/veya ad tekrarlı; doğru dalın (ör.
`besin-takviyeleri`) **altında değil, kök seviyesinde** duruyorlar. Bu hem feed'i (takviye
exclude'ı `excluded_category_slugs`'a elle 4 slug eklemek zorunda kaldı) hem **storefront
menüsünü/kategori sayfalarını** kirletiyor (kullanıcı "Magnezyum BisglisinatMagnezyum
Bisglisinat" diye zaten şikayet etmişti).

**Tespit edilenler (prod):**
| Bozuk kök slug | Ürün | Doğru dal (olması gereken) |
|---|---|---|
| `d3-k2-vitaminid3-k2-vitamini` | 79 | Besin Takviyeleri > D3-K2 Vitamini |
| `magnezyum-bisglisinatmagnezyum-bisglisinat` | 44 | Besin Takviyeleri > Magnezyum … |
| `antioksidan-iceren-e-vitaminleriantioksidan-iceren-e-vitaminleri` | 9 | Besin Takviyeleri > Vitaminler |
| `takviye-edici-gida-urunleri` | 2 | Besin Takviyeleri (kendisi ya da alt) |

(Server tarama listesi otoriter — bu tabloyu prod'daki gerçek tespitten güncelle. Başka
çift-slug kök kategori varsa hepsi kapsansın.)

Bu iş **backend** (Catalog veri düzeltmesi). Storefront'a dokunma — menü/kategori bu ağaçtan
besleniyor, ağaç düzelince kendiliğinden düzelir.

## Yaklaşım (idempotent, güvenli)

**Her bozuk kök için:**
1. **Doğru üst kategoriyi bul/oluştur** (Besin Takviyeleri altında ilgili alt dal). Yeni
   kategori gerekiyorsa **authoring/kategori yolundan** oluştur (raw insert değil) — slug
   registry + `accepts_products` + moderasyon davranışı korunsun (ADR-038/047).
2. **Ürünleri taşı:** bozuk kökteki ürünleri doğru alt kategoriye **yeniden ata**
   (search reindex + ilgili event'ler tetiklensin — feed/storefront tutarlı kalsın).
3. **Bozuk kök kategoriyi kaldır** (ürünsüz kaldıktan sonra) — ya da doğru kategoriyle
   **birleştir**. Slug registry'den de temizle.
4. **Slug'ı normalize et:** adı/slug'ı tek sefer olacak şekilde düzelt (çift birleşmeyi geri al).

> **Not:** Ürün taşınınca artık `besin-takviyeleri` exclude'ı bu takviyeleri de kapsar →
> feed config'inden bu 4 (+zayiflama) manuel slug **kaldırılabilir** (no-op olur ama
> temizlik için). Sıra önemli: **önce ağaç düzelsin, sonra config sadeleştir** — yoksa bir
> pencere için takviyeler feed'e sızabilir. Güvenli tarafta kalmak için config'teki
> exclude'ları **bırakmak** da olur (zararsız).

## Testler
1. Bozuk kök slug'lar (çift-birleşmiş) **artık yok**; ürünleri doğru `besin-takviyeleri`
   alt dalına taşınmış.
2. Taşınan ürünler **search index'te** doğru kategoriyle görünür (reindex tetiklendi) — ≥2
   satırlı fixture (strict-mode).
3. Storefront kategori ağacı/menüsü çift-adlı kategori **göstermiyor** (kategori API'si temiz).
4. Feed: taşınan takviyeler `besin-takviyeleri` exclude'ıyla **hariç** (regression yok).
5. `accepts_products = false` insan kararı olan kategoriler ezilmedi (ADR-047).
6. Idempotent: komut ikinci kez çalışınca değişiklik yapmaz.

## Non-goals
- Genel kategori-ağacı yeniden tasarımı (yalnız bu çift-slug artıkları).
- Storefront değişikliği.
- Feed exclude config'ini kaldırmak (opsiyonel temizlik — güvenli değilse bırak).
