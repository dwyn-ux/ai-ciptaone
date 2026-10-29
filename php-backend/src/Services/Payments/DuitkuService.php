<?php
declare(strict_types=1);

namespace Ciptaone\Services\Payments;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Config;
use Ciptaone\Core\Database;
use Ciptaone\Core\ErrorCode;

/**
 * Duitku Payment Gateway Client (Full Service: QRIS, Virtual Accounts, E-Wallet, Retail).
 * Official Bank Indonesia licensed PG adapter.
 */
class DuitkuService
{
    private Config $config;
    private ?Database $db;
    private string $merchantCode;
    private string $apiKey;
    private string $mode;
    private string $callbackUrl;
    private string $returnUrl;

    public function __construct(?Config $config = null, ?Database $db = null)
    {
        $this->config = $config ?? Config::getInstance();
        $this->db = $db ?? Database::getInstance();

        // Load credentials from database system_settings first, then fallback to .env/config
        $this->merchantCode = $this->loadSetting('DUITKU_MERCHANT_CODE');
        $this->apiKey = $this->loadSetting('DUITKU_API_KEY');
        $this->mode = strtolower($this->loadSetting('DUITKU_MODE', 'sandbox'));
        $defaultHost = $_SERVER['HTTP_HOST'] ?? 'ai.ciptaone.site';
        $defaultProto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
        $defaultBase = "{$defaultProto}://{$defaultHost}";

        $rawCb = $this->loadSetting('DUITKU_CALLBACK_URL', "{$defaultBase}/api/v1/webhooks/duitku");
        if ($rawCb === '' || (str_contains($rawCb, 'ciptaone.com') && !str_contains($defaultHost, 'ciptaone.com'))) {
            $rawCb = "{$defaultBase}/api/v1/webhooks/duitku";
        }
        $this->callbackUrl = $rawCb;

        $rawReturn = $this->loadSetting('DUITKU_RETURN_URL', "{$defaultBase}/topup");
        if ($rawReturn === '' || (str_contains($rawReturn, 'ciptaone.com') && !str_contains($defaultHost, 'ciptaone.com'))) {
            $rawReturn = "{$defaultBase}/topup";
        }
        $this->returnUrl = $rawReturn;
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
        return $this->merchantCode !== '' && $this->apiKey !== '';
    }

    public function getMode(): string
    {
        return $this->mode === 'production' ? 'production' : 'sandbox';
    }

    public function getBaseUrl(): string
    {
        return $this->getMode() === 'production'
            ? 'https://passport.duitku.com/webapi/api/merchant/'
            : 'https://sandbox.duitku.com/webapi/api/merchant/';
    }

    public function getCallbackUrl(): string
    {
        return $this->callbackUrl;
    }

    public function getReturnUrl(): string
    {
        return $this->returnUrl;
    }

    /**
     * Create an inquiry transaction on Duitku (V2).
     *
     * @param string $merchantOrderId Unique invoice/order ID (e.g. INV-20261029-XXXX)
     * @param int $amount Amount in Rupiah
     * @param string $customerName Customer display name
     * @param string $customerEmail Customer email
     * @param string $customerPhone Customer phone number
     * @param string $paymentMethod Optional specific channel (e.g. 'SP' for QRIS ShopeePay/Nobu, 'BC' for BCA VA, or '' for all channels / hosted checkout)
     * @param array $items Order item details
     * @return array Normalized response containing payment_url, reference, qr_string, qr_url, va_number, etc.
     */
    public function createInvoice(
        string $merchantOrderId,
        int $amount,
        string $customerName,
        string $customerEmail,
        string $customerPhone = '081234567890',
        string $paymentMethod = '',
        array $items = []
    ): array {
        if (!$this->isConfigured()) {
            throw new AppError(
                ErrorCode::SERVICE_UNAVAILABLE,
                'Payment gateway Duitku belum dikonfigurasi oleh admin.',
                503
            );
        }

        if ($amount < 10000) {
            throw new AppError(
                ErrorCode::INVALID_REQUEST,
                'Nominal transaksi Duitku minimum adalah Rp10.000.',
                400
            );
        }

        $productDetails = "Saldo AI Ciptaone (" . number_format($amount, 0, ',', '.') . " Kredit)";
        $expiryMinutes = (int)$this->config->get('TOPUP_EXPIRY_MINUTES', 60);

        // Signature formula for v2/inquiry: md5(merchantCode + merchantOrderId + paymentAmount + apiKey)
        $signature = md5($this->merchantCode . $merchantOrderId . $amount . $this->apiKey);

        $orderItems = !empty($items) ? $items : [
            [
                'name' => $productDetails,
                'price' => $amount,
                'quantity' => 1,
            ]
        ];

        $channel = (empty($paymentMethod) || in_array($paymentMethod, ['duitku', 'duitku_qris', 'qris'], true)) ? 'SP' : $paymentMethod;

        $payload = [
            'merchantCode' => $this->merchantCode,
            'paymentAmount' => $amount,
            'paymentMethod' => $channel,
            'merchantOrderId' => $merchantOrderId,
            'productDetails' => $productDetails,
            'email' => $customerEmail ?: 'customer@ciptaone.com',
            'phoneNumber' => $customerPhone ?: '081234567890',
            'additionalParam' => '',
            'merchantUserInfo' => $customerEmail,
            'customerVaName' => $customerName ?: 'Pengguna AI Ciptaone',
            'callbackUrl' => $this->getCallbackUrl(),
            'returnUrl' => $this->getReturnUrl(),
            'signature' => $signature,
            'expiryPeriod' => $expiryMinutes,
            'itemDetails' => $orderItems,
        ];

        $endpoint = $this->getBaseUrl() . 'v2/inquiry';
        $response = $this->sendHttpRequest($endpoint, $payload);

        $statusCode = (string)($response['statusCode'] ?? '');
        if ($statusCode !== '00') {
            $msg = (string)($response['statusMessage'] ?? ($response['Message'] ?? ($response['message'] ?? 'Gagal membuat transaksi di Duitku.')));
            $prefix = $statusCode !== '' ? "[{$statusCode}] " : '';
            throw new AppError(ErrorCode::UPSTREAM_ERROR, "Duitku Error: {$prefix}{$msg}", 502);
        }

        $paymentUrl = (string)($response['paymentUrl'] ?? '');
        $reference = (string)($response['reference'] ?? '');
        $vaNumber = (string)($response['vaNumber'] ?? '');
        $qrString = (string)($response['qrString'] ?? '');
        $qrUrl = '';

        if ($qrString !== '') {
            $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=10&data=' . urlencode($qrString);
        } elseif ($paymentUrl !== '') {
            $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=10&data=' . urlencode($paymentUrl);
        }

        return [
            'success' => true,
            'reference' => $reference,
            'merchant_order_id' => $merchantOrderId,
            'payment_url' => $paymentUrl,
            'va_number' => $vaNumber ?: null,
            'qr_string' => $qrString ?: null,
            'qr_url' => $qrUrl ?: null,
            'amount' => (int)($response['amount'] ?? $amount),
            'status_code' => $statusCode,
            'status_message' => (string)($response['statusMessage'] ?? 'SUCCESS'),
            'expired_time' => time() + ($expiryMinutes * 60),
        ];
    }

    /**
     * Verify Callback Signature sent by Duitku.
     * Formula: md5(merchantCode + amount + merchantOrderId + apiKey)
     */
    public function verifyCallbackSignature(
        string $merchantCode,
        string $amount,
        string $merchantOrderId,
        string $receivedSignature
    ): bool {
        if ($this->apiKey === '' || $receivedSignature === '') {
            return false;
        }

        $expected = md5($merchantCode . $amount . $merchantOrderId . $this->apiKey);
        return hash_equals($expected, $receivedSignature);
    }

    /**
     * Parse and verify incoming webhook/callback from Duitku.
     * Accepts either associative array (from $_POST or json_decode).
     */
    public function parseAndVerifyCallback(array $params): array
    {
        $merchantCode = (string)($params['merchantCode'] ?? '');
        $amount = (string)($params['amount'] ?? '0');
        $orderId = (string)($params['merchantOrderId'] ?? '');
        $receivedSignature = (string)($params['signature'] ?? '');
        $resultCode = (string)($params['resultCode'] ?? '');
        $reference = (string)($params['reference'] ?? '');
        $paymentCode = (string)($params['paymentCode'] ?? '');

        if ($orderId === '' || $receivedSignature === '') {
            throw new AppError(ErrorCode::INVALID_REQUEST, 'Data callback Duitku tidak lengkap (merchantOrderId/signature hilang).', 400);
        }

        if (!$this->verifyCallbackSignature($merchantCode, $amount, $orderId, $receivedSignature)) {
            throw new AppError(ErrorCode::UNAUTHORIZED, 'Signature callback Duitku tidak valid (tampering detected).', 401);
        }

        $status = ($resultCode === '00') ? 'paid' : 'failed';

        return [
            'order_id' => $orderId,
            'provider_reference' => $reference,
            'amount' => (int)$amount,
            'status' => $status,
            'result_code' => $resultCode,
            'payment_code' => $paymentCode,
            'raw_data' => $params,
        ];
    }

    /**
     * Check transaction status directly with Duitku server.
     * Formula signature: md5(merchantCode + merchantOrderId + apiKey)
     */
    public function checkTransactionStatus(string $merchantOrderId): array
    {
        if (!$this->isConfigured()) {
            throw new AppError(ErrorCode::SERVICE_UNAVAILABLE, 'Duitku belum dikonfigurasi.', 503);
        }

        $signature = md5($this->merchantCode . $merchantOrderId . $this->apiKey);
        $payload = [
            'merchantCode' => $this->merchantCode,
            'merchantOrderId' => $merchantOrderId,
            'signature' => $signature,
        ];

        $endpoint = $this->getBaseUrl() . 'transactionStatus';
        return $this->sendHttpRequest($endpoint, $payload);
    }

    /**
     * Get list of active payment methods available for the merchant.
     * Formula signature: sha256(merchantCode + amount + datetime + apiKey)
     */
    public function getPaymentMethods(int $amount = 10000): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $datetime = date('Y-m-d H:i:s');
        $signature = hash('sha256', $this->merchantCode . $amount . $datetime . $this->apiKey);

        $payload = [
            'merchantcode' => $this->merchantCode,
            'amount' => $amount,
            'datetime' => $datetime,
            'signature' => $signature,
        ];

        $endpoint = $this->getBaseUrl() . 'paymentmethod/getpaymentmethod';
        try {
            $res = $this->sendHttpRequest($endpoint, $payload);
            return (array)($res['paymentFee'] ?? []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Send HTTP POST request with JSON payload.
     */
    protected function sendHttpRequest(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            throw new AppError(ErrorCode::UPSTREAM_ERROR, "Koneksi ke server Duitku gagal: {$err}", 502);
        }

        $decoded = json_decode((string)$res, true);
        if (!is_array($decoded)) {
            throw new AppError(ErrorCode::UPSTREAM_ERROR, 'Respon dari Duitku bukan JSON valid.', 502);
        }

        return $decoded;
    }
}
