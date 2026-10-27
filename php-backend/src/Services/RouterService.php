<?php
declare(strict_types=1);

namespace Decomu\Services;

use Decomu\Core\AppError;
use Decomu\Core\Config;
use Decomu\Core\Database;
use Decomu\Core\ErrorCode;
use Decomu\Core\Response;
use Decomu\Core\Security;
use Decomu\Services\ProviderAdapters\BaseProviderAdapter;
use Decomu\Services\ProviderAdapters\MockProviderAdapter;
use Decomu\Services\ProviderAdapters\OpenAICompatibleAdapter;

/**
 * AI Routing service: token mathematics, model selection, upstream orchestration,
 * non-stream completions, and real-time SSE streaming.
 */
class RouterService
{
    private Database $db;
    private Config $config;
    private WalletService $walletService;
    private MockProviderAdapter $mockAdapter;

    public function __construct(
        ?Database $db = null,
        ?Config $config = null,
        ?WalletService $walletService = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->config = $config ?? Config::getInstance();
        $this->walletService = $walletService ?? new WalletService($this->db);
        $this->mockAdapter = new MockProviderAdapter();
    }

    public static function calculateTokenCost(
        int $inputTokens,
        int $outputTokens,
        int $inputPricePerMillion,
        int $outputPricePerMillion,
        int $creditPerRupiah = 1
    ): int {
        $costIn = (int)ceil(($inputTokens * $inputPricePerMillion) / 1000000);
        $costOut = (int)ceil(($outputTokens * $outputPricePerMillion) / 1000000);
        $totalMinor = $costIn + $costOut;
        $totalCredits = $totalMinor * $creditPerRupiah;

        // Ensure minimum 1 credit charge if any tokens were processed
        if (($inputTokens > 0 || $outputTokens > 0) && $totalCredits === 0) {
            $totalCredits = 1;
        }
        return $totalCredits;
    }

    private function getSettingValue(string $key): string
    {
        try {
            $row = $this->db->fetchOne('SELECT setting_value FROM system_settings WHERE setting_key = ?', [$key]);
            if ($row !== null && !empty($row['setting_value'])) {
                return (string)$row['setting_value'];
            }
        } catch (\Throwable) {
        }
        return (string)$this->config->get($key, '');
    }

    private function getAdapter(string $providerSlug): BaseProviderAdapter
    {
        if ($providerSlug === 'mock') {
            return $this->mockAdapter;
        } elseif ($providerSlug === 'openrouter') {
            return new OpenAICompatibleAdapter(
                'https://openrouter.ai/api/v1',
                $this->getSettingValue('OPENROUTER_API_KEY')
            );
        } elseif ($providerSlug === 'openai') {
            return new OpenAICompatibleAdapter(
                'https://api.openai.com/v1',
                $this->getSettingValue('OPENAI_API_KEY')
            );
        } else {
            throw new AppError(
                ErrorCode::MODEL_UNAVAILABLE,
                "Provider '{$providerSlug}' belum memiliki adapter aktif.",
                503
            );
        }
    }

    public function listActiveModels(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT m.id, m.public_id, m.provider_id, p.slug as provider_slug, p.display_name,
                    m.upstream_model, m.enabled, m.input_price_per_million, m.output_price_per_million,
                    m.currency, m.max_input_tokens, m.max_output_tokens, m.supports_streaming, m.pricing_version
             FROM models m
             JOIN providers p ON m.provider_id = p.id
             WHERE m.enabled = 1 AND p.enabled = 1
             ORDER BY m.public_id ASC'
        );

        return array_map(function ($r) {
            return [
                'id' => $r['id'],
                'public_id' => $r['public_id'],
                'provider_id' => $r['provider_id'],
                'provider_slug' => $r['provider_slug'],
                'display_name' => $r['display_name'],
                'upstream_model' => $r['upstream_model'],
                'enabled' => (bool)$r['enabled'],
                'input_price_per_million' => (int)$r['input_price_per_million'],
                'output_price_per_million' => (int)$r['output_price_per_million'],
                'currency' => $r['currency'],
                'max_input_tokens' => (int)$r['max_input_tokens'],
                'max_output_tokens' => (int)$r['max_output_tokens'],
                'supports_streaming' => (bool)$r['supports_streaming'],
                'pricing_version' => (int)$r['pricing_version'],
            ];
        }, $rows);
    }

    public function listOpenAIModels(): array
    {
        $models = $this->listActiveModels();
        $data = [];
        foreach ($models as $m) {
            $data[] = [
                'id' => $m['public_id'],
                'object' => 'model',
                'created' => 1714000000,
                'owned_by' => 'decomu',
            ];
        }

        return [
            'object' => 'list',
            'data' => $data,
        ];
    }

    public function getModelByPublicId(string $publicId): ?array
    {
        return $this->db->fetchOne(
            'SELECT m.id, m.public_id, m.provider_id, p.slug as provider_slug, p.enabled as provider_enabled,
                    m.upstream_model, m.enabled as model_enabled, m.input_price_per_million,
                    m.output_price_per_million, m.currency, m.max_input_tokens, m.max_output_tokens,
                    m.supports_streaming, m.pricing_version
             FROM models m
             JOIN providers p ON m.provider_id = p.id
             WHERE m.public_id = ?',
            [$publicId]
        );
    }

    public function chatCompletion(
        string $userId,
        array $request,
        ?string $apiKeyId = null
    ): array {
        $startTime = microtime(true);
        $requestId = Security::generateId('req');
        $nowIso = gmdate('Y-m-d H:i:s');

        $modelName = (string)($request['model'] ?? '');
        $messages = (array)($request['messages'] ?? []);
        $temperature = (float)($request['temperature'] ?? 0.7);

        // 1. Validate Model
        $modelRow = $this->getModelByPublicId($modelName);
        if ($modelRow === null || empty($modelRow['model_enabled']) || empty($modelRow['provider_enabled'])) {
            throw new AppError(
                ErrorCode::MODEL_UNAVAILABLE,
                "Model '{$modelName}' tidak tersedia atau sedang dinonaktifkan.",
                400,
                $requestId
            );
        }

        // 2. Token usage estimation & max credit reservation
        $totalChars = 0;
        foreach ($messages as $m) {
            $totalChars += strlen((string)($m['content'] ?? ''));
        }
        $estInputTokens = max(10, (int)intdiv($totalChars, 4));
        $reqMaxTokens = isset($request['max_tokens']) ? (int)$request['max_tokens'] : (int)$modelRow['max_output_tokens'];
        $maxOutputTokens = min($reqMaxTokens, (int)$modelRow['max_output_tokens']);

        $maxReserveCredits = self::calculateTokenCost(
            $estInputTokens,
            $maxOutputTokens,
            (int)$modelRow['input_price_per_million'],
            (int)$modelRow['output_price_per_million'],
            (int)$this->config->get('CREDIT_PER_RUPIAH', 1)
        );

        // 3. Atomically Reserve Credits
        $this->walletService->reserveCredits($userId, $requestId, $maxReserveCredits);

        // 4. Execute Upstream AI Provider
        $adapter = $this->getAdapter((string)$modelRow['provider_slug']);
        try {
            $result = $adapter->chatCompletion(
                (string)$modelRow['upstream_model'],
                $messages,
                $maxOutputTokens,
                $temperature
            );
        } catch (\Throwable $e) {
            $this->walletService->releaseReservation($userId, $requestId);
            if ($e instanceof AppError) {
                $e->setRequestId($requestId);
                throw $e;
            }
            throw new AppError(
                ErrorCode::PROVIDER_ERROR,
                'Terjadi kesalahan saat memproses permintaan ke provider: ' . $e->getMessage(),
                502,
                $requestId
            );
        }

        $latencyMs = (int)round((microtime(true) - $startTime) * 1000);

        // 5. Settle Actual Billed Credits
        $actualInputTokens = (int)($result['prompt_tokens'] ?? $estInputTokens);
        $actualOutputTokens = (int)($result['completion_tokens'] ?? 10);
        $actualBilledCredits = self::calculateTokenCost(
            $actualInputTokens,
            $actualOutputTokens,
            (int)$modelRow['input_price_per_million'],
            (int)$modelRow['output_price_per_million'],
            (int)$this->config->get('CREDIT_PER_RUPIAH', 1)
        );

        $this->walletService->settleCredits(
            $userId,
            $requestId,
            $actualBilledCredits,
            [
                'model' => $modelName,
                'input_tokens' => $actualInputTokens,
                'output_tokens' => $actualOutputTokens,
                'latency_ms' => $latencyMs,
            ]
        );

        // 6. Record Usage Event (No prompt/response retention per PRD)
        $usageId = Security::generateId('usg');
        $this->db->execute(
            'INSERT INTO usage_events (id, request_id, user_id, api_key_id, model_id, provider_id,
                                      input_tokens, output_tokens, estimated_cost_minor, billed_credits,
                                      latency_ms, status, error_code, streaming, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 0, ?)',
            [
                $usageId, $requestId, $userId, $apiKeyId, $modelRow['id'], $modelRow['provider_id'],
                $actualInputTokens, $actualOutputTokens, $actualBilledCredits, $actualBilledCredits,
                $latencyMs, 'success', $nowIso
            ]
        );

        // 7. OpenAI-compatible Chat Completion response
        return [
            'id' => $requestId,
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $modelName,
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $result['content'] ?? '',
                    ],
                    'finish_reason' => $result['finish_reason'] ?? 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => $actualInputTokens,
                'completion_tokens' => $actualOutputTokens,
                'total_tokens' => $actualInputTokens + $actualOutputTokens,
            ],
        ];
    }

    public function chatCompletionStream(
        string $userId,
        array $request,
        ?string $apiKeyId = null
    ): void {
        $startTime = microtime(true);
        $requestId = Security::generateId('req');
        $createdTimestamp = time();
        $nowIso = gmdate('Y-m-d H:i:s');

        $modelName = (string)($request['model'] ?? '');
        $messages = (array)($request['messages'] ?? []);
        $temperature = (float)($request['temperature'] ?? 0.7);

        // Start SSE headers
        Response::startSse();

        $modelRow = $this->getModelByPublicId($modelName);
        if ($modelRow === null || empty($modelRow['model_enabled']) || empty($modelRow['provider_enabled'])) {
            Response::sendSseChunk([
                'error' => [
                    'code' => ErrorCode::MODEL_UNAVAILABLE,
                    'message' => "Model '{$modelName}' tidak tersedia.",
                    'request_id' => $requestId,
                ]
            ]);
            Response::sendSseDone();
            exit;
        }

        $totalChars = 0;
        foreach ($messages as $m) {
            $totalChars += strlen((string)($m['content'] ?? ''));
        }
        $estInputTokens = max(10, (int)intdiv($totalChars, 4));
        $reqMaxTokens = isset($request['max_tokens']) ? (int)$request['max_tokens'] : (int)$modelRow['max_output_tokens'];
        $maxOutputTokens = min($reqMaxTokens, (int)$modelRow['max_output_tokens']);

        $maxReserveCredits = self::calculateTokenCost(
            $estInputTokens,
            $maxOutputTokens,
            (int)$modelRow['input_price_per_million'],
            (int)$modelRow['output_price_per_million'],
            (int)$this->config->get('CREDIT_PER_RUPIAH', 1)
        );

        try {
            $this->walletService->reserveCredits($userId, $requestId, $maxReserveCredits);
        } catch (AppError $ae) {
            Response::sendSseChunk($ae->toEnvelope());
            Response::sendSseDone();
            exit;
        }

        $adapter = $this->getAdapter((string)$modelRow['provider_slug']);
        $outputWordsCount = 0;
        $promptTokenCount = $estInputTokens;

        try {
            $adapter->chatCompletionStream(
                (string)$modelRow['upstream_model'],
                $messages,
                $maxOutputTokens,
                $temperature,
                function (array $chunk) use ($requestId, $createdTimestamp, $modelName, &$outputWordsCount, &$promptTokenCount) {
                    $delta = $chunk['delta'] ?? [];
                    $finishReason = $chunk['finish_reason'] ?? null;
                    if (!empty($delta['content'])) {
                        $words = preg_split('/\s+/', trim((string)$delta['content']));
                        $outputWordsCount += count($words);
                    }

                    if (!empty($chunk['usage'])) {
                        $promptTokenCount = (int)($chunk['usage']['prompt_tokens'] ?? $promptTokenCount);
                        $outputWordsCount = (int)($chunk['usage']['completion_tokens'] ?? $outputWordsCount);
                    }

                    $chunkObj = [
                        'id' => $requestId,
                        'object' => 'chat.completion.chunk',
                        'created' => $createdTimestamp,
                        'model' => $modelName,
                        'choices' => [
                            [
                                'index' => 0,
                                'delta' => $delta,
                                'finish_reason' => $finishReason,
                            ],
                        ],
                    ];
                    Response::sendSseChunk($chunkObj);
                }
            );
        } catch (\Throwable $e) {
            $this->walletService->releaseReservation($userId, $requestId);
            Response::sendSseChunk([
                'error' => [
                    'code' => ErrorCode::PROVIDER_ERROR,
                    'message' => 'Streaming terputus: ' . $e->getMessage(),
                    'request_id' => $requestId,
                ]
            ]);
            Response::sendSseDone();
            exit;
        }

        Response::sendSseDone();

        // Settle Actual Streaming Credits
        $latencyMs = (int)round((microtime(true) - $startTime) * 1000);
        $finalOutputTokens = max(1, $outputWordsCount);
        $actualBilled = self::calculateTokenCost(
            $promptTokenCount,
            $finalOutputTokens,
            (int)$modelRow['input_price_per_million'],
            (int)$modelRow['output_price_per_million'],
            (int)$this->config->get('CREDIT_PER_RUPIAH', 1)
        );

        $this->walletService->settleCredits(
            $userId,
            $requestId,
            $actualBilled,
            [
                'model' => $modelName,
                'input_tokens' => $promptTokenCount,
                'output_tokens' => $finalOutputTokens,
                'latency_ms' => $latencyMs,
                'streaming' => true,
            ]
        );

        $usageId = Security::generateId('usg');
        $this->db->execute(
            'INSERT INTO usage_events (id, request_id, user_id, api_key_id, model_id, provider_id,
                                      input_tokens, output_tokens, estimated_cost_minor, billed_credits,
                                      latency_ms, status, error_code, streaming, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 1, ?)',
            [
                $usageId, $requestId, $userId, $apiKeyId, $modelRow['id'], $modelRow['provider_id'],
                $promptTokenCount, $finalOutputTokens, $actualBilled, $actualBilled,
                $latencyMs, 'success', $nowIso
            ]
        );
        exit;
    }

    public function listUserUsage(string $userId, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $rows = $this->db->fetchAll(
            'SELECT id, request_id, model_id, provider_id, input_tokens, output_tokens,
                    billed_credits, latency_ms, status, streaming, created_at
             FROM usage_events
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?',
            [$userId, $limit, $offset]
        );

        $totalRow = $this->db->fetchOne(
            'SELECT COUNT(*) as total FROM usage_events WHERE user_id = ?',
            [$userId]
        );
        $total = $totalRow !== null ? (int)$totalRow['total'] : 0;

        $items = array_map(function ($r) {
            return [
                'id' => $r['id'],
                'request_id' => $r['request_id'],
                'model_id' => $r['model_id'],
                'provider_id' => $r['provider_id'],
                'input_tokens' => (int)$r['input_tokens'],
                'output_tokens' => (int)$r['output_tokens'],
                'billed_credits' => (int)$r['billed_credits'],
                'latency_ms' => (int)$r['latency_ms'],
                'status' => $r['status'],
                'streaming' => (bool)$r['streaming'],
                'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['created_at'])),
            ];
        }, $rows);

        return [
            'items' => $items,
            'total' => $total,
        ];
    }
}
