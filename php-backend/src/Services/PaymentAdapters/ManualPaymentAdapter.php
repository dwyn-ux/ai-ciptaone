<?php
declare(strict_types=1);

namespace Decomu\Services\PaymentAdapters;

use Decomu\Core\AppError;
use Decomu\Core\Config;
use Decomu\Core\ErrorCode;

/**
 * Adapter for Manual Bank Transfer & Official Static QRIS top-up.
 * Relies on admin mutation check via Telegram bot.
 */
class ManualPaymentAdapter extends BasePaymentAdapter
{
    private string $methodType;
    private Config $config;

    public function __construct(string $methodType = 'manual_transfer', ?Config $config = null)
    {
        $this->methodType = $methodType;
        $this->config = $config ?? Config::getInstance();
    }

    public function createInvoice(
        string $orderId,
        int $amountMinor,
        string $currency,
        string $userId,
        string $userEmail
    ): array {
        $formattedAmount = number_format($amountMinor, 0, ',', '.');

        if ($this->methodType === 'manual_qris') {
            $instructions = [
                'method' => 'manual_qris',
                'title' => 'Pembayaran QRIS',
                'instruction' => "Silakan pindai QRIS resmi dan bayar tepat Rp{$formattedAmount}. Cantumkan nomor pesanan {$orderId} pada catatan jika memungkinkan.",
                'qris_image_url' => $this->config->get('MANUAL_QRIS_IMAGE_URL') ?: null,
                'order_id' => $orderId,
                'amount' => $amountMinor,
                'currency' => $currency,
            ];
        } else {
            $instructions = [
                'method' => 'manual_transfer',
                'title' => 'Transfer Bank Manual',
                'bank_name' => (string)$this->config->get('MANUAL_BANK_NAME', 'BCA / Mandiri / BRI'),
                'account_number' => (string)$this->config->get('MANUAL_ACCOUNT_NO', '1234567890'),
                'account_holder' => (string)$this->config->get('MANUAL_ACCOUNT_HOLDER', 'Decomu AI Router'),
                'order_id' => $orderId,
                'amount' => $amountMinor,
                'currency' => $currency,
                'instruction' => "Transfer tepat sebesar Rp{$formattedAmount} ke rekening di atas. Cantumkan nomor referensi {$orderId} pada berita transfer.",
            ];
        }

        return [
            'checkout_url' => null,
            'provider_reference' => $orderId,
            'instructions' => $instructions,
        ];
    }

    public function verifyWebhook(array $headers, string $rawBody): array
    {
        throw new AppError(
            ErrorCode::INVALID_REQUEST,
            'Manual payment adapter does not process gateway webhooks.',
            400
        );
    }
}
