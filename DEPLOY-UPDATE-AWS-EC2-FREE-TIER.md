# Panduan Deploy Update Nameshift di AWS EC2

Panduan ini ditujukan untuk memperbarui instalasi Nameshift yang sudah berjalan di satu instance EC2 Ubuntu dengan Nginx, PHP-FPM, MySQL/MariaDB atau RDS, dan Supervisor. Contoh memakai:

- direktori aplikasi: `/var/www/nameshift`
- branch produksi: `main`
- user deployment: `deploy`
- user Nginx/PHP-FPM: `www-data`
- PHP-FPM: `php8.2-fpm`

Sesuaikan nama user, direktori, branch, dan service dengan server yang digunakan.

## Ringkasan update ini

Update ini menambahkan kolom metadata registrar pada tabel `domains`, index status registrar, perubahan sinkronisasi Namecheap/Name.com/Z.com, serta build frontend baru. Setelah deployment:

1. seluruh migration harus berstatus `Ran`;
2. queue worker harus memuat source code baru;
3. aset Vite harus dibangun ulang;
4. lakukan sinkronisasi registrar untuk mengisi metadata domain lama.

## 1. Persiapan sebelum deployment

### Akses EC2

Gunakan AWS Systems Manager Session Manager jika sudah dikonfigurasi. Metode ini tidak membutuhkan inbound port SSH. Jika memakai SSH, batasi port `22` pada Security Group hanya ke IP administrator. AWS menjelaskan pilihan koneksi EC2 dan persyaratannya di [dokumentasi koneksi EC2](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/connect.html).

Masuk ke server dan direktori aplikasi:

```bash
sudo -iu deploy
cd /var/www/nameshift
```

### Pemeriksaan awal

```bash
git status --short
git branch --show-current
git rev-parse HEAD
php -v
node --version
composer --version
php artisan about
php artisan migrate:status
sudo supervisorctl status
curl -fsS https://nama-domain.example/up
```

Hentikan deployment apabila working tree tidak bersih, health check gagal, atau service penting sedang bermasalah. Catat commit aktif dari `git rev-parse HEAD` sebagai commit rollback.

### Backup database

Ambil snapshot RDS jika database menggunakan RDS. Untuk MySQL/MariaDB lokal, buat backup dengan kredensial dari file client MySQL yang permission-nya dibatasi, bukan dengan menaruh password pada command history:

```bash
sudo install -d -o deploy -g deploy /var/backups/nameshift
NAMESHIFT_BACKUP="/var/backups/nameshift/pre-deploy-$(date +%Y%m%d-%H%M%S).sql.gz"
mysqldump --defaults-extra-file=/home/deploy/.my.cnf \
  --single-transaction --routines --triggers nameshift \
  | gzip > "$NAMESHIFT_BACKUP"
gzip -t "$NAMESHIFT_BACKUP"
```

Pastikan backup dapat dibaca dan tersimpan di lokasi yang tidak ikut tertimpa oleh deployment.

## 2. Jalankan deployment

### Aktifkan maintenance mode

```bash
cd /var/www/nameshift
php artisan down --retry=60
```

Laravel tidak mengambil job baru ketika aplikasi berada dalam maintenance mode. Job yang sedang berjalan dibiarkan selesai.

### Ambil source code terbaru

```bash
git fetch --prune origin
git pull --ff-only origin main
```

Gunakan `--ff-only` agar deployment berhenti jika riwayat branch server menyimpang dari remote.

### Install dependency PHP

```bash
composer install \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction
```

Jangan menjalankan `composer update` di server produksi.

### Install dependency frontend dan build aset

```bash
npm ci --include=dev
npm run build
test -f public/build/manifest.json
```

Jangan memakai `npm ci --omit=dev` pada proyek ini. Flag `--include=dev` dipakai agar package `playwright` tetap terpasang walaupun server mempunyai konfigurasi npm production. Playwright saat ini berada di `devDependencies`, tetapi dibutuhkan saat runtime oleh adapter Z.com.

Jika `package-lock.json` mengubah versi Playwright, atau Chromium belum pernah dipasang pada instance:

```bash
sudo ./node_modules/.bin/playwright install-deps chromium
PLAYWRIGHT_BROWSERS_PATH=/var/www/nameshift/.playwright-browsers \
  ./node_modules/.bin/playwright install chromium
```

Nilai `PLAYWRIGHT_BROWSERS_PATH` pada `.env` harus sama dan direktori tersebut harus dapat dibaca oleh user Supervisor yang menjalankan queue worker.

### Jalankan migration

```bash
php artisan migrate --force
php artisan migrate:status
```

Untuk update ini, pastikan migration berikut berstatus `Ran`:

- `2026_08_28_083320_add_registrar_metadata_to_domains_table`
- `2026_08_28_084654_add_remote_status_index_to_domains_table`

Jangan menjalankan `migrate:fresh` atau menghapus database di production.

### Atur permission dan cache Laravel

```bash
sudo chown -R deploy:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache

php artisan optimize:clear
php artisan optimize
```

Laravel merekomendasikan cache konfigurasi, event, route, dan view saat deployment melalui `php artisan optimize`; lihat [Laravel 12 deployment](https://laravel.com/framework/docs/12.x/deployment).

### Muat ulang PHP dan queue worker

```bash
sudo systemctl reload php8.2-fpm
php artisan queue:restart
sudo supervisorctl status
```

`queue:restart` meminta worker keluar secara graceful setelah job aktif selesai. Supervisor harus otomatis menjalankan worker baru yang memuat source code terbaru. Laravel menjelaskan bahwa worker bersifat long-lived dan perlu direstart saat deployment di [dokumentasi queue Laravel 12](https://laravel.com/framework/docs/12.x/queues#queue-workers-and-deployment).

Jika status Supervisor tidak `RUNNING`, periksa log sebelum membuka maintenance mode.

### Buka aplikasi

```bash
php artisan up
curl -fsS https://nama-domain.example/up
```

Jika perintah deployment gagal setelah `artisan down`, perbaiki atau rollback terlebih dahulu. Jalankan `artisan up` hanya setelah aplikasi berada dalam kondisi konsisten.

## 3. Verifikasi setelah deployment

### Pemeriksaan server

```bash
php artisan about
php artisan migrate:status
php artisan schedule:list
sudo supervisorctl status
sudo systemctl status php8.2-fpm --no-pager
sudo systemctl status nginx --no-pager
tail -n 100 storage/logs/laravel.log
```

Pastikan:

- endpoint `/up` merespons sukses;
- tidak ada migration pending;
- semua worker Supervisor berstatus `RUNNING`;
- tidak ada exception baru pada `storage/logs/laravel.log`;
- halaman login dan halaman Domains dapat dibuka;
- sidebar collapsed untuk browser yang belum mempunyai preferensi;
- modal **Configure columns** menampilkan semua kolom;
- kolom **Status Registrar** menampilkan status registrar, bukan status inventory.

### Isi metadata domain lama

Klik **Synchronize all** dari aplikasi, atau sinkronkan akun registrar satu per satu. Mulai dengan akun/domain non-kritis. Pantau:

```bash
sudo supervisorctl status
tail -f storage/logs/laravel.log
```

Setelah sinkronisasi, periksa TLD, renewal price, created date, expired date, sisa hari, status registrar, lock, privacy, dan auto-renew. Nilai `—` berarti provider tidak memberikan nilai yang dapat dikenali.

Untuk Namecheap production, pastikan Elastic IP/public IPv4 instance tetap terdaftar pada allowlist API Namecheap.

## 4. Konfigurasi Supervisor yang disarankan

Worker harus memproses seluruh queue yang digunakan aplikasi:

- `registrar-browser`
- `registrar-mutations`
- `registrar-sync`
- `default`

Contoh `/etc/supervisor/conf.d/nameshift-worker.conf`:

```ini
[program:nameshift-worker]
process_name=%(program_name)s_%(process_num)02d
directory=/var/www/nameshift
command=/usr/bin/php /var/www/nameshift/artisan queue:work database --queue=registrar-browser,registrar-mutations,registrar-sync,default --sleep=2 --tries=50 --timeout=1800 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=deploy
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/nameshift/storage/logs/worker.log
stopwaitsecs=1900
```

Aktifkan perubahan Supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start 'nameshift-worker:*'
```

Project memakai `DB_QUEUE_RETRY_AFTER=2100`, sedangkan job sinkronisasi terpanjang memiliki timeout 1800 detik. Timeout worker/job harus tetap lebih pendek daripada `retry_after` untuk mencegah sebuah job diproses dua kali. `stopwaitsecs` juga harus lebih panjang daripada job terpanjang. Rujuk [queue timeout Laravel](https://laravel.com/framework/docs/12.x/queues#job-expirations-and-timeouts) dan [konfigurasi Supervisor](https://laravel.com/framework/docs/12.x/queues#supervisor-configuration).

## 5. Scheduler

Pastikan cron menjalankan scheduler setiap menit sebagai user deployment:

```cron
* * * * * cd /var/www/nameshift && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Periksa dengan:

```bash
crontab -l
php artisan schedule:list
```

Nameshift memakai scheduler untuk membersihkan batch queue dan failed jobs. Laravel hanya membutuhkan satu cron `schedule:run` setiap menit; lihat [Laravel 12 task scheduling](https://laravel.com/framework/docs/12.x/scheduling#running-the-scheduler).

## 6. Checklist `.env` production

Jangan mengganti `.env` dengan `.env.example` saat deployment. Pertahankan `APP_KEY`; menggantinya membuat kredensial registrar yang sudah terenkripsi tidak dapat dibaca.

Nilai minimum yang perlu diperiksa:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://nama-domain.example
APP_TIMEZONE=Asia/Jakarta
SESSION_SECURE_COOKIE=true

DB_CONNECTION=mysql
QUEUE_CONNECTION=database
CACHE_STORE=database
DB_QUEUE_RETRY_AFTER=2100

# Aktifkan hanya jika akun Z.com digunakan.
ZCOM_AUTOMATION_ENABLED=true
ZCOM_NODE_BINARY=node
ZCOM_HEADLESS=true
PLAYWRIGHT_BROWSERS_PATH=/var/www/nameshift/.playwright-browsers
ZCOM_DIAGNOSTICS_PATH=/var/www/nameshift/storage/app/private/zcom-diagnostics
```

Setelah mengubah `.env`, jalankan:

```bash
php artisan optimize:clear
php artisan optimize
php artisan queue:restart
```

## 7. Rollback

Lakukan rollback bila health check gagal, aplikasi menghasilkan exception berulang, atau worker tidak dapat berjalan.

1. Aktifkan maintenance mode.
2. Deploy kembali commit/tag sebelumnya yang sudah dicatat pada pemeriksaan awal.
3. Jalankan kembali `composer install`, `npm ci --include=dev`, dan `npm run build` untuk commit tersebut.
4. Jalankan `php artisan optimize:clear`, lalu `php artisan optimize`.
5. Reload PHP-FPM dan jalankan `php artisan queue:restart`.
6. Verifikasi `/up`, log, database, dan worker sebelum `php artisan up`.

Migration update ini hanya menambahkan kolom dan index. Source code lama dapat mengabaikan keduanya, sehingga rollback kode biasanya tidak perlu menjalankan `migrate:rollback`. Jangan rollback migration production tanpa meninjau data yang telah ditulis dan memastikan backup siap direstore.

Jika migration gagal atau telah mengubah data secara tidak terduga, pertahankan maintenance mode dan restore database dari snapshot/backup yang sudah diverifikasi.

## 8. Troubleshooting cepat

### `Unable to locate file in Vite manifest`

```bash
npm ci --include=dev
npm run build
php artisan optimize:clear
```

### Kolom domain baru tidak ada

```bash
php artisan migrate:status
php artisan migrate --force
```

### Status/metadata lama masih kosong

Jalankan sinkronisasi registrar setelah memastikan worker queue `RUNNING`.

### Job terus `PENDING`

```bash
sudo supervisorctl status
php artisan queue:failed
tail -n 200 storage/logs/worker.log
tail -n 200 storage/logs/laravel.log
```

### Z.com gagal membuka browser

```bash
PLAYWRIGHT_BROWSERS_PATH=/var/www/nameshift/.playwright-browsers \
  ./node_modules/.bin/playwright install chromium
```

Pastikan Chromium beserta library sistemnya terpasang, path dapat dibaca user worker, dan `ZCOM_AUTOMATION_ENABLED=true`.

### HTTP 502 dari Nginx

```bash
sudo systemctl status php8.2-fpm --no-pager
sudo journalctl -u php8.2-fpm -n 100 --no-pager
sudo tail -n 100 /var/log/nginx/error.log
```

Jangan membuka aplikasi kembali sebelum penyebab error teridentifikasi.
