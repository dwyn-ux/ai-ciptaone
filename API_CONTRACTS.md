# Daftar Kontrak Endpoint API yang Dipertahankan

Seluruh endpoint pada backend baru (PHP 8.x) mempertahankan kontrak input, output JSON envelope, status code HTTP, format cookie, dan kompatibilitas OpenAI yang sebelumnya berjalan pada backend Python FastAPI.

---

## 1. Autentikasi & Sesi (`/api/v1/auth`)

| Metode | Endpoint | Autentikasi | Deskripsi & Payload | Respons |
| :--- | :--- | :--- | :--- | :--- |
| `POST` | `/api/v1/auth/register` | Publik | `{ "email": "...", "password": "..." }` | `201 Created`: `{ "user": { "id": "usr_...", "email": "...", "role": "user", ... } }` |
| `POST` | `/api/v1/auth/login` | Publik | `{ "email": "...", "password": "..." }` | `200 OK`: Menetapkan cookie `ciptaone_session` (`HttpOnly`, `SameSite=Lax`) |
| `POST` | `/api/v1/auth/logout` | Session | Kosong | `200 OK`: Menghapus sesi di DB & kedaluwarsa cookie |
| `POST` | `/api/v1/auth/verify-email` | Publik | `{ "token": "..." }` | `200 OK` |
| `POST` | `/api/v1/auth/forgot-password`| Publik | `{ "email": "..." }` | `200 OK` (Pesan aman tanpa enumeration) |
| `POST` | `/api/v1/auth/reset-password` | Publik | `{ "token": "...", "new_password": "..." }` | `200 OK` |

---

## 2. Profil Pengguna & Saldo Dompet (`/api/v1`)

| Metode | Endpoint | Autentikasi | Parameter Query | Format Respons |
| :--- | :--- | :--- | :--- | :--- |
| `GET` | `/api/v1/me` | Sesi / Bearer | Tidak ada | Profil user yang sedang aktif |
| `GET` | `/api/v1/wallet` | Sesi / Bearer | Tidak ada | `{ "balance_credits": 50000, "currency": "IDR", "updated_at": "..." }` |
| `GET` | `/api/v1/transactions` | Sesi / Bearer | `limit=20&offset=0` | Riwayat mutasi double-entry ledger saldo |
| `GET` | `/api/v1/usage` | Sesi / Bearer | `limit=20&offset=0` | Riwayat pemakaian token AI & kredit yang didebet |

---

## 3. Manajemen API Key Pengguna (`/api/v1/api-keys`)

| Metode | Endpoint | Autentikasi | Deskripsi | Respons |
| :--- | :--- | :--- | :--- | :--- |
| `POST` | `/api/v1/api-keys` | Sesi | `{ "name": "Produksi" }` | `201 Created`: Plaintext key (`sk-live-...`) ditampilkan **hanya sekali** |
| `GET` | `/api/v1/api-keys` | Sesi | Daftar key aktif pengguna | Hanya menampilkan `key_prefix` (`sk-live-XXXXXX...`) |
| `DELETE` | `/api/v1/api-keys/{key_id}` | Sesi | Mencabut API key | `{ "status": "success", "message": "API key berhasil dicabut." }` |

---

## 4. OpenAI-Compatible Gateway (`/v1`)

### A. Daftar Model
`GET /v1/models`  
Header: `Authorization: Bearer <api_key>` (atau Session Cookie)  
Respons:
```json
{
  "object": "list",
  "data": [
    {
      "id": "mock/echo-v1",
      "object": "model",
      "created": 1714000000,
      "owned_by": "AI Ciptaone"
    }
  ]
}
```

### B. Chat Completion (Non-Streaming & Streaming SSE)
`POST /v1/chat/completions`  
Header: `Authorization: Bearer <api_key>` (atau Session Cookie), `Content-Type: application/json`  
Payload:
```json
{
  "model": "mock/echo-v1",
  "messages": [
    {"role": "user", "content": "Halo AI Router"}
  ],
  "stream": false,
  "max_tokens": 1024,
  "temperature": 0.7
}
```

- **Non-Streaming (`stream: false`):**  
  Respons `application/json`:
  ```json
  {
    "id": "req_123456789abc",
    "object": "chat.completion",
    "created": 1714000000,
    "model": "mock/echo-v1",
    "choices": [
      {
        "index": 0,
        "message": { "role": "assistant", "content": "..." },
        "finish_reason": "stop"
      }
    ],
    "usage": {
      "prompt_tokens": 15,
      "completion_tokens": 25,
      "total_tokens": 40
    }
  }
  ```

- **Streaming SSE (`stream: true`):**  
  Respons `text/event-stream; charset=utf-8`:
  ```text
  data: {"id":"req_123","object":"chat.completion.chunk","created":1714000000,"model":"mock/echo-v1","choices":[{"index":0,"delta":{"role":"assistant","content":"Halo"},"finish_reason":null}]}

  data: {"id":"req_123","object":"chat.completion.chunk","created":1714000000,"model":"mock/echo-v1","choices":[{"index":0,"delta":{"content":" dunia"},"finish_reason":null}]}

  data: {"id":"req_123","object":"chat.completion.chunk","created":1714000000,"model":"mock/echo-v1","choices":[{"index":0,"delta":{},"finish_reason":"stop"}]}

  data: [DONE]
  ```

---

## 5. Top-up & Pembayaran (`/api/v1/topups`)

| Metode | Endpoint | Autentikasi | Deskripsi | Respons |
| :--- | :--- | :--- | :--- | :--- |
| `POST` | `/api/v1/topups` | Sesi | `{ "amount_minor": 50000, "payment_method": "manual_transfer" }` | `201 Created`: Invoice unik `INV-...` dengan instruksi transfer & batas waktu |
| `GET` | `/api/v1/topups` | Sesi | Daftar transaksi top-up pengguna | Array top-up dengan status (`pending`, `under_review`, `paid`, `rejected`) |
| `GET` | `/api/v1/topups/{id}` | Sesi | Status invoice spesifik | Detail invoice dan instruksi pembayaran |
| `POST` | `/api/v1/topups/{id}/proof` | Sesi | `multipart/form-data`: `file` (JPEG/PNG/WEBP maks 5MB) | Menyimpan bukti secara privat dan mengubah status ke `under_review` |

---

## 6. Webhooks (`/api/v1/webhooks`)

| Metode | Endpoint | Validasi Keamanan | Deskripsi |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/v1/webhooks/telegram` | `X-Telegram-Bot-Api-Secret-Token` | Menerima callback tombol `Setujui`/`Tolak` dari bot Telegram privat |
| `POST` | `/api/v1/webhooks/{gateway}` | Signature & Deduplikasi Hash | Webhook gateway pembayaran (misal DANA dynamic QRIS) |

---

## 7. Administrasi Sistem (`/api/v1/admin`)

Khusus pengguna dengan `role = 'admin'`:

| Metode | Endpoint | Deskripsi |
| :--- | :--- | :--- |
| `GET` | `/api/v1/admin/settings` | Ambil status konfigurasi API Key provider & Telegram (masked) |
| `POST` | `/api/v1/admin/settings` | Simpan/update API Key OpenRouter, OpenAI, Bot Telegram dari Web UI |
| `GET` | `/api/v1/admin/models` | Daftar semua model AI beserta harga input/output per 1M token |
| `POST` | `/api/v1/admin/models` | Tambah model AI baru dari Web UI |
| `PATCH` | `/api/v1/admin/models/{id}` | Kill switch model dan update harga jual token |
| `GET` | `/api/v1/admin/providers` | Daftar upstream provider |
| `PATCH` | `/api/v1/admin/providers/{id}` | Toggle status aktif provider |
| `GET` | `/api/v1/admin/topups` | Antrean top-up dengan filter status |
| `POST` | `/api/v1/admin/topups/{order_id}/review` | Persetujuan atau penolakan top-up manual |
| `GET` | `/api/v1/admin/topups/{order_id}/proof` | Menampilkan file bukti transfer privat ke admin |
| `GET` | `/api/v1/admin/users` | Daftar seluruh akun terdaftar dan saldo kreditnya |
| `POST` | `/api/v1/admin/users/balance-adjust` | Penyesuaian saldo manual dengan catatan audit wajib |
| `GET` | `/api/v1/admin/audit-logs` | Jejak audit seluruh tindakan admin |

---

## 8. Pemeriksaan Kesehatan (`/health`)

| Metode | Endpoint | Deskripsi | Respons Sukses |
| :--- | :--- | :--- | :--- |
| `GET` | `/health/live` | Liveness probe proses PHP | `{"status": "ok", "service": "ai-ciptaone-router", "runtime": "php-8.x.x"}` |
| `GET` | `/health/ready` | Readiness probe konektivitas database MySQL | `{"status": "ready", "database": "connected"}` |
