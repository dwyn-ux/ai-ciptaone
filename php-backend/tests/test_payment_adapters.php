<?php
declare(strict_types=1);

namespace Ciptaone\Tests;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Config;
use Ciptaone\Services\PaymentAdapters\DanaPaymentAdapter;

function test_payment_adapters_suite(): void
{
    $config = Config::getInstance();

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
