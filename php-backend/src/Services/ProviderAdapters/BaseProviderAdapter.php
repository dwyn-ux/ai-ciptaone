<?php
declare(strict_types=1);

namespace Decomu\Services\ProviderAdapters;

/**
 * Interface contract for upstream AI provider integrations.
 */
abstract class BaseProviderAdapter
{
    /**
     * Non-streaming chat completion.
     * Returns: ['content' => string, 'finish_reason' => string, 'prompt_tokens' => int, 'completion_tokens' => int]
     */
    abstract public function chatCompletion(
        string $modelName,
        array $messages,
        int $maxTokens,
        float $temperature
    ): array;

    /**
     * Streaming chat completion.
     * Invokes $onChunk callback for each chunk: function(array $chunkData): void
     */
    abstract public function chatCompletionStream(
        string $modelName,
        array $messages,
        int $maxTokens,
        float $temperature,
        callable $onChunk
    ): void;
}
