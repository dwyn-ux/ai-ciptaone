<?php
declare(strict_types=1);

namespace Ciptaone\Services\PaymentAdapters;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Config;
use Ciptaone\Core\Database;
use Ciptaone\Core\ErrorCode;
use Ciptaone\Services\Payments\DuitkuService;

/**
 * Adapter integrating Duitku Full Service (QRIS, VA BCA/Mandiri/BRI/BNI, E-Wallet, Retail)
 * into the Topup invoice lifecycle.
 */
class DuitkuPaymentAdapter extends BasePaymentAdapter
{
    private DuitkuService $duitkuService;

    public function __construct(?DuitkuService $duitkuService = null, ?Config $config = null, ?Database $db = null)
    {
        $this->duitkuService = $duitkuService ?? new DuitkuService($config, $db);
    }

    public function getDuitkuService(): DuitkuService
    {
        return $this->duitkuService;
    }

    public function createInvoice(
        string $orderId,
        int $amountMinor,
        string $currency,
        string $userId,
        string $userEmail
    ): array {
        $result = $this->duitkuService->createInvoice(
            $orderId,
            $amountMinor,
            "Pengguna {$userId}",
            $userEmail,
            '081234567890',
            'SP'
        );

        $checkoutUrl = $result['payment_url'] ?: ($result['qr_url'] ?? '');

        return [
            'checkout_url' => $checkoutUrl,
            'qr_url' => $result['qr_url'],
            'qr_string' => $result['qr_string'],
            'provider_reference' => $result['reference'],
            'instructions' => [
                'metode' => 'Duitku Full Service (QRIS, VA, E-Wallet, Ritel)',
                'nominal' => $amountMinor,
                'checkout_url' => $checkoutUrl,
                'va_number' => $result['va_number'] ?? null,
                'qr_url' => $result['qr_url'] ?? null,
                'berlaku_sampai' => gmdate('Y-m-d H:i:s', (int)($result['expired_time'] ?? (time() + 3600))),
                'keterangan' => 'Klik tombol Bayar Sekarang untuk menyelesaikan pembayaran melalui QRIS, Virtual Account (BCA, Mandiri, BRI, BNI, CIMB, Permata, dll), E-Wallet, atau Gerai Ritel. Transaksi otomatis aktif dalam hitungan detik.',
            ],
        ];
    }

    public function verifyWebhook(array $headers, string $rawBody): array
    {
        // Duitku can post as application/x-www-form-urlencoded or application/json
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            parse_str($rawBody, $parsed);
            if (is_array($parsed) && !empty($parsed)) {
                $data = $parsed;
            }
        }

        if (!is_array($data) || empty($data)) {
            throw new AppError(ErrorCode::INVALID_REQUEST, 'Body webhook Duitku kosong atau tidak valid.', 400);
        }

        return $this->duitkuService->parseAndVerifyCallback($data);
    }
}
