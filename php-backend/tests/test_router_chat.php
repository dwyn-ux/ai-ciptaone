<?php
declare(strict_types=1);

namespace Ciptaone\Tests;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Database;
use Ciptaone\Services\AuthService;
use Ciptaone\Services\RouterService;
use Ciptaone\Services\WalletService;

function test_router_chat_suite(Database $db): void
{
    $authService = new AuthService($db);
    $walletService = new WalletService($db);
    $routerService = new RouterService($db, null, $walletService);

    // 1. Token cost calculation
    $cost1 = RouterService::calculateTokenCost(1000, 2000, 1500, 3000, 1);
    // (1000 * 1500)/1M = 1.5 -> ceil = 2
    // (2000 * 3000)/1M = 6.0 -> ceil = 6
    // Total = 8
    assert($cost1 === 8, "Expected token cost 8, got {$cost1}");

    // Minimum 1 credit rule
    $costMin = RouterService::calculateTokenCost(1, 0, 10, 10, 1);
    assert($costMin === 1, "Minimum token cost should be 1, got {$costMin}");

    // Zero tokens cost zero
    $costZero = RouterService::calculateTokenCost(0, 0, 1500, 3000, 1);
    assert($costZero === 0, "Zero tokens cost should be 0, got {$costZero}");

    // 2. Models Catalog
    $openAiModels = $routerService->listOpenAIModels();
    assert($openAiModels['object'] === 'list', 'OpenAI models object is not list');
    assert(is_array($openAiModels['data']), 'OpenAI models data is not array');
    assert(count($openAiModels['data']) >= 1, 'Active models list is empty');
    assert($openAiModels['data'][0]['id'] === 'mock/echo-v1', 'mock/echo-v1 model not found in catalog');

    // 3. Chat completion setup
    $user = $authService->register('chat_tester_' . bin2hex(random_bytes(4)) . '@ciptaone.com', 'ChatPass12345#');
    $userId = $user['id'];

    // Credit user wallet with 10,000 credits
    $walletService->creditWalletForTopup($userId, 'INV-CHAT-01', 10000);

    // 4. Non-streaming completion
    $chatRequest = [
        'model' => 'mock/echo-v1',
        'messages' => [
            ['role' => 'user', 'content' => 'Halo, ini uji coba router']
        ],
        'temperature' => 0.7,
        'stream' => false,
    ];

    $response = $routerService->chatCompletion($userId, $chatRequest);
    assert($response['object'] === 'chat.completion', 'Response object is not chat.completion');
    assert($response['model'] === 'mock/echo-v1', 'Model in response mismatch');
    assert(!empty($response['choices'][0]['message']['content']), 'Message content is empty');
    assert(str_contains($response['choices'][0]['message']['content'], '[Mock Echo]'), 'Mock Echo prefix missing');
    assert($response['usage']['prompt_tokens'] > 0, 'Prompt tokens should be > 0');
    assert($response['usage']['completion_tokens'] > 0, 'Completion tokens should be > 0');

    // Verify wallet was settled
    $walletAfter = $walletService->getWallet($userId);
    assert($walletAfter['balance_credits'] < 10000, 'Wallet was not debited after chat completion');

    // Verify usage event recorded
    $usageRow = $db->fetchOne('SELECT * FROM usage_events WHERE user_id = ? ORDER BY created_at DESC', [$userId]);
    assert($usageRow !== null, 'Usage event record not found');
    assert($usageRow['status'] === 'success', 'Usage status is not success');
    assert((int)$usageRow['streaming'] === 0, 'Non-streaming usage marked as streaming');

    // 5. Disabled / Unavailable Model Rejection
    $disabledCaught = false;
    try {
        $routerService->chatCompletion($userId, [
            'model' => 'meta-llama/llama-3-8b-instruct', // Disabled in seed
            'messages' => [['role' => 'user', 'content' => 'test']]
        ]);
    } catch (AppError $ae) {
        $disabledCaught = true;
        assert($ae->getCodeString() === 'model_unavailable', 'Expected model_unavailable error code');
    }
    assert($disabledCaught, 'Disabled model completion was allowed');
}
