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

> **⚠️ STACK SÜRÜMLERİ — repo'dan doğrulandı, sabittir:**
> - **Veritabanı = PostgreSQL** (`DB_CONNECTION=pgsql`, config'de açıkça "Chosen over MySQL").
>   **MySQL/MariaDB KULLANMA** — platform pgsql'e özgü şeyler kullanıyor (partial unique
>   index, pgsql operatörleri, full-text search); MariaDB ile migration'lar patlar,
>   `make check` kırmızı olur. (Bir başka checklist "MariaDB" diyorsa görmezden gel.)
> - **PHP = `^8.3`** (composer.json). 8.3 veya 8.4 olur.
> - **Docker mı bare-metal mı?** Bu bölüm Docker varsayar (CLAUDE.md: "Everything runs in
>   Docker"; PHP/Postgres/Redis/Node host'a DEĞİL, container'dan gelir). Eğer Raftabul
>   prod'un standardı bare-metal ise (owner teyit eder) o yolu izle: o zaman Nginx +
>   PHP-8.3-FPM + PostgreSQL + Redis + Node LTS + Supervisor(queue) + cron(scheduler)
>   **host'a** kurulur. Her iki yolda da yukarıdaki DB ve PHP kilidi geçerli.

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

## 6. Yedekleme & monitoring (prod'a çıkmadan şart — ama smoke'ta temeli at)

**Yedekleme:**
- [ ] Postgres için otomatik dump (günlük) planı; dump'ı kutu-DIŞINA (örn. object storage)
      atma stratejisini owner ile belirle. Smoke'ta en azından `pg_dump` cron'u kur ve bir
      kez elle çalıştırıp geri-yükleme testini not et.
- [ ] Medya/storage dizini yedek kapsamında mı, işaretle.

**Monitoring (temel — smoke'ta minimum, go-live'da genişlet):**
- [ ] Disk/RAM/CPU izleme (en azından disk-dolma uyarısı) — kurulacak araç owner ile.
- [ ] Uygulama hata log'u takibi (`storage/logs`, queue failed jobs, scheduler çıktısı).
- [ ] Uptime kontrolü (Cloudflare veya harici) — go-live'da; smoke'ta not.

## 6b. Performans & log rotation — ⏭️ GO-LIVE ÖNCESİ (Faz 2.5), smoke'u bloke etmez

Duman testi geçtikten SONRA, public'e çıkmadan önce yapılacak sertleştirme. Docker
yolunda çoğu image/config içindedir; bare-metal'de elle:
- [ ] **PHP OPcache** açık + prod ayarları (validate_timestamps=0 vb.).
- [ ] **PHP-FPM tuning** (pm=dynamic/static, worker sayısı — 8 vCPU / 31 GiB'a göre).
- [ ] **Redis** cache/session/queue backend olarak devrede (config teyidi).
- [ ] **Nginx**: gzip/**Brotli**, statik cache header'ları, HTTP/2 (HTTP/3'ü Cloudflare edge verir).
- [ ] **Next.js production build** + process manager (Docker servisi ya da pm2/systemd).
- [ ] **Laravel cache**: `config:cache`, `route:cache`, `event:cache`, `view:cache` (prod).
- [ ] **logrotate** — nginx + Laravel + container log'ları şişmesin.

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

- [x] Deploy metodu kararı (Docker Compose / bare-metal) + neden: **bare-metal** (owner
      onayı, 2026-09-16). Raftabul prod bare-metal koşuyor (HANDOFF.md: PHP 8.3 +
      PostgreSQL + Redis + Meilisearch + systemd horizon/scheduler/storefront, Docker yok);
      repo'daki `docker-compose.yml` yalnız DEV yığını (Xdebug, mailpit, OpenSearch —
      storefront ve Meilisearch yok) ve prod compose dosyası yok. Docker yine de kurulacak:
      `make check` `docker compose exec app` üzerinden koşuyor.
- [x] OS sıkılaştırma durumu (SSH/UFW/fail2ban/upgrades): **tamam (2026-09-16).**
  - Çalışan kullanıcı: `tkdeveloper` (sudo grubu). Owner'ın ed25519 key'i
    `authorized_keys`'te (SHA256:+Wd7BV5O…pVc), owner 23422'den key girişini test etti.
  - **SSH portu 23422** (hosting böyle teslim etti; 22 kapalı — "Connection refused"
    bundan). `sshd_config.d/00-hardening.conf`: `PasswordAuthentication no`,
    `PermitRootLogin no`, `AllowUsers tkdeveloper`, `MaxAuthTries 4`, `X11Forwarding no`.
    Cloud-init drop-in'lerindeki `PasswordAuthentication yes` de `no` yapıldı (sshd ilk
    değeri alır). `sshd -T` ile teyit; localhost'tan root + parola denemesi reddedildi.
    Yedekler: `/root/sshd_config*.bak-2026-09-16`.
  - **UFW aktif:** default deny in / allow out; `23422/tcp LIMIT`, `80`, `443` (v4+v6).
  - **fail2ban:** sshd jail, port 23422, `banaction=ufw`, bantime 1h / 5 deneme / 10 dk.
    Ubuntu 24.04'te birim `ssh.service` olduğundan `journalmatch` düzeltildi (varsayılan
    `sshd.service` hiçbir şey yakalamazdı); filtre gerçek journal satırlarıyla doğrulandı.
  - **unattended-upgrades:** açık, günlük. Hosting imajında `-updates`, **`-proposed`** ve
    `-backports` otomatik kuruluma açıktı — yoruma alındı; yalnız `noble` +
    `noble-security` (+ESM). Yedek: `/root/50unattended-upgrades.bak-2026-09-16`.
  - `apt upgrade` yapıldı; reboot gerekmedi (`/var/run/reboot-required` yok).
  - Saat dilimi **Europe/Istanbul**, NTP senkron. **4 GB swap** (`/swapfile`, fstab'da,
    `vm.swappiness=10`). Disk: `/dev/vda1` 387 G ext4 root'ta, %3 dolu.
  - ⚠️ **KURULUM SONRASI KALDIR:** `/etc/sudoers.d/90-tkdeveloper-setup`
    (`tkdeveloper ALL=(ALL) NOPASSWD:ALL`) — Faz 1+2 için geçici verildi. Faz 2 bitince
    owner: `sudo rm /etc/sudoers.d/90-tkdeveloper-setup` (tkdeveloper'ın parolası
    ayarlı olmalı, yoksa sudo tamamen kaybolur).
- [x] Stack kurulum durumu (docker, postgres, redis, nginx, node): **tamam (bare-metal, Raftabul prod şekli).**

  | Bileşen | Sürüm | Not |
  |---|---|---|
  | PHP-FPM + CLI | 8.3.6 (noble) | bcmath, intl, mbstring, pgsql, redis, gd, zip, exif, pcntl, sockets, opcache; soket `/run/php/php8.3-fpm.sock` |
  | Composer | 2.10.3 | installer imzası doğrulandı |
  | PostgreSQL | **17.11** (PGDG — CI ile aynı major) | yalnız `localhost`; rol+DB `turuncukasa`, `--locale=C` (dev compose ile aynı) |
  | Redis | 7.0.15 | `127.0.0.1`/`::1`, `requirepass`, `noeviction`, AOF, maxmemory 2gb (`/etc/redis/conf.d-turuncukasa.conf`) |
  | Meilisearch | v1.53.2 | sha256 GitHub digest'iyle doğrulandı; systemd `meilisearch`, `127.0.0.1:7700`, master key `/etc/meilisearch.env` (600); index `tk_products` |
  | nginx | 1.24.0 | vhost `srv.turuncukasa.com.conf` + `snippets/turuncukasa-laravel{,-prefixes}.conf`; bilinmeyen Host → 444 / TLS handshake red |
  | Node | 22.23.2 (NodeSource) | npm 10.9.8 |
  | Docker | 29.8.1 + compose v5.5.1 | **yalnız `make check` için** (prod trafiği Docker'dan geçmiyor) |
  | certbot | apt | timer aktif; **henüz sertifika yok** (aşağıda) |

  - **systemd servisleri** (hepsi `www-data`, enabled, log `/var/log/turuncukasa/*.log`):
    `turuncukasa-horizon` (ExecStop = `horizon:terminate`), `turuncukasa-scheduler`
    (`schedule:work`), `turuncukasa-storefront` (`next start` `127.0.0.1:3000`,
    `INTERNAL_API_URL=http://127.0.0.1:8080`), `meilisearch`.
  - **nginx şekli (ADR-058, docs/storefront-deploy.md):** 443'te Laravel allow-list'i
    (`/api/ /sanctum/ /admin /seller /livewire/ /filament/ /feed/ /resend/ =/login =/up
    /storage/ /css|js|fonts/filament/`) → PHP-FPM; geri kalan her şey → Next.
    `127.0.0.1:8080` yalnız-loopback Laravel dinleyicisi (Next SSR için). 80 → 301 https.
    Smoke aşamasında `X-Robots-Tag: noindex, nofollow`.
  - **⚠️ TLS: GEÇİCİ SELF-SIGNED.** `srv.turuncukasa.com` **public DNS'te yok**
    (1.1.1.1 → NXDOMAIN; yerelde yalnız hostname olduğu için çözülüyordu), Let's Encrypt
    HTTP-01 bu yüzden reddetti. Ayrıca `turuncukasa.com`'un NS'i hâlâ
    **`ns1729/ns1730.tekrom.com`** — Cloudflare'e taşınmamış; apex `159.69.171.75`
    (mevcut site) gösteriyor, dokunulmadı. nginx `/etc/ssl/turuncukasa/current.{crt,key}`
    symlink'lerini okuyor (şu an 30 günlük self-signed'a bakıyor). DNS kaydı gelince:
    `sudo certbot certonly --webroot -w /var/www/letsencrypt -d srv.turuncukasa.com
    --agree-tos --register-unsafely-without-email -n`, symlink'leri
    `/etc/letsencrypt/live/srv.turuncukasa.com/{fullchain,privkey}.pem`'e çevir, `nginx -s reload`.
  - **Docker + UFW tuzağı:** Docker'ın yayınladığı portlar UFW'yi **atlar**; dev compose
    5432/6379/9200/8080/9000/8025'i `0.0.0.0`'da yayınlıyor. Bu yüzden `make check` ayrı
    bir klonda (`~/mos-ci`) ve `ports: !reset []` override'ıyla koşuyor (override
    `.git/info/exclude`'da; repo'ya girmedi). O klonun dev container'ı root çalışır ve
    prod ağacına (`/opt/turuncukasa/storage`) hiç dokunmaz.
    `/etc/docker/daemon.json` log limiti (10m×3) yazıldı.
- [x] `.env` hazır mı (secret'lar sunucuda, repo'da değil — teyit): **evet.**
  `/opt/turuncukasa/.env` `640 tkdeveloper:www-data`, `git check-ignore` ile ignored,
  `git status` temiz. Parolalar rastgele üretildi, yalnız `/root/turuncukasa-secrets/`
  (700/600) + `.env` + `/etc/meilisearch.env`'de; sohbete/repo'ya basılmadı.
  Ayarlar: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://srv.turuncukasa.com`,
  kendi DB/Redis/Meili (prefix'ler `turuncukasa_`, `tk_cache`, `horizon:tk:`, `tk_`),
  `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS`/`SESSION_DOMAIN` = srv host,
  `FILESYSTEM_DISK=public` + `MEDIA_PUBLIC_DISK=public`/`MEDIA_PRIVATE_DISK=local`
  (config varsayılanı `s3` — bu kutuda S3 yok), `TRUSTED_PROXIES=127.0.0.1,::1`,
  `MAIL_MAILER=log`. **Kapalı/boş:** PayTR (anahtar yok, test_mode), Meta CAPI/Pixel,
  GTM, GMC token, Resend, Sentry, S3. `FEED_GOOGLE_STOREFRONT_URL` açıkça srv host'a
  çekildi (config varsayılanı `https://raftabul.com`!). Storefront build env'i
  `storefront/.env.production.local` (ignored): `NEXT_PUBLIC_SITE_URL` srv, GTM/Pixel/WhatsApp boş.
- [x] migrate + seed sonucu: **temiz.** `migrate --force` → pending 0 (87 tablo);
  `db:seed` (yalnız yapısal: Localization, Settings, OrganizationPlan, TaxRate,
  CommissionRule, CargoCompany, RolePermission) + `marketplace:sync-permissions`
  ("already exist"). Sayımlar: languages 2, currencies 4, roles **9**, permissions 261,
  tax_rates 4, cargo_companies 8; `TurkeyGeoSeeder` → 81/973/73.300. **users 0,
  products 0** — admin kullanıcı YOK (tasarım gereği interaktif; aşağıda owner adımı).
  Hepsi `sudo -u www-data` ile (root-log tuzağı). `storage` + `bootstrap/cache`:
  www-data, setgid 2775 + default ACL (docs/storefront-deploy.md). `filament:assets`
  ve `storage:link` yapıldı. Meili: `search:sync-settings` + `scout:import`.
- [x] storefront build sonucu: **yeşil.** `npm ci` + `next build` (Next 15.5.22) hatasız;
  `npm run typecheck` (tsc) exit 0.
- [x] queue + scheduler ayakta mı: **evet, iş geçerek doğrulandı** (yalnız `is-active` değil).
  - Horizon: `horizon:status` → running; kuyruğa atılan `catalog:refresh-sellability`
    worker'da `DONE` (48 ms), `failed_jobs` = 0. (İlk iki deneme test artefaktıydı —
    tinker `eval` closure'ı serileştirilemez / `inspire` bu app'te yok — ikisi de flush'landı.)
  - Scheduler: log'da her dakika `expire-awaiting-payment` + `expire-pending-reservations`
    `DONE`; `schedule:list` 19 görev (sweep-transit-deliveries, create-due-payouts,
    feed build'leri dahil). `debugbar:clear` yalnız `local` ortamda — `--no-dev` ile sorun değil.
- [ ] `make check` sonucu (yeşil/kırmızı + varsa hata özeti): **main'de KIRMIZI (Docker'a özgü, kod değil) → düzeltme dalı `fix/turuncukasa-deploy-make-check` (3a90a2b), son koşu SÜRÜYOR.**
  - Koşu yeri: `~/mos-ci` (ayrı klon, dev compose, port yayını yok; CI gibi `.env` = `.env.testing`).
  - Pint **PASS** (1391 dosya), PHPStan **[OK] No errors** — her koşuda.
  - Test aşaması iki ORTAM nedeniyle kırmızı:
    1. **Bellek:** `docker/php/php.ini` `memory_limit=512M`; Architecture paketi (app/ üzerinde
       php-parser) bunu aşıp **tüm koşuyu fatal ile düşürüyor** (özet satırı bile yazılmıyor).
       CI'da `setup-php` → `memory_limit=-1`.
    2. **APP_ENV:** Dockerfile `dev` hedefi `ENV APP_ENV=local` koyuyor; `phpunit.xml`'deki
       `<env name="APP_ENV" value="testing"/>` force'suz olduğu için ezmiyor → suite `local`
       ortamda koşuyor: Livewire test makroları kaydolmuyor (`assertSeeLivewire does not
       exist`), `fillForm` verisi boş kalıyor → SellerRegistrationTest, SellerOnboardingReflowTest,
       TaxRateTest vb. kırmızı. CI'da APP_ENV tanımlı olmadığı için yeşil.
  - **Kanıt:** CI koşullarıyla (`-e APP_ENV=testing`, 2G, Xdebug off) tam paket
    **1834 passed, 25 skipped, 0 failed** (1443 s). Skip'ler: container'dan Postgres/Meili
    entegrasyon testlerine erişim yok (testler tasarım gereği skip eder).
  - **Düzeltme (yalnız `phpunit.xml`):** `APP_ENV` hem `<env force>` hem `<server force>`
    (Laravel önce `$_SERVER`'ı okur), `<ini name="memory_limit" value="-1"/>`. CI'ı
    etkilemez. **Main'e bindirilmedi — PR/merge owner/masaüstü kararı:**
    https://github.com/abdullahcetin07/marketplace/pull/new/fix/turuncukasa-deploy-make-check
  - Ayrıca not: `make install` `.env.example`'ı kopyalar; oradaki boş `RESEND_API_KEY=`
    yüzünden `MailTransportTest` dev kurulumunda kırmızı olur (Laravel `.env`'i `putenv`'e
    tercih eder). CI `.env.testing` kullandığı için görünmüyor. Düzeltilmedi, not edildi.
- [x] duman testi (admin + storefront + health): **geçti** (`curl --resolve … -k`, TLS self-signed olduğu için):

  | Yol | Sonuç |
  |---|---|
  | `/` `/urunler` `/sepet` | 200, `x-powered-by: Next.js` |
  | `/admin` | 302 → `/admin/login` (200) ; `/seller/login` 200 |
  | `/up` | 200 |
  | `/api/v1/health` | `{"status":"ok","search":"up"}` (index oluşmadan önce `down`'du — beklenen) |
  | `/api/v1/products` | 200 (boş katalog) |
  | `/robots.txt` `/sitemap.xml` | 200 (Next'ten) |
  | `/css/filament/filament/app.css` | 200 |
  | `/feed/google-merchant.xml` | 404 — **beklenen**: feed henüz üretilmedi, kod boş feed yerine bilerek 404 verir |
  | `http://srv…/` | 301 → https ; çıplak IP / bilinmeyen Host → bağlantı kapatılır (444) |

  `storage/logs`: fatal yok (yalnız kurulum sırasındaki kendi `key:generate`/`tinker`
  izin denemelerim — anlaşıldı, zararsız). Başlık hâlâ **"Raftabul — Dermokozmetik…"**:
  kod olduğu gibi deploy edildi, marka/tema Faz 3.
- [x] **Yedekleme & monitoring temeli (§6):**
  - `turuncukasa-backup.timer` her gün 03:40: `pg_dump -Fc` + `storage/app` tar + `.env`
    kopyası → `/var/backups/turuncukasa` (root 750/600), 14 gün saklama.
    Elle bir kez çalıştırıldı (3 MB dump) ve **geri yükleme test edildi**: geçici
    `tk_restore_test`'e `pg_restore` → 87 tablo / 261 izin / 73.300 mahalle birebir; geçici DB silindi.
    **⚠️ Kutu-DIŞI kopya YOK** — hedef (object storage vb.) owner kararı.
  - `turuncukasa-diskcheck.timer` 15 dk'da bir, ≥%85 → journal/syslog `crit`
    (`turuncukasa-disk` etiketi). Bildirim kanalı (mail/Telegram/harici) owner kararı.
  - logrotate: `/var/log/turuncukasa/*.log` + `nginx/turuncukasa*.log` günlük, 14 döngü.
    Laravel daily log `LOG_DAILY_DAYS=30`. Uptime kontrolü: go-live'da.
- [ ] açık sorular / owner'dan gerekenler:
  1. **⚠️ OWNER — sudo:** Faz 2 bitti; `sudo rm /etc/sudoers.d/90-tkdeveloper-setup`
     (önce `sudo passwd tkdeveloper` ile parola ayarla, yoksa sudo tamamen kaybolur).
  2. **⚠️ OWNER — DNS:** `srv.turuncukasa.com` A `136.144.209.137` (+ AAAA
     `2a01:7c8:d001:2a1:5054:ff:fe79:be46`) — **DNS-only**. Şu an kayıt yok ve alan adı
     henüz Cloudflare'de değil (NS tekrom). Kayıt gelince gerçek sertifika (komut yukarıda).
  3. **⚠️ OWNER — ilk admin:** `cd /opt/turuncukasa && sudo -u www-data php artisan
     marketplace:create-admin --super` (interaktif; parola sohbete yazılmaz).
  4. **Yedek kutu-dışı hedefi** ve **uyarı kanalı** (disk/hata/uptime) — owner seçimi.
  5. **Cloudflare önüne geçince (Faz 3):** `TRUSTED_PROXIES` Cloudflare IP aralıklarına
     çekilmeli ve nginx'e `real_ip_header CF-Connecting-IP` eklenmeli; UFW 80/443'ü
     yalnız CF aralıklarına daraltmak düşünülmeli. Sertifika: Origin CA veya LE.
  6. **Faz 2.5 (§6b) kalanlar:** OPcache prod ayarı (`validate_timestamps=0` → deploy'da
     FPM reload şart), FPM pool tuning, gzip/brotli + statik cache header, Laravel
     `optimize` (config/route/event/view cache) — smoke'ta bilerek kapalı bırakıldı.
     Docker daemon log limiti bir sonraki `systemctl restart docker`'da devreye girer.
  7. Deploy notu: artisan **her zaman** `sudo -u www-data`; `.env`'i www-data yazamaz
     (bilerek) — `key:generate` gibi `.env` yazan komutlar `tkdeveloper` olarak.
- [ ] Faz 3'e hazır mı (evet/hayır + gerekçe): _FAZ3_PENDING_
