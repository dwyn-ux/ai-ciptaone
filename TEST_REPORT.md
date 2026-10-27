# Laporan Pengujian & Perbandingan Hasil Migrasi

Dokumen ini menyajikan hasil pengujian komprehensif migrasi backend AI Router dari **Python 3.12+ (FastAPI + SQLite WAL)** ke **PHP 8.x (MySQL/MariaDB)** untuk target shared hosting cPanel.

---

## 1. Ringkasan Pengujian Komponen

Semua modul logika bisnis, autentikasi, integritas keuangan, dan integrasi upstream telah diuji menggunakan test suite mandiri (`php-backend/tests/run_tests.php` dan `php-backend/tests/web_runner.php`):

| No | Modul Pengujian | Status | Aspek Kritis yang Diverifikasi |
| :---: | :--- | :---: | :--- |
| **1** | **Autentikasi & Sesi** | ✅ PASSED | Hashing password (Argon2id/Bcrypt), normalisasi email, pencegahan enumerasi akun pada registrasi duplikat, session rotation, dan pencabutan sesi (logout). |
| **2** | **Manajemen API Key** | ✅ PASSED | Format kunci `sk-live-...`, hashing SHA-256 (plaintext hanya ditampilkan sekali), validasi token konstan waktu, dan pencegahan penggunaan kunci yang dicabut. |
| **3** | **Dompet & Ledger Atomik** | ✅ PASSED | Saldo awal otomatis, reservasi kredit pra-request, pengembalian sisa kuota saat settlement, rekonsiliasi double-entry ledger, penolakan saldo negatif, dan pencegahan kredit ganda (idempotensi). |
| **4** | **AI Router & Chat Completion** | ✅ PASSED | Matematika pembulatan token (`ceil`) dengan biaya minimum 1 kredit, format OpenAI `/v1/models`, respons non-streaming, chunking SSE streaming dengan terminasi `[DONE]`, dan pencatatan riwayat pemakaian tanpa menyimpan prompt/respons per PRD. |
| **5** | **Top-up Manual & Bot Telegram** | ✅ PASSED | Pembuatan invoice unik (`INV-...`), validasi nominal minimal Rp10.000, upload bukti transfer privat (validasi MIME/ukuran), notifikasi tombol inline bot Telegram, allowlist admin Telegram ID, dan penolakan callback tidak sah. |
| **6** | **Adapter Pembayaran (Manual & DANA)** | ✅ PASSED | Instruksi transfer bank & QRIS manual, proteksi adapter DANA yang dinonaktifkan (`DANA_ENABLED=false`) sebelum akun merchant dan akses API resmi disetujui. |
| **7** | **Admin Control & Audit Trail** | ✅ PASSED | Otorisasi role admin, kill switch penonaktifan model/provider, penyesuaian saldo pengguna dengan pencatatan alasan wajib pada `admin_audit_logs`. |
| **8** | **Keamanan, CSRF & Rate Limiting** | ✅ PASSED | Proteksi CSRF berbasis header `Origin`/`Referer` pada request mutasi cookie, sliding window rate limiter berbasis database MySQL (`rate_limit_buckets`) tanpa dependensi Redis. |

---

## 2. Matriks Perbandingan: Python vs PHP Shared Hosting

| Komponen Arsitektur | Implementasi Lama (Python FastAPI) | Implementasi Baru (PHP 8.x Shared Hosting) | Dampak & Kompatibilitas |
| :--- | :--- | :--- | :--- |
| **Runtime Server** | Uvicorn / ASGI daemon permanen (Membutuhkan VPS / Python App) | PHP-FPM / LiteSpeed Web Server bawaan cPanel | Berjalan pada shared hosting termurah tanpa proses daemon |
| **Database Engine** | Cloudflare D1 / SQLite WAL | MySQL 8.0+ / MariaDB 10.4+ (InnoDB) | Sesuai standar database cPanel; mendukung transaksi atomik & foreign keys |
| **Penegakan Saldo** | SQLite `CHECK(balance_credits >= 0)` | MySQL Conditional `UPDATE ... WHERE balance_credits >= :cost` + `CHECK` | Menjamin saldo tidak pernah negatif dalam request paralel |
| **HTTP Client Provider** | `httpx.AsyncClient` | Native PHP `cURL` dengan SSL verification | Zero-dependency; efisien dan didukung penuh pada semua hosting |
| **Streaming SSE** | FastAPI `StreamingResponse` | PHP `Response::startSse()` + output flushing chunk-by-chunk | Streaming real-time OpenAI-compatible dengan penonaktifan buffer gzip |
| **Telegram Integration** | Polling loop atau webhook | Webhook murni (`/api/v1/webhooks/telegram`) dengan validasi secret header | Sangat hemat resource; tidak membutuhkan background worker |
| **Rate Limiting** | In-memory threading lock | Database Table `rate_limit_buckets` dengan pembersihan otomatis | Bekerja lintas worker PHP-FPM tanpa memerlukan Redis |
| **Penyimpanan Bukti** | Direktori lokal privat | Direktori privat di luar web root / dilindungi `.htaccess` | File bukti transfer terlindungi dari akses publik langsung |

---

## 3. Keterbatasan Shared Hosting & Mitigasi yang Diterapkan

1. **Batas Waktu Eksekusi (`max_execution_time`):**
   - *Keterbatasan:* Shared hosting biasanya membatasi eksekusi skrip PHP antara 30 hingga 60 detik. Model reasoning/deep thinking dengan prompt panjang mungkin melebihi batas ini jika upstream lambat.
   - *Mitigasi:* Pada endpoint streaming SSE (`/v1/chat/completions`), server otomatis memanggil `@set_time_limit(0)` dan `@ini_set('max_execution_time', '0')` jika hosting mengizinkannya. Untuk cPanel, disarankan menaikkan `max_execution_time` ke `120` pada menu *Select PHP Version -> Options*.
2. **Buffer Kompresi Web Server (mod_deflate / LiteSpeed):**
   - *Keterbatasan:* Beberapa web server shared hosting melakukan buffering sebelum mengirim data ke browser untuk mengompres gzip, yang dapat menyebabkan respons streaming SSE tertahan dan muncul sekaligus di akhir.
   - *Mitigasi:* File `.htaccess` menyertakan direktif `SetEnv no-gzip 1` dan `RewriteRule .* - [E=nobuf:1]` untuk menonaktifkan buffering pada endpoint API.
3. **Ketiadaan Background Queue Worker (Celery/Redis):**
   - *Keterbatasan:* Shared hosting tidak mengizinkan proses supervisor atau daemon worker berjalan terus-menerus di background.
   - *Mitigasi:* Seluruh pemrosesan (notifikasi Telegram, settlement kredit, logging audit) dijalankan secara sinkron namun ringan dan cepat (<15ms overhead) memanfaatkan prepared statements MySQL dan koneksi cURL non-blocking.
4. **Integrasi QRIS DANA Dinamis:**
   - *Keterbatasan:* Akun DANA Bisnis dan akses API resmi belum disetujui.
   - *Mitigasi:* Sesuai Aturan Utama No. 11, adapter `DanaPaymentAdapter` telah dipersiapkan strukturnya namun dinonaktifkan secara aman (`DANA_ENABLED=false`). Pembayaran tahap awal berjalan menggunakan alur top-up manual yang diverifikasi oleh admin via bot Telegram terhadap mutasi rekening resmi.
