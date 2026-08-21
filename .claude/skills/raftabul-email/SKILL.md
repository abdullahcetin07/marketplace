---
name: raftabul-email
description: Plan and draft Turkish lifecycle & marketing emails for Raftabul, sent through the platform's Amazon SES setup (MAIL_MAILER=ses, eu-west-1). Covers welcome/points, abandoned cart, order/shipping updates, delivery + review nudge, and win-back — with KVKK consent (ETK/İYS) rules and deliverability (SPF/DKIM/DMARC) baked in. Use when writing email copy, designing a lifecycle sequence, or planning campaigns. Adapts coreyhaines31/marketingskills `emails` to Raftabul.
---

# Raftabul E-posta Pazarlama

Raftabul e-postayı **Amazon SES** üzerinden yollar (`MAIL_MAILER=ses`, **eu-west-1**,
HTTPS API — host SMTP portlarını kapatıyor). SPF/DKIM/DMARC kurulu. İşlemsel
bildirimler kuyruklu (`ShouldQueue`). **SES prod erişimi onaylanınca** (sandbox'tan
çıkınca) gerçek müşteriye ulaşır — kampanya yollamadan önce bunu doğrula.

## ⚖️ KVKK / ETK / İYS — önce izin

Türkiye'de **ticari elektronik ileti** (kampanya, promosyon) için **açık rıza** ve
**İYS (İleti Yönetim Sistemi)** kaydı şarttır. Bu skill ikisini ayırır:

- **İşlemsel (rıza gerekmez):** sipariş onayı, ödeme, kargo/teslimat, iade, şifre
  sıfırlama. Bunlar bilgilendirmedir — pazarlama değil, içine kampanya sokma.
- **Pazarlama (rıza + İYS gerekir):** hoş geldin indirimi, sepeti terk, win-back,
  bülten. Yalnızca **onay vermiş** kullanıcıya; her iletide **kolay abonelikten
  çıkma** (tek tık) zorunlu. Onay yoksa gönderme.

Emin değilsen iletiyi **işlemsel** kapsamda tut ya da gönderme. Yanlış ticari ileti
para cezasıdır.

## Marka sesi & biçim

- Güvenilir, sıcak, sade Türkçe (doğru ı/İ/ş/ç/ğ). Marka: **raftabul**, turuncu aksan.
- Konu satırı: net + merak, tık-tuzağı değil. ~35–50 karakter, mobil önizlemeye göre.
- Ön izleme metni (preheader) konuyu tamamlasın.
- Tek net **CTA** (birincil eylem). Uzun duvar metin yok; taranabilir.
- Mobil-öncelikli tek kolon. Görsel yüklenmese de anlam bozulmasın (alt metin).
- Alt bilgi: işleten **AMIAY**, iletişim (destek@raftabul.com), abonelikten çık,
  KVKK/gizlilik linki.

## Yaşam döngüsü (öncelik sırası)

1. **Hoş geldin + puan** (kayıt sonrası, izinliyse): "Aldıkça Kazan"ı tanıt, ilk
   alışverişe yönlendir. Hoş geldin puanı varsa net söyle.
2. **Sipariş/ödeme onayı** (işlemsel): kalemler, tutar (KDV dahil), sipariş no.
3. **Kargo & teslimat** (işlemsel): kargo firması + takip no; "Teslim aldım" hatırlatma.
4. **Teslimat sonrası yorum daveti** (işlemsel-sınırda; yumuşak): yorum yap → puan.
5. **Sepeti terk** (pazarlama, izinli): kalan ürün + kargo bedava hatırlatma + tek CTA.
   Aşırı sıklık yok (1–2 ileti).
6. **Win-back** (pazarlama, izinli): uzun süre pasif müşteri; yeni kategori/kampanya.

## Deliverability

- Gönderen: doğrulanmış alan (raftabul.com), tutarlı "From". SPF/DKIM/DMARC hizalı.
- Spam tetikleyicilerinden kaçın (CAPS, "%100 BEDAVA!!!", aşırı ünlem, tek dev görsel).
- Metin/görsel dengesi; text-part her zaman olsun.
- Liste hijyeni: sert dönenleri (hard bounce) düş, şikayetleri (complaint) çıkar.

## İş akışı

1. Amaç + segment + **izin durumu** (işlemsel mi, pazarlama mı) belirle. İzin yoksa dur.
2. Konu + preheader + gövde + tek CTA yaz; sağlık ürününde yasadışı vaat yok
   (bkz. [[raftabul-product-copy]] beyan kuralı).
3. Diziyse: adım/zamanlama/çıkış koşulu (dönüşünce dur) tanımla.
4. Çıktı: konu, preheader, gövde (Türkçe), CTA metni + hedef URL, alt bilgi notu.
5. Teknik gönderim gerekiyorsa: bu storefront'un değil **backend/notification**
   işidir → server iş emri olarak ilet (bu skill metni ve stratejiyi üretir).

## Sınır

Bu skill **içerik + strateji** üretir; SES/kuyruk/şablon kodu backend tarafıdır. Gerçek
kampanya gönderimi için önce SES prod erişimi + İYS onay durumunu doğrula.
