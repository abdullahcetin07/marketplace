# Turuncukasa — Sunucu Kurulumu (Sunucu-tarafı Claude için iş emri)

> **Bu dosya sunucuda çalışan Claude içindir.** Masaüstü (frontend) oturumu bu iş
> emrini hazırladı; sen (srv.turuncukasa.com üzerindeki Claude) uygular, bulguları bu
> dosyanın **§9 Rapor** bölümüne yazıp **commit + push** edersin. Masaüstü oturumu
> raporu oradan okur. Raftabul entegrasyonunda işe yarayan yöntem budur.

## 0. Bağlam — bu ne, neden

turuncukasa (abdullah@turuncukasa.com'un ayrı markası) için, MarketplaceOS platformunu
**tek-satıcı (single-vendor) e-ticaret** olarak yeni bir sunucuya kuruyoruz. Raftabul'dan
**tamamen bağımsız**: ayrı sunucu, ayrı DB, ayrı şirket, ayrı müşteri tabanı, ayrı ödeme
(Halkbank sanal POS — PayTR değil).

**Sunucu (hosting teslim etti):**
- Ubuntu 24.04.5 LTS, KVM, 8 vCPU, 31 GiB RAM, 400 GB disk (382 GB boş)
- IPv4 `136.144.209.137`, IPv6 aktif, ens3, hostname `srv.turuncukasa.com`
- **Cloudflare** kullanılacak. Public site = `turuncukasa.com`; sunucu adı = `srv.turuncukasa.com`.

**Toplam plan 4 fazlı — bu iş emri yalnızca FAZ 1 ve FAZ 2'yi kapsar:**
1. **FAZ 1 — Provizyon:** OS sıkılaştırma + stack kurulumu. (Bu dosya)
2. **FAZ 2 — Smoke-deploy:** kodu **olduğu gibi (pazaryeri hâliyle)** ayağa kaldır, sadece
   "stack bu sunucuda çalışıyor mu?" doğrulaması. (Bu dosya)
3. **FAZ 3 — Adaptasyon:** single-vendor mode + turuncukasa teması + Halkbank gateway. **Ayrı iş emri gelecek.**
4. **FAZ 4 — Veri göçü:** T-Soft → ürün/varyant/müşteri/geçmiş sipariş/yorum + SEO 301. **Ayrı iş emri gelecek.**

> **FAZ 2'nin sonunda DUR.** Public DNS geçişi (turuncukasa.com'u bu sunucuya yöneltmek),
> canlı ödeme, gerçek veri — hiçbiri bu iş emrinde YOK. Onlar Faz 3–4 ve owner onayı.

---

## 1. Kurallar (ihlal etme)

- **Sır (secret) paylaşma:** parola, API anahtarı, token, kart bilgisi → **ne sohbete ne
  repo'ya**. `.env` git'e girmez (zaten `.gitignore`'da; teyit et). Owner'dan gelen
  kimlik bilgilerini yalnızca sunucu dosya sistemine/ortam değişkenine yaz.
- **Raftabul'a dokunma.** Bu ayrı bir sunucu; Raftabul verisi/entegrasyonu buraya gelmez.
  `reset-commerce` / demo-seed gibi komutlar **Raftabul'da tehlikeliydi** — burada DB
  boş/yeni olduğundan platform seed'i normaldir, ama Raftabul'a özgü hiçbir canlı anahtar
  girme.
- **Yıkıcı işlemde önce bak, sonra sor.** `rm -rf`, `DROP`, `docker volume rm`, disk
  formatı → hedefi doğrula, gerekiyorsa owner'a sor.
- **Kod düzeltmesi gerekirse `main`'e doğrudan yazma.** Ayrı bir dal aç
  (`fix/turuncukasa-deploy-<konu>`), commit + push et, §9'a not düş. Küçük olmayan hiçbir
  şeyi sessizce main'e bindirme (masaüstü + başka oturumlar aynı repo'da çalışıyor).
- **`make check` yeşil olmadan "tamam" deme** (CLAUDE.md non-negotiable).
- Bu iş emrinde owner-onayı gereken adımlar **⚠️ OWNER** ile işaretli — onları sen yapma,
  owner'a talimatı ver.

---

## 2. Ön-uçuş (önce oku, sonra dokun)

1. Repo'yu klonla (owner git erişimini —deploy key/PAT— sunucuda kurar; **sen credential
   isteme/yazma, owner ayarlar**). Öneri konum: `/opt/turuncukasa`.
2. Şunları oku: `CLAUDE.md`, `docs/001_Architecture.md`, `docs/modules.md`, `Makefile`,
   `docker/` altındaki her şey, `docker-compose*.yml` (varsa), `storefront/README.md` ve
   `storefront/package.json`, `.env.example`.
3. **Deploy metodunu belirle.** Proje **Docker-first** (CLAUDE.md: "Everything runs in
   Docker"; `make install`/`make check`/`make shell`). **Birincil yol: Docker Compose.**
   - `docker/` ve compose dosyalarını incele: prod'a uygun servisleri (app/php-fpm,
     nginx, postgres, redis, queue/horizon, scheduler, meilisearch/arama, node/storefront)
     ayıkla; **dev-only** servisleri (mailpit, vite watch vb.) prod'da çalıştırma.
   - Kalıcı **volume**'lar: postgres data, redis (opsiyonel), uploaded media/storage.
   - Eğer projenin prod standardı bare-metal ise (owner "Raftabul prod bare-metal koşuyor"
     derse) o yolu izle; ama teyit gelene kadar Docker Compose ile ilerle.
4. Bir bulgu/karar çıktıysa §9'a yaz.

---

## 3. FAZ 1a — OS sıkılaştırma

Kök ile kalıcı çalışma; ilk iş güvenlik. Her komutu çalıştırmadan önce doğrula.

- [ ] `apt update && apt upgrade -y`; gerekiyorsa reboot.
- [ ] **Non-root sudo kullanıcı** (owner ile isim belirle); bundan sonra onunla çalış.
- [ ] **SSH sıkılaştırma:** yalnız key (parola auth kapalı), root login kapalı. ⚠️ Owner'ın
      SSH public key'i `authorized_keys`'te olduğundan EMİN OL, yoksa kendini kilitlersin.
- [ ] **UFW firewall:** `allow OpenSSH` (veya özel SSH portu), `allow 80`, `allow 443`,
      gerisi `deny`. `ufw enable`.
- [ ] **fail2ban** kur (sshd jail).
- [ ] **unattended-upgrades** (yalnız güvenlik) aç.
- [ ] Zaman dilimi `Europe/Istanbul`; `timedatectl` ile NTP açık.
- [ ] Küçük **swap** (örn. 4 GB) — 31 GiB RAM bol ama emniyet.
- [ ] Disk/mount kontrolü (`df -h`), 382 GB'ın root'ta ya da uygun mount'ta olduğunu gör.

## 3b. FAZ 1b — Stack kurulumu (Docker yolu)

- [ ] **Docker Engine + compose plugin** (resmi Docker apt reposu). `docker --version`,
      `docker compose version`.
- [ ] Kullanıcıyı `docker` grubuna ekle (yeniden login).
- [ ] Repo `/opt/turuncukasa`'da; `.env`'i `.env.example`'dan üret (§4).
- [ ] Görsel/medya ve postgres için **kalıcı volume** planı.
- [ ] Reverse proxy + TLS: **Nginx** (origin) + **Cloudflare Origin CA** sertifikası
      (veya Let's Encrypt). Cloudflare SSL modu **Full (strict)** olmalı. ⚠️ Cloudflare
      tarafı ayarları (SSL modu, DNS) **OWNER** yapar — sen origin nginx + sertifikayı
      hazırla, DNS için §7'ye bak.

---

## 4. Ortam (.env) — turuncukasa'ya özgü, Raftabul'dan ayrı

`.env.example`'ı kopyala, şunları turuncukasa'ya göre ayarla. **Smoke-test için dış
entegrasyonlar KAPALI/boş** (Faz 3'te açılır):

- `APP_NAME`, `APP_ENV=production`, `APP_DEBUG=false`
- `APP_URL` → smoke aşamasında `https://srv.turuncukasa.com` (veya geçici); public
  `turuncukasa.com`'a Faz 3 sonunda geçilir.
- `APP_KEY` → `php artisan key:generate` (Docker içinde `make shell` / `docker compose exec`).
- **DB** → turuncukasa'nın kendi Postgres'i (ayrı isim/kullanıcı/parola). Raftabul DB'siyle
  hiçbir bağ yok.
- **Redis**, **queue**, **cache** → bu instance'a ait.
- **Mail** → smoke'ta `log` driver yeterli (gerçek SES/SMTP Faz 3).
- **KAPALI tut:** PayTR anahtarları (turuncukasa PayTR kullanmayacak — Halkbank, Faz 3),
  Meta/GA/GTM canlı ID'leri, GMC — hepsi boş/disabled. Smoke testte para/analitik yok.
- `.env`'in git'e girmediğini teyit et.

---

## 5. FAZ 2 — Smoke-deploy (kodu olduğu gibi ayağa kaldır)

Amaç: **turuncukasa şekli DEĞİL**, "platform bu sunucuda koşuyor mu?" doğrulaması.

- [ ] `make install` (veya compose up + bağımlılıklar) — projenin kendi tooling'ini kullan.
- [ ] **Migration:** `php artisan migrate` (Docker içinde). Tablolar oluşmalı.
- [ ] **Platform seed:** Localization/Currency/Language/roller/permission gibi zorunlu
      referans veriyi seed'le (CLAUDE.md: `Language::default()`/`Currency::default()`
      seed'siz patlar). **Raftabul iş verisi YOK** — yalnız platformun boot için gerekeni.
- [ ] `make permissions` (permission tablosu üretimi) gerekiyorsa.
- [ ] **Storefront (Next.js, `storefront/`):** `npm ci && npm run build`; prod'da çalıştır
      (kendi container'ı veya `npm start` + process manager). Build hatasız geçmeli.
- [ ] **Queue worker + scheduler ÇALIŞMALI** — para-kritik (sipariş-süresi süpürme, payout,
      feed). Docker'da queue/scheduler servisleri ayakta mı doğrula; scheduler için cron ya
      da `schedule:work` servisi. (Raftabul'da scheduler hiç çalışmadığı için 11 iş sessizce
      atlanmıştı — burada ilk günden ayakta olsun.)
- [ ] **`make check` yeşil** (lint + analyse + test). Kırmızıysa: nedenini §9'a yaz; kod
      düzeltmesi gerekiyorsa §1 kuralınca ayrı dalda.
- [ ] **Duman testleri:**
  - Admin paneli (Filament) `srv.turuncukasa.com/admin` (veya route neyse) açılıyor mu?
  - Storefront ana sayfa render oluyor mu?
  - `php artisan about` / health — app boot temiz mi, queue bağlantısı, redis, db OK mi?
  - Log'larda fatal var mı (`storage/logs`, container log'ları)?

---

## 6. Yedekleme (prod'a çıkmadan şart — ama smoke'ta temeli at)

- [ ] Postgres için otomatik dump (günlük) planı; dump'ı kutu-DIŞINA (örn. object storage)
      atma stratejisini owner ile belirle. Smoke'ta en azından `pg_dump` cron'u kur ve bir
      kez elle çalıştırıp geri-yükleme testini not et.
- [ ] Medya/storage dizini yedek kapsamında mı, işaretle.

---

## 7. DNS / Cloudflare — ⚠️ OWNER (sen yalnız origin'i hazırla)

Public geçiş Faz 3 sonunda. Şimdilik referans:
- `turuncukasa.com` + `www` → Cloudflare **proxied** (turuncu bulut) → origin
  `136.144.209.137`. **Geçişi owner, adaptasyon+göç bitince yapar.**
- `srv.turuncukasa.com` → **DNS-only (gri bulut)**; SSH/yönetim Cloudflare proxy'sine
  takılmadan.
- Cloudflare **SSL: Full (strict)**; origin'de geçerli sertifika (Origin CA / Let's Encrypt).
- **Ödeme callback'i (Faz 3):** Halkbank sunucu-sunucu 3D callback POST'u WAF/Bot Fight
  Mode'a takılmasın — ilgili path allowlist + CSRF-exempt. (Raftabul'da PayTR callback'inde
  yaşandı.) Smoke'ta ödeme yok, sadece not.

---

## 8. Kesin sınırlar (bu iş emrinde YOK)

- ❌ Public DNS'i (turuncukasa.com) bu sunucuya yöneltmek
- ❌ Canlı ödeme / Halkbank entegrasyonu (Faz 3)
- ❌ single-vendor mode / tema değişikliği (Faz 3)
- ❌ Gerçek ürün/müşteri/sipariş/yorum verisi (Faz 4)
- ❌ Herhangi bir canlı üçüncü-parti anahtarı (Meta/GA/SES/GMC)

FAZ 2 duman testi geçince **DUR**, §9'a raporla, masaüstü oturumu Faz 3 iş emrini hazırlasın.

---

## 9. RAPOR (sunucudaki Claude buraya yazar, commit + push eder)

> Her anlamlı adımdan sonra buraya kısa not düş ve push et. Masaüstü oturumu buradan okuyor.

- [ ] Deploy metodu kararı (Docker Compose / bare-metal) + neden:
- [ ] OS sıkılaştırma durumu (SSH/UFW/fail2ban/upgrades):
- [ ] Stack kurulum durumu (docker, postgres, redis, nginx, node):
- [ ] `.env` hazır mı (secret'lar sunucuda, repo'da değil — teyit):
- [ ] migrate + seed sonucu:
- [ ] storefront build sonucu:
- [ ] queue + scheduler ayakta mı:
- [ ] `make check` sonucu (yeşil/kırmızı + varsa hata özeti):
- [ ] duman testi (admin + storefront + health):
- [ ] açık sorular / owner'dan gerekenler:
- [ ] Faz 3'e hazır mı (evet/hayır + gerekçe):
