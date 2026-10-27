# PRD.md --- AI Router Custom (MVP)

**Status:** Draft untuk implementasi MVP\
**Produk:** AI API Router + Playground Chat\
**Target awal:** Pengguna umum yang ingin mencoba beberapa model AI
melalui satu saldo dan satu antarmuka\
**Infrastruktur target:** Oracle Cloud Always Free + Cloudflare
Workers/D1\
**Bahasa UI:** Bahasa Indonesia\
**Prinsip:** biaya terkendali, keamanan default, tidak menjanjikan AI
gratis tanpa batas

------------------------------------------------------------------------

## 1. Ringkasan Produk

Produk menyediakan playground chat dan satu API kompatibel dengan pola
OpenAI. Pengguna dapat mendaftar secara publik, membuat API key, mencoba
model yang tersedia, membeli kredit melalui alur top-up manual yang ditinjau admin pada tahap awal, lalu beralih ke QRIS dinamis DANA API resmi hanya setelah akun merchant dan akses integrasinya disetujui. Pengguna dapat melihat riwayat penggunaan dan mengelola batas pemakaian.

Oracle menjalankan backend gateway. Cloudflare Workers menjadi lapisan
akses aman ke Cloudflare D1. D1 menyimpan data aplikasi, transaksi
kredit, metadata penggunaan, dan konfigurasi model nonrahasia. Provider
AI eksternal memproses prompt dan dapat mengenakan biaya.

MVP tidak menjalankan model AI sendiri. Tidak ada klaim bahwa kuota
provider gratis dapat dijual kembali: setiap adapter/provider wajib
diaktifkan hanya setelah syarat penggunaan komersial dan reselling
diverifikasi.

## 2. Tujuan dan Non-Tujuan

### Tujuan

1.  Pengguna dapat mendaftar dan masuk dengan aman.
2.  Pengguna dapat menggunakan playground chat dengan model yang
    diaktifkan.
3.  Developer dapat memakai API key pada endpoint kompatibel OpenAI.
4.  Pengguna dapat meminta top-up melalui alur manual (transfer/QRIS yang secara resmi boleh digunakan untuk usaha), lalu admin memverifikasi transaksi aktual melalui bot Telegram.
5.  Sistem mendukung adapter pembayaran agar kelak dapat memakai QRIS dinamis DANA melalui API/webhook resmi setelah merchant disetujui.
6.  Kredit hanya ditambahkan setelah pembayaran diverifikasi secara manual oleh admin atau secara otomatis melalui notifikasi/status API resmi yang terautentikasi.
6.  Setiap request memiliki batas biaya, token, waktu, dan laju.
7.  Admin dapat mengaktifkan/menonaktifkan provider dan model, mengatur
    harga jual, serta meninjau transaksi dan penggunaan.
8.  Sistem bisa dideploy dengan target biaya infrastruktur Rp0 selama
    semua layanan tetap berada dalam kuota gratis.

### Bukan tujuan MVP

-   Pelatihan model atau hosting model sendiri.
-   Fitur vision/audio, embeddings, fine-tuning, tool marketplace,
    organisasi/teams, atau aplikasi mobile.
-   Jaminan uptime/SLA produksi.
-   Langganan unlimited.
-   Penjualan ulang kuota gratis tanpa izin provider.
-   Multi-region atau Kubernetes.
-   Menyimpan seluruh riwayat prompt secara default.

## 3. Persona dan Alur Utama

### Persona

-   Pengguna umum: mendaftar, mencoba chat, membeli kredit, melihat
    saldo.
-   Developer: membuat API key, melihat dokumentasi endpoint, mengakses
    API.
-   Admin: mengatur provider/model, harga, akun, refund/penyesuaian
    saldo, dan memantau biaya.

### Alur pengguna

1.  Pengguna mendaftar dan memverifikasi email.
2.  Pengguna masuk ke dashboard.
3.  Pengguna membuka playground, memilih model aktif, menulis prompt.
4.  Backend mengecek akun, saldo, kuota, model, serta batas token.
5.  Sistem mereservasi kredit maksimum secara atomik sebelum request
    upstream.
6.  Gateway memanggil provider dan mengumpulkan pemakaian token/biaya.
7.  Sistem menyelesaikan reservasi dengan biaya aktual; bila biaya
    aktual tidak tersedia, gunakan kebijakan estimasi konservatif yang
    ditampilkan jelas.
8.  Pengguna melihat saldo dan riwayat penggunaan.
9.  Untuk isi ulang tahap awal, pengguna memilih nominal dan metode yang tersedia, lalu sistem membuat invoice unik berstatus pending dengan batas waktu.
10. Untuk metode manual, pengguna mengikuti instruksi pembayaran dan dapat mengirim referensi/bukti transaksi; bukti hanya membantu pemeriksaan, bukan bukti final.
11. Bot Telegram privat memberi admin ringkasan invoice dan tombol Setujui/Tolak. Admin mencocokkan transaksi dengan mutasi/riwayat resmi sebelum menyetujui.
12. Backend memvalidasi admin Telegram yang diizinkan, status invoice, nominal, dan idempotency; saldo serta ledger diperbarui secara atomik.
13. Setelah akses QRIS dinamis DANA resmi tersedia, sistem membuat QR per invoice dan memproses webhook/status API terverifikasi dengan deduplikasi. Redirect browser atau screenshot tidak pernah dianggap bukti pembayaran.

## 4. Persyaratan Fungsional

### P0 --- wajib untuk MVP

-   Registrasi publik, login/logout, verifikasi email, reset password.
-   Session cookie aman (`HttpOnly`, `Secure` di produksi,
    `SameSite=Lax` atau lebih ketat).
-   Playground chat respons biasa dan streaming SSE.
-   Daftar model yang aktif dengan label provider, konteks, harga
    input/output, dan status.
-   API key: buat, tampilkan sekali, revoke; simpan hash, bukan
    plaintext.
-   Endpoint API kompatibel OpenAI untuk chat completions dan models.
-   Wallet kredit dalam integer unit terkecil; jangan menggunakan
    floating-point untuk uang.
-   Riwayat penggunaan, transaksi, pembayaran, dan status.
-   Top-up manual sebagai metode awal, dengan invoice unik, nominal tetap, kedaluwarsa, status review, dan keputusan admin yang diaudit.
-   Bot Telegram privat untuk notifikasi invoice dan aksi Setujui/Tolak; akses dibatasi berdasarkan Telegram user ID allowlist dan backend tetap menjadi sumber kebenaran.
-   Penyimpanan bukti pembayaran di private storage dengan batas tipe/ukuran; jangan menyimpan bukti di URL publik. Bukti gambar tidak otomatis memicu kredit.
-   Adapter pembayaran yang dapat diperluas untuk QRIS dinamis DANA melalui API resmi. Integrasi produksi hanya aktif setelah kelayakan merchant, kredensial, callback/webhook, dan tes status transaksi berhasil diverifikasi.
-   Webhook pembayaran dengan verifikasi tanda tangan/token sesuai dokumentasi gateway, idempotensi, validasi nominal/mata uang/status, dan proteksi replay.
-   Reservasi kredit dan settlement yang aman untuk request paralel.
-   Rate limit per user/API key/IP dan batas request/token.
-   Admin minimal dengan role-based access control.
-   Kill switch untuk provider/model dan batas pengeluaran.
-   Audit log untuk tindakan admin dan perubahan saldo.
-   Health check, logging terstruktur, backup/export database, dan
    halaman status error yang aman.

### P1 --- setelah MVP stabil

-   Paket langganan, kupon, referral, penggunaan organisasi.
-   Provider fallback dan routing berbasis harga/latency.
-   Ekspor CSV, notifikasi saldo rendah, dan dashboard metrik lebih
    lengkap.
-   Dukungan refund otomatis dan rekonsiliasi pembayaran terjadwal.

## 5. Arsitektur

``` text
Browser
  ├── React + Vite (static frontend)
  └── HTTPS API
        └── Oracle VM: Caddy -> FastAPI
              ├── Auth/session & business logic
              ├── Chat orchestration / provider adapters
              ├── Credit reservation & settlement
              └── Cloudflare Worker internal API
                    └── Cloudflare D1

FastAPI -> AI provider(s) over HTTPS
FastAPI -> Payment gateway API over HTTPS
Payment gateway -> FastAPI webhook endpoint over HTTPS
```

### Komponen

-   Frontend: React + TypeScript + Vite.
-   Backend: Python 3.12+ dan FastAPI.
-   Database: Cloudflare D1.
-   D1 access: Cloudflare Worker dengan binding D1. FastAPI tidak
    mengakses D1 secara langsung; ia memanggil Worker private API dengan
    autentikasi service-to-service.
-   Gateway Worker: tidak menerima request publik untuk operasi internal
    kecuali endpoint webhook yang sengaja dipisahkan; validasi
    `Authorization: Bearer <D1_SERVICE_TOKEN>`, schema, ukuran body, dan
    allowlist route.
-   Reverse proxy/TLS: Caddy pada Oracle atau konfigurasi HTTPS yang
    setara.
-   Payment: implementasi adapter dengan satu gateway pertama (misalnya
    Midtrans Snap dalam sandbox terlebih dahulu); pilihan gateway harus
    dikonfirmasi berdasarkan kelayakan akun bisnis, metode pembayaran,
    biaya, dan syarat merchant. Jangan hard-code provider ke business
    logic.
-   Secrets: environment variables/secret store server-side; tidak masuk
    Git, frontend, D1, atau logs.

### Catatan D1

D1 adalah SQLite serverless dan diakses melalui Workers binding, bukan
koneksi SQLite remote biasa. Semua operasi yang perlu atomik harus
dilakukan dalam satu Worker request/transaction sesuai kemampuan D1.
Jangan membuat pola read saldo -\> update saldo yang terpisah tanpa
transaksi/conditional update.

## 6. Model Data D1

Semua timestamp UTC ISO-8601. Nominal uang disimpan sebagai integer
dalam satuan terkecil mata uang (IDR rupiah sebagai integer; kredit
internal integer). Gunakan foreign keys dan indeks. Migration berurutan
dan dapat diulang dengan aman.

### Tabel inti

-   `users`: id, email_normalized UNIQUE, password_hash,
    email_verified_at, status, role, created_at, updated_at.
-   `sessions`: id, user_id, token_hash UNIQUE, expires_at, created_at,
    last_seen_at.
-   `api_keys`: id, user_id, name, key_prefix, key_hash UNIQUE,
    last_used_at, revoked_at, created_at.
-   `providers`: id, slug UNIQUE, display_name, enabled,
    commercial_use_approved, terms_checked_at, created_at, updated_at.
    Tidak menyimpan secret provider.
-   `models`: id, public_id UNIQUE, provider_id, upstream_model,
    enabled, input_price_per_million, output_price_per_million,
    currency, max_input_tokens, max_output_tokens, supports_streaming,
    pricing_version, updated_at.
-   `wallets`: user_id PRIMARY KEY, balance_credits INTEGER NOT NULL
    CHECK(balance_credits \>= 0), updated_at.
-   `credit_ledger`: id, user_id, event_type, amount_credits signed
    INTEGER, balance_after, reference_type, reference_id,
    idempotency_key UNIQUE, metadata_json, created_at.
-   `credit_reservations`: id, user_id, request_id UNIQUE,
    reserved_credits, settled_credits, status (`reserved`, `settled`,
    `released`, `needs_reconciliation`), expires_at, created_at,
    updated_at.
-   `usage_events`: id, request_id UNIQUE, user_id, api_key_id nullable,
    model_id, provider_id, input_tokens, output_tokens,
    estimated_cost_minor, billed_credits, latency_ms, status, error_code
    nullable, streaming, created_at. Jangan simpan prompt/response
    secara default.
-   `topups`: id, user_id, order_id UNIQUE, payment_provider, payment_method (`manual_transfer`, `manual_qris`, `dana_qris_dynamic`), provider_reference nullable, amount_minor, currency, credits_to_grant, status (`pending`, `under_review`, `paid`, `rejected`, `failed`, `expired`, `refunded`), proof_file_key nullable, reviewed_by nullable, reviewed_at nullable, rejection_reason nullable, idempotency_key UNIQUE, paid_at nullable, expires_at, created_at, updated_at.
-   `payment_webhook_events`: id, gateway, event_id, payload_hash,
    processing_status, received_at, processed_at, error_code.
    UNIQUE(gateway, event_id) jika gateway menyediakan event ID stabil;
    bila tidak, buat kunci deduplikasi berdasarkan referensi dan status
    transaksi.
-   `admin_audit_logs`: id, admin_user_id, action, target_type,
    target_id, metadata_json, created_at.
-   `rate_limit_buckets` atau mekanisme rate limit setara: key,
    window_start, count, expires_at. Jika D1 terlalu sering ditulis
    untuk rate limiting, gunakan limiter di memori untuk soft protection
    dan mekanisme edge/Worker untuk rate limit yang lebih kuat; jangan
    mengandalkan in-memory lintas worker sebagai kontrol tunggal.

### Aturan integritas

-   `balance_credits` tidak boleh negatif.
-   Setiap perubahan saldo harus memiliki satu entri ledger dalam
    operasi atomik yang sama.
-   Webhook berulang tidak boleh menambah kredit dua kali.
-   `topups` yang sudah `paid` tidak boleh dibayar ulang atau diberi
    kredit ulang.
-   Setiap `request_id` hanya boleh settle sekali.
-   Harga model memiliki versi agar audit historis tetap benar.
-   Pengubahan saldo oleh admin wajib punya alasan dan audit log.
-   Saldo dan ledger harus dapat direkonsiliasi.

## 7. Kontrak API

Semua endpoint JSON kecuali streaming. Prefix `/api/v1` untuk dashboard,
`/v1` untuk API kompatibel OpenAI. API publik memakai HTTPS.

### Auth/dashboard

-   `POST /api/v1/auth/register`
    -   Request: `{ "email": "...", "password": "..." }`
    -   Response:
        `201 { "user": { "id": "...", "email": "...", "email_verified": false } }`
    -   Rate limited; jangan membocorkan apakah email sudah terdaftar.
-   `POST /api/v1/auth/login`
    -   Request: `{ "email": "...", "password": "..." }`
    -   Response: `200 { "user": { ... } }`; session cookie HttpOnly.
-   `POST /api/v1/auth/logout`
-   `POST /api/v1/auth/verify-email`
-   `POST /api/v1/auth/forgot-password`
-   `POST /api/v1/auth/reset-password`
-   `GET /api/v1/me`
-   `GET /api/v1/wallet`
    -   Response:
        `{ "balance_credits": 12000, "currency": "IDR", "updated_at": "..." }`
-   `GET /api/v1/usage?cursor=...&limit=...`
-   `GET /api/v1/transactions?cursor=...&limit=...`
-   `POST /api/v1/api-keys` → plaintext key ditampilkan sekali saja.
-   `GET /api/v1/api-keys` → hanya prefix dan metadata.
-   `DELETE /api/v1/api-keys/{id}` → revoke.

### Model dan chat

-   `GET /v1/models`
    -   Response kompatibel:
        `{ "object": "list", "data": [{ "id": "router/model-id", "object": "model" }] }`
-   `POST /v1/chat/completions`
    -   Header: `Authorization: Bearer <api_key>`,
        `Content-Type: application/json`.
    -   Request minimum:
        `{ "model": "router/model-id", "messages": [{"role":"user","content":"Halo"}], "stream": false, "max_tokens": 512 }`
    -   Response non-stream mengikuti bentuk OpenAI Chat Completions:
        `id`, `object`, `created`, `model`, `choices`, `usage`.
    -   Streaming memakai `text/event-stream`, event chunk kompatibel,
        dan terminator `data: [DONE]`.
    -   Tolak parameter yang tidak didukung dengan error jelas; jangan
        mengklaim kompatibilitas fitur yang belum ada.
-   `GET /v1/usage` (opsional untuk API key; dashboard tetap memakai
    endpoint internal) mengembalikan pemakaian pemilik key.

### Top-up dan pembayaran

#### Tahap awal: pembayaran manual + bot Telegram

- `POST /api/v1/topups` membuat invoice unik. Nominal top-up dan kredit yang diberikan dihitung/ditentukan server dari konfigurasi, bukan dipercaya dari klien. Setiap invoice memiliki `expires_at` dan instruksi pembayaran yang dikonfigurasi admin.
- Tambahkan endpoint terautentikasi untuk mengirim bukti/referensi pembayaran, melihat status invoice, dan membatalkan invoice yang masih pending bila diizinkan. File bukti disimpan privat, dengan validasi MIME sebenarnya, batas ukuran, nama acak, dan akses admin saja.
- Bot Telegram hanya untuk notifikasi dan tindakan admin; jangan menjadikan Telegram sebagai database atau sumber status pembayaran. Simpan token bot di secret server-side.
- Batasi tindakan bot dengan allowlist Telegram user ID admin. Callback Setujui/Tolak harus diverifikasi di backend, bukan dipercaya dari teks tombol. Jangan pernah menambah saldo hanya karena pengguna mengunggah screenshot. Admin harus memeriksa transaksi aktual pada aplikasi/portal pembayaran resmi.
- Persetujuan mengubah status invoice, wallet, dan `credit_ledger` secara atomik. Terapkan idempotency agar klik ganda, callback berulang, atau retry tidak menggandakan kredit. Simpan actor, waktu, dan alasan penolakan pada audit log.
- Jangan meminta kredensial internet banking, melakukan scraping aplikasi bank, atau menganggap QRIS statis dapat direkonsiliasi otomatis. Gunakan hanya rekening/QRIS yang secara resmi boleh menerima pembayaran usaha ini.

#### Tahap berikutnya: QRIS dinamis DANA resmi

- Pemilik saat ini baru memiliki akun DANA pribadi biasa. Sebelum integrasi, pemilik perlu memeriksa kelayakan upgrade DANA Premium, mendaftar DANA Bisnis dengan data usaha yang benar, lalu meminta konfirmasi tertulis dari DANA mengenai kelayakan akun untuk QRIS dinamis/API merchant dan webhook.
- Status DANA Bisnis atau kepemilikan QRIS saja tidak membuktikan bahwa akses API dinamis tersedia. Jangan menganggap API production, sandbox, kredensial, atau webhook tersedia sebelum dikonfirmasi oleh DANA.
- Setelah akses resmi disetujui, implementasikan adapter DANA untuk membuat QR per invoice, memeriksa status transaksi melalui API resmi, dan memvalidasi notifikasi server-to-server sesuai dokumentasi DANA. Cocokkan referensi invoice, nominal, mata uang, merchant, status final, serta waktu kedaluwarsa. Deduplikasi event dan lakukan rekonsiliasi status bila callback ambigu.
- Jangan memakai QRIS pihak lain, menyamarkan kategori usaha, atau mengaktifkan metode pembayaran yang melanggar syarat penyedia. Bila akses API belum disetujui, tetap gunakan alur manual yang diaudit.


-   `POST /api/v1/topups`
    -   Request: `{ "amount_minor": 50000, "currency": "IDR" }`
    -   Server menentukan `credits_to_grant` dari konfigurasi harga,
        bukan dari input klien.
    -   Response:
        `{ "topup_id": "...", "checkout_url": "...", "status": "pending" }`
-   `GET /api/v1/topups/{id}`
    -   Status yang ditampilkan berasal dari server; redirect browser
        bukan bukti pembayaran.
-   `POST /api/v1/webhooks/{gateway}`
    -   Terima payload provider. Verifikasi autentisitas sesuai
        dokumentasi gateway, simpan/deduplikasi event, validasi order
        ID, nominal, mata uang, status final, lalu kreditkan saldo
        atomik.
    -   Balas 2xx cepat setelah event tercatat dan proses yang aman
        terjamin. Untuk implementasi MVP, proses sinkron boleh jika
        cepat; jika butuh queue, gunakan pola durable yang benar, bukan
        background task in-memory.

### Error envelope

`{ "error": { "code": "insufficient_credits", "message": "Saldo kredit tidak mencukupi.", "request_id": "req_..." } }`

Kode minimum: `invalid_request`, `unauthorized`, `forbidden`,
`rate_limit_exceeded`, `insufficient_credits`, `model_unavailable`,
`provider_timeout`, `provider_error`, `payment_pending`,
`payment_verification_failed`, `internal_error`.

## 8. Harga, Kredit, dan Pengendalian Biaya

-   Harga provider disimpan sebagai harga input/output per satu juta
    token dengan `pricing_version`.
-   Semua perhitungan memakai Decimal/integer; hindari float untuk uang.
-   Buat satu fungsi teruji untuk konversi biaya upstream menjadi kredit
    jual.
-   Tetapkan margin minimum dan buffer biaya kurs/fee.
-   Reservasi kredit maksimum berdasarkan `max_tokens` dan estimasi
    input sebelum memanggil provider.
-   Jika biaya aktual tersedia, settle berdasarkan pemakaian aktual.
    Jika tidak tersedia, gunakan estimasi konservatif yang
    terdokumentasi dan tandai sebagai estimasi.
-   Jika biaya aktual melebihi reservasi, batasi output atau gunakan
    aturan settlement yang tidak membuat saldo negatif; catat selisih
    untuk rekonsiliasi.
-   Hard limit per request, per user, per API key, per hari, dan global.
-   Admin dapat mematikan provider/model dan menghentikan penjualan
    top-up.
-   Kuota gratis provider tidak boleh diasumsikan stabil atau boleh
    dijual ulang.
-   Jangan menawarkan unlimited pada MVP.
-   Default: model berbayar tidak aktif sampai billing upstream, harga,
    dan commercial terms diverifikasi.

## 9. Keamanan dan Privasi

-   Password hash dengan Argon2id atau algoritma password hashing kuat
    yang didukung library terawat.
-   Session cookie HttpOnly, Secure, SameSite; rotasi session setelah
    login; CSRF protection untuk aksi berbasis cookie.
-   API key minimal 256-bit random; simpan hash dengan constant-time
    comparison bila relevan; key hanya ditampilkan sekali.
-   TLS end-to-end; batasi port Oracle; SSH key-only; non-root
    deployment; update OS.
-   Rahasia provider/payment/service-token di environment server; rotasi
    dan redaksi log.
-   Worker internal memverifikasi service token, memvalidasi schema dan
    ukuran body, serta tidak membuka operasi admin ke publik.
-   Webhook diverifikasi sesuai mekanisme resmi gateway. Jangan hanya
    percaya IP atau parameter browser.
-   Idempotensi untuk top-up, webhook, ledger, dan request completion.
-   Rate limit registrasi, login, password reset, chat, pembuatan
    top-up, dan endpoint admin.
-   Proteksi SSRF: backend hanya memanggil base URL provider yang
    terdaftar; pengguna tidak boleh menentukan URL upstream.
-   CORS allowlist; CSP dan security headers.
-   Batasi ukuran body, jumlah pesan, panjang prompt, output token,
    timeout, dan concurrency.
-   Redaksi token, API key, prompt sensitif, email, dan data pembayaran
    di log.
-   Kebijakan retensi dan penghapusan akun/data yang jelas.
-   Tampilkan pemberitahuan bahwa prompt dikirim ke provider eksternal
    dan mengikuti kebijakan data provider.
-   Jangan menyimpan data kartu; gunakan hosted checkout/tokenisasi
    payment gateway.
-   Admin dengan MFA bila memungkinkan, least privilege, dan audit log.

## 10. Deployment Gratis

### Oracle

-   Buat VM Ampere A1 Ubuntu ARM64 di home region dan pastikan semua
    resource berlabel Always Free eligible.
-   Instal Docker Engine + Compose atau deploy systemd; pilih satu pola,
    jangan campur tanpa alasan.
-   Jalankan FastAPI di jaringan internal container; Caddy menjadi
    reverse proxy HTTPS.
-   Firewall hanya membuka SSH yang dibatasi dan 80/443; jangan expose
    port aplikasi/database.
-   Gunakan domain/subdomain, misalnya `api.example.com`; domain
    berbayar tidak termasuk asumsi biaya nol.
-   Jalankan health endpoint `/health/live` dan `/health/ready`.
-   Batasi log, aktifkan rotasi, dan backup konfigurasi/database D1.

### Cloudflare

-   Buat D1 database dan jalankan migrations via Wrangler.
-   Deploy Worker API data dengan D1 binding.
-   Simpan `D1_SERVICE_TOKEN` sebagai Worker secret dan sebagai
    environment secret di Oracle.
-   Frontend React/Vite dapat di-host pada Cloudflare Pages atau static
    hosting yang tersedia pada akun; jangan menganggap seluruh fitur
    platform gratis tanpa memeriksa kuota terkini.
-   DNS diarahkan ke layanan yang dipilih; uji TLS dan webhook dari
    gateway sandbox.

### CI/CD

-   GitHub Actions untuk lint, type-check, unit test, build, dan deploy
    hanya setelah secrets dikonfigurasi.
-   Tidak menyimpan secret di repository.
-   Migration database harus memiliki langkah staging/sandbox dan
    prosedur rollback/forward-fix.
-   Simpan backup source, migrations, dan ekspor data penting di lokasi
    terpisah.

### Biaya

Target Rp0 hanya untuk infrastruktur yang tetap berada di kuota gratis.
Token AI, payment gateway, domain, email transaksional, backup
eksternal, atau penggunaan yang melampaui kuota dapat berbiaya. Oracle
Always Free dapat mengalami keterbatasan kapasitas atau reclamation
instance idle; bukan SLA produksi.

## 11. Pengujian dan Kriteria Penerimaan

### Unit tests

-   Hash/verify API key dan password.
-   Perhitungan harga, pembulatan, kredit, dan margin.
-   Validasi model, token limit, serta error mapping.
-   Verifikasi webhook valid/tidak valid.
-   Idempotensi ledger/top-up/reservasi.

### Integration tests

-   D1 migrations pada database test.
-   Worker service-token valid/invalid.
-   Provider adapter dengan mock server.
-   Payment gateway sandbox create-checkout dan webhook.
-   Streaming SSE dan disconnect.
-   Session, CSRF, CORS, dan rate limit.

### Security/abuse tests

-   Saldo nol/kurang.
-   Dua request paralel yang bersaing atas saldo sama.
-   Webhook yang sama dikirim berulang.
-   Nominal, currency, atau order ID dimanipulasi.
-   API key dicabut saat digunakan.
-   Provider timeout, error, dan response malformed.
-   Input besar, prompt injection tidak mengubah kontrol sistem, SSRF,
    brute force, dan akses silang antar pengguna.

### Acceptance criteria

1.  Pengguna bisa daftar, login, verifikasi email, dan reset password.
2.  Pengguna dapat mengirim chat dari playground dengan model aktif.
3.  API key dapat digunakan pada endpoint kompatibel OpenAI dan dapat
    dicabut.
4.  Saldo tidak pernah negatif dalam uji paralel.
5.  Webhook palsu/berulang tidak dapat menambah saldo.
6.  Persetujuan top-up manual hanya dapat dilakukan oleh admin Telegram yang diizinkan, setelah verifikasi transaksi aktual; callback duplikat tidak menggandakan kredit.
7.  Kredit hanya diberikan setelah pembayaran terverifikasi dengan nominal dan currency yang cocok, baik melalui pemeriksaan manual terdokumentasi maupun API/webhook resmi.
8.  Pemakaian token, harga model, dan kredit yang dibebankan dapat diaudit.
9.  Provider dapat dimatikan tanpa deploy ulang frontend.
10. Secret tidak ditemukan dalam source map, frontend bundle, atau log.
11. Deployment dapat dilakukan dari README yang diuji pada VM baru.

## 12. Risiko dan Mitigasi

  -----------------------------------------------------------------------
  Risiko                              Mitigasi
  ----------------------------------- -----------------------------------
  Provider melarang resale            Verifikasi terms; adapter tetap
                                      nonaktif sampai disetujui

  Biaya upstream melebihi penjualan   Reservasi, hard cap, margin,
                                      monitoring dan kill switch

  Webhook palsu/replay                Signature/token verification,
                                      idempotensi, cek status via API
                                      jika diperlukan

  D1 mencapai limit harian            Monitoring, backpressure, backup;
                                      jangan auto-upgrade tanpa
                                      persetujuan

  Oracle instance tidak               Backup, dokumentasi restore, target
  tersedia/direklamasi                MVP tanpa SLA

  Abuse akun publik                   Email verification, rate limit,
                                      CAPTCHA bila diperlukan, spending
                                      limits

  Perbedaan usage provider            Adapter menormalisasi usage; tandai
                                      estimasi jika usage tidak tersedia

  Kebocoran data percakapan           Jangan log isi prompt secara
                                      default; retensi terbatas
  -----------------------------------------------------------------------

## 13. Urutan Implementasi

1.  Repository, lint, config, migrations, Worker D1.
2.  Auth/session dan dashboard shell.
3.  API key management.
4.  Provider adapter mock dan `/v1/models`.
5.  Chat non-stream, lalu streaming.
6.  Wallet, ledger, reservation/settlement.
7.  Top-up manual, review admin, bot Telegram privat, ledger atomik, dan audit trail.
8.  Adapter pembayaran sandbox/mock dan tes webhook; integrasi DANA dinamis menjadi fase lanjutan setelah kelayakan merchant/API dikonfirmasi.
9.  Admin panel, rate limits, hard caps.
10. Security tests, observability, deployment.
11. Pilot tertutup; setelah metrik stabil dan proses pembayaran terbukti, baru buka pendaftaran publik luas.

## 14. Keputusan yang harus dikonfirmasi sebelum produksi

-   Nama produk dan domain.
-   Kelayakan akun DANA Bisnis dan akses QRIS dinamis/API/webhook; metode pembayaran manual yang sah untuk tahap awal.
-   Telegram user ID admin yang diizinkan dan tata cara verifikasi transaksi manual.
-   Provider AI yang mengizinkan integrasi komersial/resale.
-   Formula kredit, harga jual, margin minimum, dan kebijakan refund.
-   Kebutuhan verifikasi email dan kebijakan privasi/retensi.
-   Batas anggaran upstream harian dan bulanan.
