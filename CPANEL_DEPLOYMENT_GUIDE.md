# Panduan Deployment AI Router ke Shared Hosting cPanel (PHP 8.x + MySQL)

Dokumen ini memandu proses migrasi dan instalasi backend **AI Router (PHP)** beserta antarmuka **Frontend React/Vite** ke lingkungan shared hosting cPanel tanpa memerlukan Python App, Docker, VPS, atau proses daemon server.

---

## 1. Persyaratan Lingkungan Hosting

Pastikan paket hosting cPanel Anda memenuhi kriteria berikut:
- **Versi PHP:** PHP 8.1, 8.2, atau 8.3 (Dapat diatur melalui menu *Select PHP Version* atau *MultiPHP Manager* di cPanel).
- **Ekstensi PHP Wajib:**
  - `pdo` & `pdo_mysql` (Akses database relasional)
  - `curl` (Komunikasi upstream AI provider & Telegram webhook)
  - `json` (Parsing dan serialisasi data OpenAI-compatible)
  - `mbstring` (Normalisasi string UTF-8)
  - `openssl` (Kriptografi token acak dan verifikasi signature)
  - `fileinfo` (Validasi MIME file bukti transfer pembayaran)
- **Database:** MySQL 8.0+ atau MariaDB 10.4+ (Mendukung InnoDB, transaksi atomik, dan constraint `CHECK`).
- **Web Server:** Apache 2.4+ atau LiteSpeed Web Server dengan modul `mod_rewrite` dan `mod_headers` aktif.
- **SSL/TLS:** Sertifikat SSL aktif (cPanel AutoSSL / Let's Encrypt) untuk menjamin HTTPS.

---

## 2. Struktur Direktori Deployment

Terdapat 2 opsi penempatan file di hosting:

### Opsi A: Struktur Paling Aman (Direkomendasikan)
Menempatkan logika bisnis, secret, dan bukti pembayaran di luar `public_html` agar tidak dapat diakses langsung oleh browser:

```text
/home/cpaneluser/
├── AI Ciptaone-backend/               # DI LUAR public_html (Aman dari akses publik)
│   ├── .env                      # Kredensial & secret database
│   ├── migrations/               # Skema SQL
│   ├── src/                      # Kode sumber PHP (Core, Services, Controllers)
│   └── storage/
│       └── proofs/               # Bukti transfer (Dilindungi)
└── public_html/                  # DOCUMENT ROOT PUBLIK
    ├── .htaccess                 # Routing API & React SPA fallback
    ├── index.php                 # Front controller (memanggil ../AI Ciptaone-backend/src/bootstrap.php)
    ├── index.html                # Build frontend React
    └── assets/                   # File bundle frontend (JS/CSS)
```

> Jika memilih Opsi A, sesuaikan path pada `public_html/index.php`:
> ```php
> require_once __DIR__ . '/../AI Ciptaone-backend/src/bootstrap.php';
> ```

---

### Opsi B: Struktur Single Folder (Di dalam `public_html`)
Jika penyedia hosting Anda membatasi akses di luar `public_html`:

```text
/home/cpaneluser/public_html/
├── .env                          # Dilindungi oleh rule .htaccess
├── .htaccess                     # Memblokir akses ke .env, src/, storage/
├── index.php                     # Front controller
├── index.html                    # Build frontend React
├── assets/                       # Asset frontend
├── src/                          # Backend PHP
└── storage/
    └── proofs/                   # Folder bukti transfer (dilindungi .htaccess internal)
```

---

## 3. Langkah-Langkah Deployment

### Langkah 1: Pengaturan Versi & Ekstensi PHP di cPanel
1. Masuk ke cPanel.
2. Buka menu **Select PHP Version** (atau **MultiPHP Manager**).
3. Pilih domain Anda dan ubah ke **PHP 8.2** atau **PHP 8.3**.
4. Di tab **Extensions**, pastikan ekstensi berikut dicentang:
   - `pdo_mysql`, `curl`, `json`, `mbstring`, `openssl`, `fileinfo`.
5. Di tab **Options**, atur konfigurasi:
   - `memory_limit` = `256M`
   - `max_execution_time` = `60` (atau `120` jika provider AI lambat)
   - `upload_max_filesize` = `8M`
   - `post_max_size` = `10M`

---

### Langkah 2: Pembuatan Database MySQL
1. Di cPanel, buka **MySQL® Databases** (atau **MySQL Database Wizard**).
2. Buat database baru, misalnya: `cpaneluser_AI Ciptaone`.
3. Buat pengguna database baru, misalnya: `cpaneluser_dbuser` dengan password acak yang kuat.
4. Hubungkan pengguna ke database dengan memberikan hak akses penuh (**ALL PRIVILEGES**).
5. Buka **phpMyAdmin** dari cPanel.
6. Pilih database `cpaneluser_AI Ciptaone`.
7. Masuk ke tab **Import**:
   - Pilih file `php-backend/migrations/mysql_0001_initial_schema.sql` lalu klik **Go**.
   - Pilih file `php-backend/migrations/mysql_0002_seed_data.sql` lalu klik **Go**.
8. Pastikan 13 tabel berhasil terbentuk (`users`, `sessions`, `api_keys`, `providers`, `models`, `wallets`, `credit_ledger`, `credit_reservations`, `usage_events`, `topups`, `payment_webhook_events`, `admin_audit_logs`, `rate_limit_buckets`).

---

### Langkah 3: Konfigurasi File `.env`
1. Salin file `php-backend/.env.example` menjadi `.env`.
2. Isi kredensial database yang baru dibuat:
   ```env
   APP_ENV=production
   DEBUG=false
   SECRET_KEY=masukkan_64_karakter_acak_hex_di_sini

   DB_DRIVER=mysql
   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=cpaneluser_AI Ciptaone
   DB_USER=cpaneluser_dbuser
   DB_PASS=password_database_anda
   DB_CHARSET=utf8mb4

   SESSION_SECURE_COOKIE=true
   CORS_ORIGINS=https://domainanda.com
   ```
3. Konfigurasikan data rekening/top-up manual:
   ```env
   MANUAL_BANK_NAME="BCA"
   MANUAL_ACCOUNT_NO="1234567890"
   MANUAL_ACCOUNT_HOLDER="Nama Rekening Usaha"
   MANUAL_QRIS_IMAGE_URL=""
   ```
4. Masukkan token bot Telegram (lihat panduan bot Telegram pada `TELEGRAM_WEBHOOK_GUIDE.md`).
5. Masukkan API key upstream AI provider (misal OpenRouter atau OpenAI) bila ingin mengaktifkan model komersial.

---

### Langkah 4: Build & Upload Frontend Statis
1. Di komputer lokal Anda, lakukan build frontend:
   ```bash
   cd frontend
   npm install
   npm run build
   ```
2. Hasil build akan berada di folder `frontend/dist/`.
3. Kompres isi folder `frontend/dist/` (termasuk `index.html` dan folder `assets/`) menjadi file ZIP.
4. Di cPanel **File Manager**, upload dan ekstrak isi file tersebut ke dalam `public_html/`.

---

### Langkah 5: Verifikasi File `.htaccess`
Pastikan file `.htaccess` di dalam `public_html/` memuat konfigurasi routing terpadu:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /

    # Disable output buffering & gzip untuk streaming SSE OpenAI
    <IfModule mod_env.c>
        SetEnv no-gzip 1
    </IfModule>
    <IfModule LiteSpeed>
        RewriteRule .* - [E=nobuf:1]
    </IfModule>

    # Lindungi file konfigurasi sensitif
    RewriteRule ^\.(?!well-known) - [F]
    RewriteRule ^(config|storage|src|tests|migrations)/ - [F]

    # Rute API backend ke PHP Front Controller
    RewriteRule ^(api/v1/.*|v1/.*|health/.*)$ index.php [QSA,L]

    # Sajikan file statis frontend jika file fisik ditemukan
    RewriteCond %{REQUEST_FILENAME} -f [OR]
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]

    # Fallback untuk React Router SPA
    RewriteCond %{DOCUMENT_ROOT}/index.html -f
    RewriteRule ^ index.html [L]

    # Default fallback
    RewriteRule ^ index.php [QSA,L]
</IfModule>

<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "DENY"
    Header set X-XSS-Protection "1; mode=block"
    Header set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>

Options -Indexes
```

---

### Langkah 6: Pembuatan Akun Admin Awal
1. Buka browser dan akses halaman registrasi: `https://domainanda.com/register`.
2. Daftarkan email admin Anda (misalnya `admin@domainanda.com`).
3. Buka **phpMyAdmin**, cari tabel `users`, lalu edit baris user tersebut:
   - Ubah kolom `role` dari `'user'` menjadi `'admin'`.
   - Ubah kolom `email_verified_at` menjadi `NOW()`.
4. Berikan saldo kredit awal (opsional) melalui tabel `wallets` atau lewat fitur penyesuaian saldo di Dashboard Admin.

---

### Langkah 7: Pengujian & Health Check
1. Buka endpoint liveness:  
   `https://domainanda.com/health/live`  
   Respons yang diharapkan:
   ```json
   { "status": "ok", "service": "ai-ciptaone-router", "runtime": "php-8.x.x" }
   ```
2. Buka endpoint readiness:  
   `https://domainanda.com/health/ready`  
   Respons yang diharapkan:
   ```json
   { "status": "ready", "database": "connected" }
   ```
3. Coba login ke `https://domainanda.com/login` dan kirim pesan di Playground Chat menggunakan model `mock/echo-v1`.
