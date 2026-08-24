# BUILD — Mail dayanıklılık: retry_after + geçersiz-alıcı guard'ı

**Problem (canlı gözlendi):** Failover round-robin, başarısız bir transport'u
`config/mail.php`'deki **`retry_after` (şu an 60 sn)** boyunca "ölü" sayar. Tek bir
**geçersiz alıcı** (`@example.com` → Resend `Invalid 'to' field`) Resend'i 60 sn ölü
işaretler → **o pencerede kuyruğa giren GERÇEK mailler de düşer** (No transports found).
Sipariş/şifre-sıfırlama bildirimi patlaması sırasında sessiz mail kaybı.

Bu iş **backend**.

## Düzeltmeler

### 1. `retry_after` düşür (blast radius)
`config/mail.php` failover mailer `retry_after`: **60 → 5**. Ölü pencere 60 sn yerine 5 sn.
(Tümden 0/çok küçük yapmak round-robin'in amacını bozabilir; 5 sn makul denge.)

### 2. Geçersiz/test alıcı guard'ı (asıl savunma)
Gönderim öncesi, bilinen **test/geçersiz domain'lere** giden bildirimleri **gönderme, logla,
kuyruğu tıkatma** — böylece bir sahte alıcı transport'u öldürmez.
- Blocklist (config): `mail.blocked_recipient_domains` = `['example.com','example.org',
  'example.net','test.com','mailinator.com']`.
- Uygulama noktası: `BaseNotification` seviyesinde `shouldSend()`/`via()` ya da bir global
  `MessageSending` listener'ı — adresi blocklist'teyse **atla + `info` log** ("blocked test
  recipient"), exception fırlatma.
- **Enumeration/KVKK'yı bozma:** bu yalnız GÖNDERİMİ atlar; API yanıtı (ADR-025) aynı kalır.

### 3. (Bağlantılı) test hesaplarını temizle
Sipariş/puan/yorum'u olmayan test-domain hesaplarını **soft-delete** (liste verip onaylatarak).
Tetikleyiciyi büyük ölçüde kaldırır; guard gelecekteki test kayıtlarına karşı kalıcı savunma.

## Testler
1. Blocklist domain'ine bildirim → **gönderilmez**, loglanır, exception yok, transport
   ölmez; aynı worker'daki geçerli mail **etkilenmez**.
2. `retry_after=5` config'te doğrulanır.
3. Gerçek adrese bildirim → normal gider (regression yok).
4. Modül import yok; mevcut `BaseNotification` kuyruk davranışı korunur.

## Non-goals
- Tam e-posta doğrulama servisi / MX kontrolü (v1 basit domain-blocklist yeter).
- Storefront değişikliği.
