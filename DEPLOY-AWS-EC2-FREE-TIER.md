# Deploy Nameshift ke AWS EC2 Free Tier

Panduan ini menggunakan arsitektur hemat biaya untuk satu server:

- AWS EC2 dengan Ubuntu Server 24.04 LTS.
- Nginx dan PHP 8.5 FPM.
- MySQL, aplikasi, queue worker, dan scheduler berjalan pada EC2 yang sama.
- Supervisor menjaga queue worker tetap aktif.
- Certbot menyediakan HTTPS langsung untuk public IPv4 tanpa domain.

Panduan ini ditulis untuk project Laravel 12 + React/Inertia di repository ini. Jalankan perintah server sebagai user `ubuntu`, kecuali jika perintah menggunakan `sudo`.

> **Penting tentang Free Tier:** program AWS untuk akun baru sekarang menggunakan kredit Free Tier dan batas waktu yang dapat berbeda berdasarkan jenis akun. Periksa menu **Billing and Cost Management → Credits/Free Tier** sebelum membuat resource. Public IPv4 dan Elastic IP juga dapat dikenakan biaya. Buat budget alert sebelum deployment.

## 1. Siapkan informasi deployment

Tentukan nilai berikut terlebih dahulu:

| Placeholder | Contoh | Keterangan |
| --- | --- | --- |
| `EMAIL_ANDA` | `admin@example.com` | Email untuk Certbot dan notifikasi. |
| `REPOSITORY_URL` | `https://github.com/organisasi/repo.git` | URL Git repository project. |
| `DB_PASSWORD_KUAT` | password acak minimal 24 karakter | Jangan gunakan contoh ini sebagai password asli. |
| `IP_ANDA` | `203.0.113.10/32` | Public IP komputer yang diperbolehkan melakukan SSH. |
| `PUBLIC_IP_EC2` | `198.51.100.20` | Public IPv4 atau Elastic IP milik EC2. |

Lokasi aplikasi dalam panduan ini adalah:

```text
/var/www/nameshift
```

## 2. Aktifkan kontrol biaya AWS

1. Masuk ke AWS Console.
2. Buka **Billing and Cost Management**.
3. Aktifkan **Free Tier usage alerts** jika tersedia.
4. Buka **Budgets → Create budget**.
5. Buat **Cost budget** bulanan, misalnya USD 1 atau USD 5.
6. Tambahkan notifikasi email pada 50%, 80%, dan 100% dari budget.
7. Periksa halaman **Credits** dan catat tanggal kedaluwarsa kredit.

Budget hanya mengirim peringatan; budget tidak otomatis mematikan EC2.

## 3. Buat EC2 instance

1. Buka **EC2 → Instances → Launch instances**.
2. Name: `nameshift-production`.
3. AMI: **Ubuntu Server 24.04 LTS**, 64-bit x86.
4. Instance type: pilih instance yang ditandai **Free tier eligible** pada akun Anda. Untuk aplikasi kecil biasanya kelas micro cukup, tetapi pilihan yang memenuhi syarat mengikuti program akun Anda.
5. Buat key pair baru dengan format `.pem`, lalu simpan dengan aman. File ini tidak dapat diunduh ulang.
6. Network settings:
   - Auto-assign public IP: **Enable**.
   - Security group: buat `nameshift-web-sg`.
7. Tambahkan inbound rules:

| Type | Port | Source |
| --- | ---: | --- |
| SSH | 22 | `IP_ANDA`, jangan `0.0.0.0/0` |
| HTTP | 80 | `0.0.0.0/0` dan `::/0` |
| HTTPS | 443 | `0.0.0.0/0` dan `::/0` |

Jangan membuka port MySQL `3306` ke internet.

8. Storage: gunakan volume gp3 secukupnya, misalnya 16–20 GiB. Pastikan ukurannya masih sesuai cakupan kredit/Free Tier akun.
9. Klik **Launch instance**.

## 4. Pilih strategi IP publik

### Opsi A — Elastic IP (disarankan untuk Nameshift)

Namecheap mengharuskan client IPv4 di-allowlist. Public IP EC2 biasa dapat berubah setelah instance dihentikan dan dinyalakan kembali. Elastic IP memberikan alamat tetap.

1. Buka **EC2 → Elastic IP addresses**.
2. Klik **Allocate Elastic IP address**.
3. Pilih Elastic IP tersebut, lalu **Actions → Associate Elastic IP address**.
4. Hubungkan ke instance `nameshift-production`.
5. Catat alamatnya untuk DNS dan konfigurasi Namecheap.

AWS mengenakan biaya untuk public IPv4, termasuk Elastic IP yang sedang digunakan maupun idle. Lepaskan Elastic IP saat server sudah tidak digunakan.

### Opsi B — Public IP otomatis

Opsi ini lebih sederhana untuk pengujian, tetapi IP dapat berubah setelah stop/start. Jika IP berubah:

- Perbarui DNS.
- Perbarui `client_ipv4` dan allowlist API Namecheap.

## 5. Hubungkan melalui SSH

Linux/macOS:

```bash
chmod 400 nameshift-production.pem
ssh -i nameshift-production.pem ubuntu@PUBLIC_IP_EC2
```

Windows PowerShell:

```powershell
ssh -i "C:\path\nameshift-production.pem" ubuntu@PUBLIC_IP_EC2
```

Jika SSH timeout, periksa inbound rule port 22 dan pastikan source berisi public IP Anda saat ini dengan suffix `/32`.

## 6. Update server dan buat swap

Instance micro memiliki RAM terbatas. Swap membantu mencegah proses `composer install` atau `npm run build` dihentikan karena kehabisan memori.

```bash
sudo apt update
sudo apt upgrade -y
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
free -h
```

Pastikan baris swap belum ada di `/etc/fstab` sebelum menjalankan `tee` lagi.

## 7. Install Nginx, PHP, MySQL, Composer, Node.js, dan Supervisor

```bash
sudo apt install -y nginx mysql-server git unzip curl supervisor
sudo apt install -y php8.5-fpm php8.5-cli php8.5-mysql php8.5-curl php8.5-mbstring php8.5-xml php8.5-zip php8.5-bcmath php8.5-intl
sudo apt install -y composer nodejs npm
```

Periksa versi:

```bash
php -v
composer --version
node --version
npm --version
nginx -v
```

Project membutuhkan PHP minimal 8.2. Vite 6 membutuhkan Node.js modern; gunakan Node.js 20 LTS atau lebih baru jika versi dari repository Ubuntu terlalu lama.

Aktifkan service:

```bash
sudo systemctl enable nginx
sudo systemctl enable php8.5-fpm
sudo systemctl enable mysql
sudo systemctl enable supervisor
sudo systemctl start nginx
sudo systemctl start php8.5-fpm
sudo systemctl start mysql
sudo systemctl start supervisor
```

## 8. Amankan server dasar

Aktifkan firewall OS setelah memastikan rule SSH tersedia:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
sudo ufw status
```

Security Group AWS tetap menjadi firewall utama; UFW menjadi lapisan tambahan.

## 9. Buat database MySQL

Buka MySQL sebagai root lokal:

```bash
sudo mysql
```

Jalankan SQL berikut. Ganti `DB_PASSWORD_KUAT` dengan password asli dan pertahankan tanda petiknya:

```sql
CREATE DATABASE nameshift CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nameshift'@'127.0.0.1' IDENTIFIED BY 'qweasd123';
GRANT ALL PRIVILEGES ON nameshift.* TO 'nameshift'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

Uji login:

```bash
mysql -h 127.0.0.1 -u nameshift -p nameshift
```

Masukkan password ketika diminta, lalu jalankan `EXIT;`.

## 10. Upload atau clone source code

### Repository publik

```bash
sudo mkdir -p /var/www/nameshift
sudo chown ubuntu:www-data /var/www/nameshift
git clone https://github.com/dimassetio/godsseo-nameshift.git /var/www/nameshift
cd /var/www/nameshift
```

### Repository private

Gunakan salah satu metode berikut:

- Deploy key SSH khusus repository dengan akses read-only.
- Personal access token yang hanya memiliki akses baca ke repository tersebut.
- Upload archive build menggunakan SCP.

Jangan menyimpan Git token di `.env`, source code, atau command history.

Jika Laravel berada di subfolder repository, masuk ke folder yang memiliki file `artisan`, `composer.json`, dan `package.json` sebelum melanjutkan.

## 11. Install dependency production

```bash
cd /var/www/nameshift
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci
npm run build
```

Production tidak menggunakan `composer run dev`, `php artisan serve`, atau `npm run dev`. Nginx melayani aplikasi dan Supervisor menjalankan queue.

Jika build Node gagal karena RAM, pastikan swap aktif dengan `free -h`.

## 12. Buat file environment production

```bash
cd /var/www/nameshift
cp .env.example .env
nano .env
```

Gunakan konfigurasi dasar berikut. Ganti public IP dan password:

```dotenv
APP_NAME="Nameshift"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_TIMEZONE=Asia/Jakarta
APP_URL=https://PUBLIC_IP_EC2

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nameshift
DB_USERNAME=nameshift
DB_PASSWORD="DB_PASSWORD_KUAT"

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=null

QUEUE_CONNECTION=database
DB_QUEUE=default
DB_QUEUE_RETRY_AFTER=120

CACHE_STORE=database
FILESYSTEM_DISK=local

MAIL_MAILER=log
MAIL_FROM_ADDRESS="EMAIL_ANDA"
MAIL_FROM_NAME="Nameshift"

VITE_APP_NAME="Nameshift"
```

Untuk tahap instalasi sebelum sertifikat aktif, sementara gunakan `APP_URL=http://PUBLIC_IP_EC2` dan `SESSION_SECURE_COOKIE=false`. Jangan memasukkan credential registrar melalui HTTP. Segera aktifkan HTTPS dan kembalikan `SESSION_SECURE_COOKIE=true`.

Generate application key:

```bash
php artisan key:generate
```

> **Jangan pernah mengganti atau menghapus `APP_KEY` setelah registrar account dibuat.** Token Name.com dan API key Namecheap disimpan terenkripsi menggunakan key tersebut. Jika `APP_KEY` hilang, credential lama tidak dapat didekripsi. Backup `.env` secara aman dan jangan commit ke Git.

Batasi permission `.env`:

```bash
sudo chown ubuntu:www-data /var/www/nameshift/.env
sudo chmod 640 /var/www/nameshift/.env
```

## 13. Migrasi database dan permission Laravel

```bash
cd /var/www/nameshift
sudo chown -R ubuntu:www-data /var/www/nameshift
sudo chown -R www-data:www-data /var/www/nameshift/storage /var/www/nameshift/bootstrap/cache
sudo chmod -R ug+rwx /var/www/nameshift/storage /var/www/nameshift/bootstrap/cache
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize
```

Artisan production dijalankan sebagai `www-data`, yaitu user yang sama dengan PHP-FPM dan queue worker. Ini mencegah file log atau cache dibuat dengan owner yang tidak dapat ditulis oleh proses web.

Periksa aplikasi:

```bash
sudo -u www-data php artisan about
sudo -u www-data php artisan migrate:status
sudo -u www-data php artisan schedule:list
```

## 14. Konfigurasi Nginx

Buat file virtual host:

```bash
sudo nano /etc/nginx/sites-available/nameshift
```

Isi dengan konfigurasi berikut:

```nginx
server {
    listen 80;
    listen [::]:80;

    server_name PUBLIC_IP_EC2;
    root /var/www/nameshift/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;
    client_max_body_size 10M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Aktifkan virtual host:

```bash
sudo ln -s /etc/nginx/sites-available/nameshift /etc/nginx/sites-enabled/nameshift
sudo rm /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

Jika file symlink sudah ada, jangan ulangi perintah `ln -s`.

Uji dari server:

```bash
curl -I http://127.0.0.1
```

Respons normal adalah `200`, `301`, atau `302`, bukan `502`.

## 15. Konfigurasi queue worker dengan Supervisor

Fitur synchronize domain dan update nameserver tidak akan selesai tanpa queue worker. Buat konfigurasi:

```bash
sudo nano /etc/supervisor/conf.d/nameshift-worker.conf
```

Isi:

```ini
[program:nameshift-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/nameshift/artisan queue:work database --queue=registrar-mutations,registrar-sync,default --sleep=2 --tries=50 --timeout=60 --max-time=3600
directory=/var/www/nameshift
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/nameshift/storage/logs/worker.log
stopwaitsecs=120
```

Satu worker dipilih untuk menghemat RAM instance micro. Urutan queue membuat perubahan registrar diproses sebelum sync dan queue default.

Aktifkan worker:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start nameshift-worker:*
sudo supervisorctl status
```

Status yang diharapkan adalah `RUNNING`.

Perintah pemeriksaan queue:

```bash
cd /var/www/nameshift
sudo -u www-data php artisan queue:failed
sudo tail -f storage/logs/worker.log
```

## 16. Aktifkan Laravel Scheduler

Project ini memakai scheduler untuk membersihkan batch dan failed jobs lama. Edit cron milik user web server:

```bash
sudo crontab -u www-data -e
```

Tambahkan satu baris:

```cron
* * * * * cd /var/www/nameshift && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Pastikan terpasang:

```bash
sudo crontab -u www-data -l
cd /var/www/nameshift
sudo -u www-data php artisan schedule:list
```

## 17. Verifikasi akses melalui public IP

Tidak ada konfigurasi DNS pada deployment ini. Pastikan `PUBLIC_IP_EC2` adalah alamat yang terlihat pada detail instance atau Elastic IP yang sudah diasosiasikan.

Uji HTTP awal:

```bash
curl -I http://PUBLIC_IP_EC2
```

Respons normal adalah `200`, `301`, atau `302`. Jika timeout, periksa Security Group, UFW, Nginx, dan pastikan instance masih menggunakan IP yang sama.

Untuk Nameshift, Elastic IP tetap disarankan karena:

- URL aplikasi tidak berubah setelah EC2 stop/start.
- Allowlist client IPv4 Namecheap tetap valid.
- Sertifikat HTTPS IP tidak perlu diterbitkan ulang untuk alamat berbeda.

## 18. Aktifkan HTTPS langsung pada public IP

Jangan gunakan aplikasi registrar melalui HTTP. Token dan API key dapat dibaca dari traffic jaringan jika koneksi tidak dienkripsi.

Sejak 2026, Let's Encrypt dapat menerbitkan sertifikat public IPv4 tanpa domain. Sertifikat IP menggunakan profil `shortlived`, berlaku sekitar enam hari, dan harus diperbarui otomatis. Certbot minimal versi 5.4 diperlukan untuk mode webroot IP.

Install Certbot dan periksa versinya:

```bash
sudo snap install --classic certbot
sudo ln -s /snap/bin/certbot /usr/local/bin/certbot
certbot --version
```

Pastikan hasilnya versi 5.4 atau lebih baru, lalu minta sertifikat. Ganti placeholder dengan angka public IP asli tanpa `http://`:

```bash
sudo certbot certonly --preferred-profile shortlived --webroot --webroot-path /var/www/nameshift/public --ip-address PUBLIC_IP_EC2
```

Certbot belum memasang sertifikat IP ke Nginx secara otomatis. Edit virtual host:

```bash
sudo nano /etc/nginx/sites-available/nameshift
```

Ganti isinya dengan konfigurasi berikut:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name PUBLIC_IP_EC2;

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/nameshift/public;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name PUBLIC_IP_EC2;
    root /var/www/nameshift/public;

    ssl_certificate /etc/letsencrypt/live/PUBLIC_IP_EC2/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/PUBLIC_IP_EC2/privkey.pem;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;
    client_max_body_size 10M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Periksa dan reload Nginx:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

Buat deploy hook agar Nginx otomatis membaca sertifikat baru setelah renewal:

```bash
sudo nano /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
```

Isi file:

```sh
#!/bin/sh
systemctl reload nginx
```

Aktifkan hook:

```bash
sudo chmod 750 /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
```

Pastikan timer renewal aktif dan uji pembaruan beserta deploy hook:

```bash
systemctl list-timers | grep certbot
sudo certbot renew --dry-run --run-deploy-hooks
```

Karena sertifikat hanya berlaku sekitar enam hari, jangan lanjut production jika renewal test gagal.

Pastikan `.env` menggunakan:

```dotenv
APP_URL=https://PUBLIC_IP_EC2
SESSION_SECURE_COOKIE=true
```

Setelah mengubah `.env`:

```bash
cd /var/www/nameshift
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan optimize
sudo supervisorctl restart nameshift-worker:*
```

## 19. Konfigurasi registrar pada production

### Namecheap

1. Masukkan Elastic IP EC2 sebagai `client_ipv4` pada account di aplikasi.
2. Allowlist IP yang sama pada pengaturan API Namecheap.
3. Gunakan endpoint environment yang sesuai dengan credential: Sandbox atau Production.
4. Klik **Test connection** sebelum synchronize.

Jika tidak menggunakan Elastic IP, ulangi allowlist setiap kali public IP EC2 berubah.

### Name.com

1. Username adalah username account Name.com.
2. Token adalah nilai API token, bukan nama/label token.
3. Credential sandbox dan production harus digunakan pada environment yang sesuai.
4. Klik **Test connection** satu kali dan tunggu hasilnya sebelum synchronize agar tidak memicu rate limit.

## 20. Checklist verifikasi production

Jalankan:

```bash
cd /var/www/nameshift
sudo -u www-data php artisan about
sudo -u www-data php artisan migrate:status
sudo -u www-data php artisan schedule:list
sudo supervisorctl status
sudo nginx -t
sudo systemctl status nginx --no-pager
sudo systemctl status php8.5-fpm --no-pager
sudo systemctl status mysql --no-pager
curl -I https://PUBLIC_IP_EC2/up
```

Kemudian periksa melalui browser:

- Halaman login muncul tanpa error.
- Registrasi/login dapat dilakukan sesuai konfigurasi aplikasi.
- Asset CSS dan JavaScript termuat.
- Test connection registrar memberikan pesan sukses atau error provider yang jelas.
- Synchronize berpindah dari `QUEUED` ke hasil akhir.
- Single dan bulk nameserver update diproses worker.

## 21. Prosedur deployment update berikutnya

Backup terlebih dahulu, lalu:

```bash
cd /var/www/nameshift
sudo -u www-data php artisan down
git pull --ff-only
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci
npm run build
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan optimize
sudo -u www-data php artisan queue:restart
sudo supervisorctl restart nameshift-worker:*
sudo -u www-data php artisan up
```

Jika `git pull`, Composer, atau npm gagal, jangan menjalankan migrasi sampai penyebabnya diperbaiki.

## 22. Backup minimum

Database dan `APP_KEY` adalah data paling penting.

Backup database manual:

```bash
mkdir -p /home/ubuntu/backups
mysqldump -h 127.0.0.1 -u nameshift -p nameshift | gzip > /home/ubuntu/backups/nameshift.sql.gz
```

Simpan salinan `.env` terenkripsi di password manager atau secret vault. Jangan menyimpannya di repository atau bucket publik.

Untuk production jangka panjang, gunakan snapshot EBS terjadwal atau AWS Backup. Periksa biayanya sebelum mengaktifkan.

## 23. Troubleshooting

### Halaman `502 Bad Gateway`

```bash
sudo systemctl status php8.5-fpm --no-pager
ls -la /run/php/
sudo tail -n 100 /var/log/nginx/error.log
```

Pastikan socket pada konfigurasi Nginx sama dengan socket PHP-FPM yang tersedia.

### Halaman `500 Internal Server Error`

```bash
cd /var/www/nameshift
tail -n 100 storage/logs/laravel.log
sudo -u www-data php artisan about
```

Periksa `APP_KEY`, koneksi database, permission `storage`, dan `bootstrap/cache`.

### Job terus `QUEUED`

```bash
sudo supervisorctl status
sudo supervisorctl restart nameshift-worker:*
cd /var/www/nameshift
sudo -u www-data php artisan queue:failed
tail -n 100 storage/logs/worker.log
tail -n 100 storage/logs/laravel.log
```

Jangan menjalankan `php artisan serve` untuk mengatasi queue; web server dan queue adalah proses berbeda.

### Asset atau tampilan React tidak muncul

```bash
cd /var/www/nameshift
npm ci
npm run build
ls -la public/build
sudo -u www-data php artisan optimize:clear
```

### Upload Excel ditolak

Nginx pada panduan ini menerima maksimal 10 MB. Jika PHP masih menolak file, edit `/etc/php/8.5/fpm/php.ini`:

```ini
upload_max_filesize = 10M
post_max_size = 12M
```

Kemudian:

```bash
sudo systemctl restart php8.5-fpm
sudo systemctl reload nginx
```

### Credential registrar tidak dapat didekripsi

Biasanya terjadi karena `APP_KEY` berubah. Pulihkan `.env` yang memiliki `APP_KEY` asli. Mengganti token melalui UI hanya dapat dilakukan jika aplikasi masih bisa membuka record terenkripsi lama; jika tidak, record credential harus dibuat ulang dengan hati-hati.

## 24. Menghentikan biaya saat server tidak dipakai

- **Stop instance** menghentikan compute charge, tetapi volume EBS dan public IPv4/Elastic IP dapat tetap menimbulkan biaya.
- **Terminate instance** menghapus instance dan biasanya root volume jika `Delete on termination` aktif.
- Lepaskan Elastic IP yang tidak digunakan.
- Hapus snapshot, volume, atau resource lain yang tidak lagi diperlukan.
- Periksa **Billing → Bills** dan **Cost Explorer**, bukan hanya status instance.

Backup database dan `.env` sebelum terminate.

## Referensi resmi

- [AWS Free Tier FAQ](https://aws.amazon.com/free/free-tier-faqs/)
- [Getting Started with Amazon EC2](https://aws.amazon.com/ec2/getting-started/)
- [Amazon EC2 security groups](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/ec2-security-groups.html)
- [Amazon EC2 public and Elastic IP addressing](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/using-instance-addressing.html)
- [Creating an AWS Budget](https://docs.aws.amazon.com/cost-management/latest/userguide/budgets-create.html)
- [Laravel 12 deployment](https://laravel.com/docs/12.x/deployment)
- [Laravel 12 queues and Supervisor](https://laravel.com/docs/12.x/queues#supervisor-configuration)
- [Laravel 12 task scheduling](https://laravel.com/docs/12.x/scheduling#running-the-scheduler)
- [Let's Encrypt: IP address certificates are generally available](https://letsencrypt.org/2026/01/15/6day-and-ip-general-availability.html)
- [Let's Encrypt: IP address certificates with Certbot](https://letsencrypt.org/2026/03/11/shorter-certs-certbot/)
