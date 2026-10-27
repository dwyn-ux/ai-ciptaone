<?php
declare(strict_types=1);

namespace Decomu\Services\ProviderAdapters;

/**
 * Mock Provider for local development, automated testing, and sandbox verification.
 * Produces deterministic echo output with simulated token counts without consuming upstream quotas.
 */
class MockProviderAdapter extends BaseProviderAdapter
{
    public function chatCompletion(
        string $modelName,
        array $messages,
        int $maxTokens,
        float $temperature
    ): array {
        $lastMessage = !empty($messages) ? ($messages[array_key_last($messages)]['content'] ?? 'Halo') : 'Halo';
        $replyContent = "[Mock Echo] Menerima pesan: \"{$lastMessage}\". Model: {$modelName}.";

        $promptTokens = 0;
        foreach ($messages as $m) {
            $words = preg_split('/\s+/', trim((string)($m['content'] ?? '')));
            $promptTokens += count($words) * 2;
        }
        $promptTokens += 5;

        $replyWords = preg_split('/\s+/', trim($replyContent));
        $completionTokens = count($replyWords) * 2 + 2;

        return [
            'content' => $replyContent,
            'finish_reason' => 'stop',
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
        ];
    }

    public function chatCompletionStream(
        string $modelName,
        array $messages,
        int $maxTokens,
        float $temperature,
        callable $onChunk
    ): void {
        $lastMessage = !empty($messages) ? ($messages[array_key_last($messages)]['content'] ?? 'Halo') : 'Halo';
        $fullText = "[Mock Echo] Menerima pesan: \"{$lastMessage}\". Streaming aktif.";
        $words = explode(' ', $fullText);

        foreach ($words as $i => $word) {
            $chunkText = ($i === 0) ? $word : " {$word}";
            $onChunk([
                'delta' => [
                    'role' => ($i === 0) ? 'assistant' : null,
                    'content' => $chunkText,
                ],
                'finish_reason' => null,
            ]);
            usleep(10000); // 10ms simulated stream chunk interval
        }

        $lastWords = preg_split('/\s+/', trim($lastMessage));
        $promptTokens = count($lastWords) * 2 + 5;
        $completionTokens = count($words) * 2 + 2;

        $onChunk([
            'delta' => [],
            'finish_reason' => 'stop',
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
            ],
        ]);
    }
}
