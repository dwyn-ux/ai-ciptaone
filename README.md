# AI Ciptaone Router (MVP)

> **Produk:** AI API Router Universal + Playground Chat Multi-Model  
> **Infrastruktur Target:** Oracle Cloud Always Free (Backend Gateway) + Cloudflare Workers / Cloudflare D1 (Database) + Caddy (Reverse Proxy HTTPS)  
> **Bahasa Antarmuka:** Bahasa Indonesia  
> **Prinsip Operasional:** Biaya terkendali (Rp0 infrastruktur kuota gratis), keamanan default, reservasi kredit atomik, perlindungan mutasi resmi.

---

## 1. Ringkasan Arsitektur

### A. Opsi 1: Shared Hosting cPanel (PHP 8.x + MySQL/MariaDB) — *Deployment Termurah*
```text
Browser / Client App
  ├── React 18 + TypeScript + Vite (Static Frontend di public_html/)
  └── HTTPS API (Apache / LiteSpeed cPanel)
        └── PHP 8.x Native Gateway (FastCGI / PHP-FPM)
              ├── Auth / Session HttpOnly, CSRF & API Keys (SHA-256)
              ├── AI Router (OpenAI Compatible /v1) + Streaming SSE
              ├── Wallet, Atomic Reservation & Double-Entry Ledger
              ├── Top-up Manual & Webhook Bot Telegram Admin
              └── PDO MySQL / MariaDB (InnoDB, Transaksi Atomik)
```

### B. Opsi 2: Cloud VM (Python FastAPI + Cloudflare D1) — *Deployment Cloud Oracle*
```text
Browser / Client App
  ├── React 18 + TypeScript + Vite (Static Frontend)
  └── HTTPS API
        └── Oracle Cloud VM (Ampere A1 Ubuntu ARM64)
              ├── Caddy 2 (TLS / Reverse Proxy HTTPS otomatis)
              └── FastAPI (Python 3.12+ ASGI Gateway)
                    ├── Auth / Session HttpOnly & API Keys (SHA-256)
                    ├── AI Router (OpenAI Compatible /v1)
                    ├── Wallet, Atomic Reservation & Ledger
                    ├── Top-up Manual & Bot Telegram Admin
                    └── Cloudflare Worker Data API (Bearer Token)
                          └── Cloudflare D1 (Serverless SQLite)
```

- **Cloudflare D1:** Menyimpan database relasional (users, sessions, api_keys, providers, models, wallets, credit_ledger, credit_reservations, usage_events, topups, payment_webhook_events, admin_audit_logs).
- **Cloudflare Worker (`worker/`):** Lapisan akses private API ke binding D1 dengan autentikasi `Authorization: Bearer <D1_SERVICE_TOKEN>`.
- **FastAPI Backend (`backend/`):** Logika bisnis utama, orkestrasi model, reservasi kredit atomik, penegakan saldo tidak boleh negatif (`CHECK(balance_credits >= 0)`), adapter pembayaran, dan endpoint OpenAI-compatible (`/v1/chat/completions` dan `/v1/models`).
- **React Frontend (`frontend/`):** Antarmuka responsif mencakup Dashboard Saldo, Playground Chat (dengan streaming SSE), Manajemen API Key (reveal sekali pakai + copy), Top-up & Unggah Bukti Pembayaran, Riwayat Pemakaian/Ledger, serta Admin Panel.

---

## 2. Struktur Repositori

```text
ciptaone.com/
├── PRD.md                       # Spesifikasi persyaratan produk
├── README.md                    # Dokumentasi lengkap & panduan deployment
├── CPANEL_DEPLOYMENT_GUIDE.md   # Panduan deployment lengkap shared hosting cPanel
├── TELEGRAM_WEBHOOK_GUIDE.md    # Panduan setup bot Telegram privat & allowlist review
├── API_CONTRACTS.md             # Daftar kontrak endpoint yang dipertahankan
├── TEST_REPORT.md               # Laporan hasil pengujian & perbandingan implementasi
├── .env.example                 # Template environment variable
├── php-backend/                 # Backend PHP 8.x untuk Shared Hosting cPanel
│   ├── .env.example             # Konfigurasi database MySQL cPanel
│   ├── migrations/              # Skema MySQL/MariaDB & data seed
│   │   ├── mysql_0001_initial_schema.sql
│   │   └── mysql_0002_seed_data.sql
│   ├── src/                     # Arsitektur PSR-4 mandiri (Core, Services, Controllers)
│   │   ├── bootstrap.php        # Autoloader & routing registrasi
│   │   ├── Core/                # Database PDO, Router, Security, RateLimiter
│   │   ├── Services/            # Wallet, Auth, ApiKey, Topup, Router, Telegram
│   │   └── Controllers/         # Endpoint handlers (/v1, /api/v1, /health)
│   ├── public/                  # Document root publik cPanel
│   │   ├── index.php            # Front controller
│   │   └── .htaccess            # Routing API, SSE streaming, SPA fallback
│   ├── storage/proofs/          # Private storage bukti pembayaran (.htaccess protected)
│   └── tests/                   # Test suite mandiri PHP (CLI & Web runner)
│       ├── run_tests.php
│       └── web_runner.php
├── migrations/                  # Skrip migrasi SQL Cloudflare D1 berurutan
│   ├── 0001_initial_schema.sql  # 13 tabel inti, indeks, dan integritas
│   └── 0002_seed_data.sql       # Seed data provider mock & model awal
├── worker/                      # Cloudflare Worker untuk gateway binding D1
│   ├── package.json
│   ├── tsconfig.json
│   ├── wrangler.toml            # Konfigurasi D1 database binding
│   └── src/index.ts             # Gateway Worker dengan validasi Bearer token & batch transaksi
├── backend/                     # Backend Gateway FastAPI (Python 3.12+)
│   ├── requirements.txt
│   ├── pyproject.toml
│   ├── Dockerfile
│   ├── app/
│   │   ├── config.py            # Konfigurasi Pydantic Settings
│   │   ├── main.py              # Inisialisasi FastAPI, CORS, middleware keamanan
│   │   ├── core/
│   │   │   ├── security.py      # Password hash (Argon2id/bcrypt), SHA-256 API key
│   │   │   ├── database.py      # Client D1 Worker + SQLite fallback mandiri
│   │   │   ├── rate_limit.py    # Sliding window rate limiter
│   │   │   └── errors.py        # Error envelope standar PRD
│   │   ├── models/schemas.py    # Pydantic schemas untuk semua request/response
│   │   ├── services/
│   │   │   ├── auth_service.py  # Registrasi, login session, rotasi token
│   │   │   ├── api_key_service.py # API key lifecycle & hash lookup
│   │   │   ├── wallet_service.py  # Atomic reservation, settlement, ledger, topup
│   │   │   ├── router_service.py  # Pricing, token math, streaming SSE
│   │   │   ├── provider_adapters.py # Mock adapter & OpenAI upstream adapter
│   │   │   ├── topup_service.py   # Invoice unik, upload bukti privat, review
│   │   │   ├── telegram_bot.py    # Notifikasi invoice & callback admin allowlist
│   │   │   ├── audit_service.py   # Admin audit logs
│   │   │   └── payment_adapters/  # Adapter pembayaran modular (Manual & DANA)
│   │   └── routers/
│   │       ├── auth.py          # /api/v1/auth/*
│   │       ├── me.py            # /api/v1/me, /api/v1/wallet, /api/v1/usage, /api/v1/transactions
│   │       ├── api_keys.py      # /api/v1/api-keys
│   │       ├── openai_v1.py     # /v1/models, /v1/chat/completions
│   │       ├── topups.py        # /api/v1/topups, upload bukti
│   │       ├── webhooks.py      # /api/v1/webhooks/*
│   │       ├── admin.py         # /api/v1/admin/* (kill switch, pricing, review)
│   │       └── health.py        # /health/live, /health/ready
│   └── tests/                   # Test suite komprehensif
│       ├── conftest.py
│       ├── test_auth.py
│       ├── test_api_keys.py
│       ├── test_wallet_ledger.py
│       ├── test_router_chat.py
│       ├── test_topup_telegram.py
│       ├── test_payment_adapters.py
│       ├── test_admin_audit.py
│       └── run_tests.py         # Test runner mandiri berbasis asyncio
└── frontend/                    # Frontend React + TypeScript + Vite
    ├── package.json
    ├── vite.config.ts
    ├── index.html
    └── src/
        ├── App.tsx
        ├── main.tsx
        ├── api/client.ts        # Fetch client dengan credentials & error parsing
        ├── contexts/AuthContext.tsx
        ├── components/          # Navbar, Layout
        └── pages/               # Login, Register, Dashboard, Playground, ApiKeys, Topup, History, Admin
```

---

## 3. Alur Kerja Inti & Keamanan

### A. Reservasi Kredit & Transaksi Atomik
1. Setiap request completion AI menghitung perkiraan kredit maksimum:  
   $$\text{Max Credits} = \lceil (\text{Input Token} \times \text{Input Price} + \text{Max Output Token} \times \text{Output Price}) / 1.000.000 \rceil$$
2. Saldo dikurangi secara atomik via constraint database `CHECK(balance_credits >= 0)`.
3. Setelah respon upstream diterima, biaya aktual dihitung. Selisih kredit yang tidak terpakai dikembalikan secara otomatis ke wallet pengguna.
4. Setiap mutasi dicatat pada `credit_ledger` dengan `idempotency_key` unik untuk mencegah double-spend atau duplicate credit.

### B. Top-up Manual & Bot Telegram
1. Pengguna memilih paket atau nominal di halaman Top-up.
2. Server membuat invoice unik (`INV-YYYYMMDD-XXXXXX`) dengan batas kedaluwarsa 2 jam.
3. Pengguna mentransfer dana ke rekening resmi usaha dan mengunggah foto bukti pembayaran.
4. Bukti transfer divalidasi MIME (hanya JPEG, PNG, WEBP) dan ukuran maksimal 5MB, kemudian disimpan di private storage lokal (`PROOF_UPLOAD_DIR`), bukan URL publik.
5. Bot Telegram privat mengirimkan ringkasan invoice ke Telegram Admin dengan tombol interaktif **"✅ Setujui"** dan **"❌ Tolak"**.
6. Tindakan tombol diverifikasi terhadap allowlist `TELEGRAM_ADMIN_IDS`. Admin memeriksa mutasi rekening resmi aktual sebelum menekan setuju.
7. Callback diproteksi idempotency di database sehingga klik berulang tidak akan menggandakan kredit.

### C. Adapter DANA Dynamic QRIS (Fase Lanjutan)
- Sesuai PRD Bagian 7 dan 14, adapter DANA telah disiapkan di `backend/app/services/payment_adapters/dana.py` dengan kalkulasi signature HMAC/SNAP dan webhook deduplication.
- Integrasi dinamis dinonaktifkan secara aman (`DANA_ENABLED=false`) sampai verifikasi merchant resmi DANA Bisnis, kredensial produksi, dan webhook disetujui secara tertulis oleh pihak DANA.

---

## 4. Cara Menjalankan Aplikasi

### Persiapan Awal
Pastikan telah menginstal:
- Python 3.11 atau 3.12
- Node.js 18+ & npm
- (Opsional) Docker & Docker Compose untuk pengujian deployment

Salin template konfigurasi:
```bash
cp .env.example .env
```

### A. Menjalankan Backend PHP (Target cPanel Shared Hosting)

#### 1. Menjalankan Test Suite PHP Mandiri (Zero-Dependency)
```bash
cd php-backend
php tests/run_tests.php
```
*Atau buka via browser di: `http://localhost:8000/tests/web_runner.php`*

#### 2. Menjalankan Server Lokal PHP
```bash
cd php-backend
php -S localhost:8000 -t public
```

---

### B. Menjalankan Backend Python FastAPI (Cloud Oracle VM)
Jalankan pengujian mandiri tanpa dependensi eksternal (menggunakan engine SQLite terisolasi):
```bash
cd backend
python -m pip install -r requirements.txt
python tests/run_tests.py
```
Atau menggunakan `pytest`:
```bash
pytest tests/ -v
```

### B. Menjalankan Backend FastAPI
```bash
cd backend
uvicorn app.main:app --host 0.0.0.0 --port 8000 --reload
```
Akses dokumentasi interaktif Swagger di: `http://localhost:8000/docs`  
Health check liveness: `http://localhost:8000/health/live`  
Health check readiness: `http://localhost:8000/health/ready`

### C. Menjalankan Frontend React
Di terminal terpisah:
```bash
cd frontend
npm install
npm run dev
```
Buka browser di: `http://localhost:5173`

---

## 5. Panduan Deployment Produksi (Target Biaya Rp0)

### 1. Cloudflare D1 & Worker Gateway
1. Login ke Cloudflare CLI:
   ```bash
   npx wrangler login
   ```
2. Buat database D1:
   ```bash
   npx wrangler d1 create AI Ciptaone-d1
   ```
   Salin `database_id` ke dalam `worker/wrangler.toml`.
3. Jalankan migrasi schema dan seed awal:
   ```bash
   npx wrangler d1 execute AI Ciptaone-d1 --file=../migrations/0001_initial_schema.sql
   npx wrangler d1 execute AI Ciptaone-d1 --file=../migrations/0002_seed_data.sql
   ```
4. Pasang secret token pada Cloudflare Worker:
   ```bash
   cd worker
   npx wrangler secret put D1_SERVICE_TOKEN
   # Masukkan secret token acak (misal: hex 32 karakter)
   npx wrangler deploy
   ```
5. Simpan URL Worker (misal `https://AI Ciptaone-d1-worker.yourname.workers.dev`) dan secret token ke `.env` server Oracle:
   ```env
   DB_MODE=worker
   D1_WORKER_URL=https://AI Ciptaone-d1-worker.yourname.workers.dev
   D1_SERVICE_TOKEN=token-yang-sama
   ```

### 2. Oracle Cloud VM (Always Free)
1. Buat VM Ampere A1 (Ubuntu 22.04 / 24.04 ARM64, 4 OCPU, 24GB RAM) di Home Region Always Free.
2. Buka port 80 dan 443 pada Oracle Ingress Security List dan UFW:
   ```bash
   sudo ufw allow 80/tcp
   sudo ufw allow 443/tcp
   sudo ufw allow 22/tcp
   sudo ufw enable
   ```
3. Build frontend statis:
   ```bash
   cd frontend
   npm install
   npm run build
   ```
4. Jalankan dengan Docker Compose:
   ```bash
   docker compose up -d --build
   ```
   Caddy akan otomatis mendaftarkan dan memperbarui sertifikat TLS/SSL Let's Encrypt secara gratis untuk domain Anda.

---

## 6. Status Kepatuhan PRD & Hal yang Menunggu Akses Eksternal

| Komponen | Status Implementasi | Catatan / Kendala Eksternal |
| :--- | :--- | :--- |
| **Registrasi, Login, Sesi** | ✅ Selesai | HttpOnly cookies, Argon2id/bcrypt, session rotation |
| **API Key Management** | ✅ Selesai | Format `sk-live-...`, SHA-256 hash, revealed once, revocation |
| **AI Router & Gateway** | ✅ Selesai | Kompatibel OpenAI (`/v1/models`, `/v1/chat/completions`), SSE stream |
| **Provider Mock (Testing)** | ✅ Selesai | Output deterministik & echo untuk sandbox tanpa biaya token |
| **Provider Komersial (OpenRouter/OpenAI)** | ✅ Selesai (Nonaktif di Seed) | Membutuhkan verifikasi syarat reselling komersial & API key resmi |
| **Wallet & Atomic Ledger** | ✅ Selesai | Integer IDR, saldo `CHECK >= 0`, atomic reservation & settlement |
| **Top-up Manual & Upload Bukti** | ✅ Selesai | Invoice unik, expiry 2 jam, MIME check, private storage |
| **Bot Telegram Review Admin** | ✅ Selesai | Allowlist `TELEGRAM_ADMIN_IDS`, notifikasi pesan, callback approve/reject |
| **DANA Dynamic QRIS Adapter** | ⏳ Menunggu Akses Resmi | Adapter siap & diamankan (`DANA_ENABLED=false`). Menunggu persetujuan DANA Bisnis |
| **Admin Dashboard Panel** | ✅ Selesai | Topup review, bukti viewer, model killswitch, balance adjustment, audit |
| **Dokumentasi API Interaktif & OpenAPI Spec** | ✅ Selesai | Portal interaktif di `/docs`, generator kode multi-bahasa, dan `/openapi.json` |
