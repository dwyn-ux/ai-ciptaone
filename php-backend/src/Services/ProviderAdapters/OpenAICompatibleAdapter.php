<?php
declare(strict_types=1);

namespace Ciptaone\Services\ProviderAdapters;

use Ciptaone\Core\AppError;
use Ciptaone\Core\ErrorCode;

/**
 * Adapter for upstream OpenAI-compatible endpoints (OpenRouter, OpenAI, Groq, etc.)
 * using native PHP cURL with real-time SSE streaming.
 */
class OpenAICompatibleAdapter extends BaseProviderAdapter
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = trim($apiKey);
    }

    public function chatCompletion(
        string $modelName,
        array $messages,
        int $maxTokens,
        float $temperature
    ): array {
        if ($this->apiKey === '') {
            throw new AppError(
                ErrorCode::MODEL_UNAVAILABLE,
                "Kredensial upstream provider untuk model '{$modelName}' belum dikonfigurasi.",
                503
            );
        }

        $payload = [
            'model' => $modelName,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'stream' => false,
        ];

        $ch = curl_init("{$this->baseUrl}/chat/completions");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $errNo = curl_errno($ch);
        $errStr = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo === CURLE_OPERATION_TIMEDOUT) {
            throw new AppError(ErrorCode::PROVIDER_TIMEOUT, 'Upstream AI provider timeout.', 504);
        }
        if ($errNo !== 0) {
            throw new AppError(ErrorCode::PROVIDER_ERROR, "Koneksi upstream gagal: {$errStr}", 502);
        }

        if ($httpCode !== 200) {
            throw new AppError(
                ErrorCode::PROVIDER_ERROR,
                "Upstream provider error: HTTP {$httpCode}",
                502,
                null,
                ['upstream_status' => $httpCode]
            );
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            throw new AppError(ErrorCode::PROVIDER_ERROR, 'Format respons provider tidak valid.', 502);
        }

        $choice = $data['choices'][0] ?? [];
        $usage = $data['usage'] ?? [];

        return [
            'content' => $choice['message']['content'] ?? '',
            'finish_reason' => $choice['finish_reason'] ?? 'stop',
            'prompt_tokens' => (int)($usage['prompt_tokens'] ?? 10),
            'completion_tokens' => (int)($usage['completion_tokens'] ?? 10),
        ];
    }

    public function chatCompletionStream(
        string $modelName,
        array $messages,
        int $maxTokens,
        float $temperature,
        callable $onChunk
    ): void {
        if ($this->apiKey === '') {
            throw new AppError(
                ErrorCode::MODEL_UNAVAILABLE,
                'Kredensial upstream provider belum dikonfigurasi.',
                503
            );
        }

        $payload = [
            'model' => $modelName,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'stream' => true,
        ];

        $ch = curl_init("{$this->baseUrl}/chat/completions");
        $buffer = '';

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: text/event-stream',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$buffer, $onChunk): int {
                $buffer .= $chunk;
                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines); // Retain incomplete line

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, ':')) {
                        continue;
                    }

                    if (str_starts_with($line, 'data: ')) {
                        $dataStr = trim(substr($line, 6));
                        if ($dataStr === '[DONE]') {
                            break;
                        }

                        $parsed = json_decode($dataStr, true);
                        if (is_array($parsed)) {
                            $choice = $parsed['choices'][0] ?? [];
                            $delta = $choice['delta'] ?? [];
                            $finishReason = $choice['finish_reason'] ?? null;
                            $usage = $parsed['usage'] ?? null;

                            $onChunk([
                                'delta' => $delta,
                                'finish_reason' => $finishReason,
                                'usage' => $usage,
                            ]);
                        }
                    }
                }
                return strlen($chunk);
            },
        ]);

        $success = curl_exec($ch);
        $errNo = curl_errno($ch);
        $errStr = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
            throw new AppError(ErrorCode::PROVIDER_ERROR, "Streaming upstream error: {$errStr}", 502);
        }

        if ($httpCode !== 200) {
            throw new AppError(
                ErrorCode::PROVIDER_ERROR,
                "Upstream provider streaming error: HTTP {$httpCode}",
                502
            );
        }
    }
}
