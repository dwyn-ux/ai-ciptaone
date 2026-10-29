<?php
declare(strict_types=1);

namespace Ciptaone\Services\PaymentAdapters;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Config;
use Ciptaone\Core\Database;
use Ciptaone\Core\ErrorCode;
use Ciptaone\Services\Payments\TripayService;

/**
 * Adapter integrating Tripay Closed Dynamic QRIS into the Topup invoice lifecycle.
 */
class TripayPaymentAdapter extends BasePaymentAdapter
{
    private TripayService $tripayService;

    public function __construct(?TripayService $tripayService = null, ?Config $config = null, ?Database $db = null)
    {
        $this->tripayService = $tripayService ?? new TripayService($config, $db);
    }

    public function getTripayService(): TripayService
    {
        return $this->tripayService;
    }

    public function createInvoice(
        string $orderId,
        int $amountMinor,
        string $currency,
        string $userId,
        string $userEmail
    ): array {
        $result = $this->tripayService->createQrisTransaction(
            $orderId,
            $amountMinor,
            "Pengguna {$userId}",
            $userEmail
        );

        return [
            'checkout_url' => $result['checkout_url'] ?? ($result['qr_url'] ?? null),
            'qr_url' => $result['qr_url'] ?? null,
            'qr_string' => $result['qr_string'] ?? null,
            'provider_reference' => $result['reference'] ?? $orderId,
            'instructions' => [
                'metode' => 'QRIS Dinamis (Tripay)',
                'nominal' => $amountMinor,
                'qr_url' => $result['qr_url'],
                'berlaku_sampai' => gmdate('Y-m-d H:i:s', $result['expired_time']),
                'keterangan' => 'Scan QRIS ini dengan aplikasi e-Wallet atau Mobile Banking Anda. Pembayaran akan terkonfirmasi otomatis dalam hitungan detik.',
            ],
        ];
    }

    public function verifyWebhook(array $headers, string $rawBody): array
    {
        $signature = '';
        foreach ($headers as $k => $v) {
            if (strtolower((string)$k) === 'x-callback-signature') {
                $signature = is_array($v) ? (string)reset($v) : (string)$v;
                break;
            }
        }

        if ($signature === '') {
            throw new AppError(ErrorCode::UNAUTHORIZED, 'Header X-Callback-Signature Tripay tidak ditemukan.', 401);
        }

        if (!$this->tripayService->verifyCallbackSignature($rawBody, $signature)) {
            throw new AppError(ErrorCode::UNAUTHORIZED, 'Signature webhook Tripay tidak valid (tampering detected).', 401);
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            throw new AppError(ErrorCode::INVALID_REQUEST, 'Body webhook bukan format JSON valid.', 400);
        }

        $status = strtoupper((string)($data['status'] ?? ''));
        $orderId = (string)($data['merchant_ref'] ?? '');
        $reference = (string)($data['reference'] ?? '');
        $amount = (int)($data['total_amount'] ?? 0);

        return [
            'order_id' => $orderId,
            'provider_reference' => $reference,
            'amount' => $amount,
            'status' => $status === 'PAID' ? 'paid' : strtolower($status),
            'raw_data' => $data,
        ];
    }
}
