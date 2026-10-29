<?php
declare(strict_types=1);

namespace Ciptaone\Services\Payments;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Config;
use Ciptaone\Core\Database;
use Ciptaone\Core\ErrorCode;

/**
 * Tripay Open Payment Gateway Client:
 * Specializes in Closed Dynamic QRIS generation, signature verification, and callback processing.
 */
class TripayService
{
    private Config $config;
    private ?Database $db;
    private string $apiKey;
    private string $privateKey;
    private string $merchantCode;
    private string $mode;

    public function __construct(?Config $config = null, ?Database $db = null)
    {
        $this->config = $config ?? Config::getInstance();
        $this->db = $db ?? Database::getInstance();

        // Load credentials from database settings first, fallback to config/env
        $this->apiKey = $this->loadSetting('TRIPAY_API_KEY');
        $this->privateKey = $this->loadSetting('TRIPAY_PRIVATE_KEY');
        $this->merchantCode = $this->loadSetting('TRIPAY_MERCHANT_CODE');
        $this->mode = strtolower($this->loadSetting('TRIPAY_MODE', 'sandbox'));
    }

    private function loadSetting(string $key, string $default = ''): string
    {
        // 1. Strict Security Priority: Server environment (.env)
        // Hardcoded in file to protect from database injection or admin tampering
        $envVal = (string)$this->config->get($key, '');
        if (trim($envVal) !== '') {
            return trim($envVal);
        }

        // 2. Non-production fallback (for test suite isolation)
        if ($this->db !== null) {
            try {
                $row = $this->db->fetchOne('SELECT setting_value FROM system_settings WHERE setting_key = ?', [$key]);
                if ($row !== null && trim((string)$row['setting_value']) !== '') {
                    return trim((string)$row['setting_value']);
                }
            } catch (\Throwable) {
                // Table might not exist during early bootstrap
            }
        }
        return $default;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->privateKey !== '' && $this->merchantCode !== '';
    }

    public function getMode(): string
    {
        return $this->mode === 'production' ? 'production' : 'sandbox';
    }

    public function getBaseUrl(): string
    {
        return $this->getMode() === 'production'
            ? 'https://tripay.co.id/api/'
            : 'https://tripay.co.id/api-sandbox/';
    }

    /**
     * Create a dynamic QRIS transaction on Tripay.
     *
     * @param string $merchantRef Our unique invoice/order ID
     * @param int $amount Total amount in Rupiah (min Rp1.000)
     * @param string $customerName Customer display name
     * @param string $customerEmail Customer email
     * @param string $customerPhone Customer phone number (optional)
     * @param array $items List of order items
     * @return array Normalized response containing qr_url, qr_string, reference, expired_time
     */
    public function createQrisTransaction(
        string $merchantRef,
        int $amount,
        string $customerName,
        string $customerEmail,
        string $customerPhone = '081234567890',
        array $items = []
    ): array {
        if (!$this->isConfigured()) {
            throw new AppError(
                ErrorCode::SERVICE_UNAVAILABLE,
                'Payment gateway Tripay belum dikonfigurasi oleh admin.',
                503
            );
        }

        if ($amount < 1000) {
            throw new AppError(
                ErrorCode::INVALID_REQUEST,
                'Nominal transaksi Tripay minimum adalah Rp1.000.',
                400
            );
        }

        $orderItems = !empty($items) ? $items : [
            [
                'name' => "Saldo AI Ciptaone (" . number_format($amount, 0, ',', '.') . " Kredit)",
                'price' => $amount,
                'quantity' => 1,
            ]
        ];

        // Signature: HMAC-SHA256 of merchant_code + merchant_ref + amount
        $signature = hash_hmac('sha256', $this->merchantCode . $merchantRef . $amount, $this->privateKey);
        $expiryMinutes = (int)$this->config->get('TOPUP_EXPIRY_MINUTES', 30);
        $expiredTime = time() + ($expiryMinutes * 60);

        $payload = [
            'method' => 'QRIS',
            'merchant_ref' => $merchantRef,
            'amount' => $amount,
            'customer_name' => $customerName ?: 'Pengguna AI Ciptaone',
            'customer_email' => $customerEmail ?: 'customer@ciptaone.com',
            'customer_phone' => $customerPhone ?: '081234567890',
            'order_items' => $orderItems,
            'expired_time' => $expiredTime,
            'signature' => $signature,
        ];

        $endpoint = $this->getBaseUrl() . 'transaction/create';
        $response = $this->sendHttpRequest($endpoint, $payload);

        if (!isset($response['success']) || $response['success'] !== true) {
            $msg = (string)($response['message'] ?? 'Gagal membuat transaksi di Tripay.');
            throw new AppError(ErrorCode::UPSTREAM_ERROR, "Tripay Error: {$msg}", 502);
        }

        $data = (array)($response['data'] ?? []);

        return [
            'success' => true,
            'reference' => (string)($data['reference'] ?? ''),
            'merchant_ref' => (string)($data['merchant_ref'] ?? $merchantRef),
            'payment_method' => (string)($data['payment_method'] ?? 'QRIS'),
            'amount' => (int)($data['amount'] ?? $amount),
            'total_fee' => (int)($data['total_fee'] ?? 0),
            'status' => (string)($data['status'] ?? 'UNPAID'),
            'qr_url' => (string)($data['qr_url'] ?? ''),
            'qr_string' => (string)($data['qr_string'] ?? ''),
            'checkout_url' => (string)($data['checkout_url'] ?? ''),
            'expired_time' => (int)($data['expired_time'] ?? $expiredTime),
        ];
    }

    /**
     * Verify HMAC-SHA256 signature sent in Tripay webhook header `X-Callback-Signature`.
     */
    public function verifyCallbackSignature(string $rawBody, string $receivedSignature): bool
    {
        if ($this->privateKey === '' || $receivedSignature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $this->privateKey);
        return hash_equals($expected, $receivedSignature);
    }

    /**
     * Send HTTP POST request with Bearer authorization.
     */
    protected function sendHttpRequest(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            throw new AppError(ErrorCode::UPSTREAM_ERROR, "Koneksi ke server Tripay gagal: {$err}", 502);
        }

        $decoded = json_decode((string)$res, true);
        if (!is_array($decoded)) {
            throw new AppError(ErrorCode::UPSTREAM_ERROR, 'Respon dari Tripay bukan JSON valid.', 502);
        }

        return $decoded;
    }
}
