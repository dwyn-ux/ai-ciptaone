<?php
declare(strict_types=1);

namespace Ciptaone\Services\PaymentAdapters;

/**
 * Interface contract for payment gateway adapters.
 */
abstract class BasePaymentAdapter
{
    abstract public function createInvoice(
        string $orderId,
        int $amountMinor,
        string $currency,
        string $userId,
        string $userEmail,
        string $paymentMethod = ''
    ): array;

    abstract public function verifyWebhook(array $headers, string $rawBody): array;
}
