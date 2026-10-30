<?php
declare(strict_types=1);

namespace Ciptaone\Services\PaymentAdapters;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Config;
use Ciptaone\Core\ErrorCode;
use Ciptaone\Core\Security;

/**
 * Adapter for Official DANA Dynamic QRIS / Merchant API.
 *
 * IMPORTANT (PRD Section 7 & 14, Rule 11):
 * This adapter is prepared for the official DANA Dynamic QRIS merchant integration.
 * It remains deactivated until the business merchant account, official API access,
 * client credentials, and webhook endpoints are officially verified by DANA.
 */
class DanaPaymentAdapter extends BasePaymentAdapter
{
    private bool $enabled;
    private string $env;
    private string $clientId;
    private string $merchantId;
    private string $clientSecret;
    private string $publicKey;
    private string $privateKey;
    private string $baseUrl;
    private Config $config;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? Config::getInstance();
        $this->enabled = (bool)$this->config->get('DANA_ENABLED', false);
        $this->env = (string)$this->config->get('DANA_ENV', 'sandbox');
        $this->clientId = (string)$this->config->get('DANA_CLIENT_ID', '');
        $this->merchantId = (string)$this->config->get('DANA_MERCHANT_ID', '');
        $this->clientSecret = (string)$this->config->get('DANA_CLIENT_SECRET', '');
        $this->publicKey = (string)$this->config->get('DANA_PUBLIC_KEY', '');
        $this->privateKey = (string)$this->config->get('DANA_PRIVATE_KEY', '');
        $this->baseUrl = $this->env === 'production' ? 'https://api.dana.id' : 'https://api-sandbox.dana.id';
    }

    private function assertConfigured(): void
    {
        if (!$this->enabled || $this->clientId === '' || $this->clientSecret === '') {
            throw new AppError(
                ErrorCode::INVALID_REQUEST,
                'Integrasi DANA QRIS Dinamis belum aktif: menunggu persetujuan merchant resmi DANA Bisnis, ' .
                'verifikasi akun, dan penerbitan kredensial API. Silakan gunakan metode Transfer Bank / QRIS Manual.',
                400
            );
        }
    }

    public function createInvoice(
        string $orderId,
        int $amountMinor,
        string $currency,
        string $userId,
        string $userEmail,
        string $paymentMethod = ''
    ): array {
        $this->assertConfigured();

        $payload = [
            'partnerReferenceNo' => $orderId,
            'amount' => [
                'value' => sprintf('%.2f', $amountMinor),
                'currency' => $currency,
            ],
            'merchantId' => $this->merchantId,
            'validUpTo' => (int)$this->config->get('TOPUP_EXPIRY_MINUTES', 120) * 60,
        ];

        $timestamp = gmdate('Y-m-d\TH:i:sP');
        $signature = $this->generateSignature($payload, $timestamp);

        $ch = curl_init("{$this->baseUrl}/v1.0/qr/qr-mpm-generate");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "X-CLIENT-KEY: {$this->clientId}",
                "X-TIMESTAMP: {$timestamp}",
                "X-SIGNATURE: {$signature}",
                "X-PARTNER-ID: {$this->merchantId}",
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $errNo = curl_errno($ch);
        $errStr = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
            throw new AppError(ErrorCode::PROVIDER_ERROR, "Gagal menghubungi gateway DANA: {$errStr}", 502);
        }

        if ($httpCode !== 200) {
            throw new AppError(ErrorCode::PROVIDER_ERROR, "DANA API merespons dengan kode HTTP {$httpCode}", 502);
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            throw new AppError(ErrorCode::PROVIDER_ERROR, 'Respons DANA API tidak valid.', 502);
        }

        return [
            'checkout_url' => $data['checkoutUrl'] ?? null,
            'provider_reference' => $data['referenceNo'] ?? $orderId,
            'instructions' => [
                'method' => 'dana_qris_dynamic',
                'title' => 'DANA QRIS Dinamis',
                'order_id' => $orderId,
                'amount' => $amountMinor,
                'currency' => $currency,
                'qr_content' => $data['qrContent'] ?? '',
                'instruction' => 'Pindai kode QRIS dinamis menggunakan aplikasi DANA atau e-wallet lainnya.',
            ],
        ];
    }

    public function verifyWebhook(array $headers, string $rawBody): array
    {
        $this->assertConfigured();

        $receivedSig = $headers['X-Signature'] ?? $headers['x-signature'] ?? null;
        $timestamp = $headers['X-Timestamp'] ?? $headers['x-timestamp'] ?? null;

        if (!$receivedSig || !$timestamp) {
            throw new AppError(
                ErrorCode::PAYMENT_VERIFICATION_FAILED,
                'Header signature DANA tidak lengkap.',
                400
            );
        }

        $expectedSig = $this->generateSignatureRaw($rawBody, $timestamp);
        if (!Security::secureCompare($receivedSig, $expectedSig)) {
            throw new AppError(
                ErrorCode::PAYMENT_VERIFICATION_FAILED,
                'Verifikasi tanda tangan webhook DANA gagal.',
                401
            );
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            throw new AppError(ErrorCode::INVALID_REQUEST, 'Payload webhook DANA tidak valid.', 400);
        }

        $statusStr = ($data['latestTransactionStatus'] ?? '') === '00' ? 'paid' : 'failed';
        $amountVal = isset($data['amount']['value']) ? (int)round((float)$data['amount']['value']) : 0;

        return [
            'order_id' => (string)($data['partnerReferenceNo'] ?? ''),
            'amount_minor' => $amountVal,
            'currency' => (string)($data['amount']['currency'] ?? 'IDR'),
            'status' => $statusStr,
            'provider_reference' => (string)($data['referenceNo'] ?? ''),
            'raw' => $data,
        ];
    }

    private function generateSignature(array $payload, string $timestamp): string
    {
        $bodyStr = json_encode($payload, JSON_UNESCAPED_SLASHES);
        return $this->generateSignatureRaw($bodyStr, $timestamp);
    }

    private function generateSignatureRaw(string $body, string $timestamp): string
    {
        $digest = hash('sha256', $body);
        $stringToSign = "POST|/v1.0/qr/qr-mpm-generate|{$digest}|{$timestamp}";
        $hmac = hash_hmac('sha256', $stringToSign, $this->clientSecret, true);
        return base64_encode($hmac);
    }
}
