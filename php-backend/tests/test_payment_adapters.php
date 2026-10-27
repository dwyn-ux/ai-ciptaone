<?php
declare(strict_types=1);

namespace Ciptaone\Tests;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Config;
use Ciptaone\Services\PaymentAdapters\DanaPaymentAdapter;
use Ciptaone\Services\PaymentAdapters\ManualPaymentAdapter;

function test_payment_adapters_suite(): void
{
    $config = Config::getInstance();
    $config->set('MANUAL_BANK_NAME', 'Bank Mandiri');
    $config->set('MANUAL_ACCOUNT_NO', '9876543210');
    $config->set('MANUAL_ACCOUNT_HOLDER', 'PT AI Ciptaone');
    $config->set('MANUAL_QRIS_IMAGE_URL', 'https://example.com/qris.jpg');

    // 1. Manual Bank Transfer
    $transferAdapter = new ManualPaymentAdapter('manual_transfer', $config);
    $resTransfer = $transferAdapter->createInvoice('INV-001', 50000, 'IDR', 'usr_1', 'user@test.com');
    assert($resTransfer['instructions']['method'] === 'manual_transfer', 'Method mismatch');
    assert($resTransfer['instructions']['bank_name'] === 'Bank Mandiri', 'Bank name mismatch');
    assert($resTransfer['instructions']['account_number'] === '9876543210', 'Account number mismatch');

    // 2. Manual QRIS
    $qrisAdapter = new ManualPaymentAdapter('manual_qris', $config);
    $resQris = $qrisAdapter->createInvoice('INV-002', 25000, 'IDR', 'usr_1', 'user@test.com');
    assert($resQris['instructions']['method'] === 'manual_qris', 'Method mismatch');
    assert($resQris['instructions']['qris_image_url'] === 'https://example.com/qris.jpg', 'QRIS image URL mismatch');

    // 3. DANA Unconfigured Protection (DANA_ENABLED = false)
    $config->set('DANA_ENABLED', false);
    $danaAdapter = new DanaPaymentAdapter($config);

    $unconfiguredCaught = false;
    try {
        $danaAdapter->createInvoice('INV-003', 50000, 'IDR', 'usr_1', 'user@test.com');
    } catch (AppError $ae) {
        $unconfiguredCaught = true;
        assert($ae->getStatusCode() === 400, 'Deactivated DANA did not return HTTP 400');
        assert(str_contains($ae->getMessage(), 'DANA QRIS Dinamis belum aktif'), 'Error message missing expected advice');
    }
    assert($unconfiguredCaught, 'Unconfigured DANA adapter did not throw exception');
}
