# Panduan Konfigurasi Bot Telegram & Webhook Review Admin

Dokumen ini menjelaskan cara menghubungkan bot Telegram privat dengan AI Router untuk menerima notifikasi top-up dan melakukan persetujuan (approval) mutasi secara aman.

---

## 1. Pembuatan Bot Telegram

1. Buka aplikasi Telegram dan cari akun resmi **@BotFather**.
2. Kirim perintah:
   ```text
   /newbot
   ```
3. Beri nama tampilan bot (contoh: `AI Ciptaone Admin Bot`) dan username bot yang diakhiri `bot` (contoh: `ciptaone_router_admin_bot`).
4. **@BotFather** akan memberikan **HTTP API Token** berupa format:
   ```text
   7123456789:AAFlkjhsdf8923-lsdkjf2389sdf
   ```
5. Simpan token ini dengan aman. **Jangan pernah membagikan atau menyimpan token ini ke git/frontend.**

---

## 2. Mendapatkan Telegram User ID Admin

Demi keamanan, tindakan persetujuan top-up dibatasi secara ketat menggunakan **allowlist Telegram User ID**. Hanya akun yang terdaftar yang dapat memicu kredit saldo.

1. Buka Telegram dan cari bot **@userinfobot**.
2. Kirim pesan apa saja (misalnya `/start`).
3. Bot akan membalas dengan profil Anda, perhatikan baris **Id**:
   ```text
   Id: 123456789
   First: John
   Last: Doe
   Lang: en
   ```
4. Salin angka `Id` tersebut (misalnya `123456789`). Jika admin lebih dari satu orang, kumpulkan ID masing-masing.

---

## 3. Konfigurasi File `.env` Server

Buka file `.env` pada server hosting Anda dan isi variabel berikut:

```env
# Token dari @BotFather
TELEGRAM_BOT_TOKEN=7123456789:AAFlkjhsdf8923-lsdkjf2389sdf

# Daftar ID Admin (pisahkan dengan koma jika lebih dari satu)
TELEGRAM_ADMIN_IDS=123456789,987654321

# Secret string acak untuk memvalidasi request resmi dari Telegram API
TELEGRAM_WEBHOOK_SECRET=token_rahasia_acak_minimal_32_karakter
```

---

## 4. Mendaftarkan Webhook ke Telegram API

Karena server shared hosting tidak dapat menjalankan proses polling daemon (`getUpdates` loop), Telegram akan mengirimkan data callback secara langsung ke server Anda melalui **HTTPS Webhook**.

Jalankan perintah berikut di terminal lokal atau panggil URL berikut melalui browser:

```bash
curl -X POST "https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/setWebhook" \
     -H "Content-Type: application/json" \
     -d '{
       "url": "https://domainanda.com/api/v1/webhooks/telegram",
       "secret_token": "<TELEGRAM_WEBHOOK_SECRET>",
       "allowed_updates": ["callback_query"]
     }'
```

Atau menggunakan URL langsung di browser:
```text
https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/setWebhook?url=https://domainanda.com/api/v1/webhooks/telegram&secret_token=<TELEGRAM_WEBHOOK_SECRET>&allowed_updates=["callback_query"]
```

### Verifikasi Status Webhook
Periksa apakah webhook berhasil terdaftar dengan mengakses:
```text
https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/getWebhookInfo
```

Respons yang sukses:
```json
{
  "ok": true,
  "result": {
    "url": "https://domainanda.com/api/v1/webhooks/telegram",
    "has_custom_certificate": false,
    "pending_update_count": 0,
    "max_connections": 40
  }
}
```

---

## 5. Alur Kerja Persetujuan Top-up

1. **Pengguna Mengajukan Top-up:**  
   Pengguna memilih nominal di dashboard web dan mengunggah bukti pembayaran transfer bank/QRIS.
2. **Notifikasi Masuk ke Telegram Admin:**  
   Bot Telegram privat akan otomatis mengirim pesan ke seluruh admin di `TELEGRAM_ADMIN_IDS`:
   ```text
   🔔 PERMINTAAN TOP-UP BARU

   • Order ID: INV-20261027-A1B2C3
   • Pengguna: user@domain.com
   • Nominal: Rp50.000
   • Kredit: 50.000 kredit
   • Metode: Transfer Bank
   • Bukti Transfer: Sudah diunggah (periksa mutasi resmi!)

   ⚠️ Perhatian: Jangan setujui hanya karena melihat screenshot. Pastikan dana benar-benar masuk pada mutasi rekening resmi.
   ```
   Pesan dilengkapi tombol interaktif: `[✅ Setujui]` dan `[❌ Tolak]`.
3. **Pemeriksaan Mutasi:**  
   Admin memeriksa mutasi rekening bank / QRIS aktual pada aplikasi m-banking atau internet banking resmi.
4. **Tindakan Tombol:**  
   - Admin menekan **"✅ Setujui"**.
   - Server memverifikasi identitas penekan tombol terhadap `TELEGRAM_ADMIN_IDS`.
   - Server menjalankan transaksi atomik:
     1. Status invoice diubah menjadi `paid`.
     2. Saldo kredit ditambahkan ke dompet pengguna.
     3. Catatan mutasi dicatat pada `credit_ledger` dengan `idempotency_key` = `topup_INV-...`.
     4. Log dicatat pada `admin_audit_logs`.
   - Pesan Telegram diperbarui dengan status: `🏁 STATUS: DISETUJUI oleh @usernameadmin`.
   - Notifikasi popup (alert) ditampilkan di layar Telegram admin: *"Order berhasil DISETUJUI dan saldo dikreditkan."*

---

## 6. Fitur Keamanan & Pencegahan Kredit Ganda

- **Verifikasi Header Secret:**  
  Endpoint `/api/v1/webhooks/telegram` memvalidasi header `X-Telegram-Bot-Api-Secret-Token`. Request palsu dari pihak ketiga langsung ditolak dengan kode `401 Unauthorized`.
- **Allowlist Terisolasi:**  
  Callback dari pengguna Telegram yang ID-nya tidak terdaftar pada `TELEGRAM_ADMIN_IDS` ditolak secara instan (`403 Forbidden`).
- **Idempotensi Kredit:**  
  Jika admin menekan tombol "Setujui" dua kali, atau jika Telegram melakukan retry webhook, constraint unik `idempotency_key` pada tabel `credit_ledger` dan status invoice `paid` memastikan saldo **tidak akan pernah dikreditkan ganda**.
- **Audit Trail Lengkap:**  
  Setiap tindakan persetujuan dan penolakan mencatat username Telegram admin, ID, timestamp, dan nominal transaksi ke database.
