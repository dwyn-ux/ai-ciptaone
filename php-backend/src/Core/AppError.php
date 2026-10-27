<?php
declare(strict_types=1);

namespace Decomu\Core;

use Exception;

/**
 * Custom application exception that encapsulates PRD standard error envelope.
 */
class AppError extends Exception
{
    protected string $codeString;
    protected int $statusCode;
    protected string $requestId;
    protected array $details;

    public function __construct(
        string $codeString,
        string $message,
        int $statusCode = 400,
        ?string $requestId = null,
        array $details = []
    ) {
        parent::__construct($message);
        $this->codeString = $codeString;
        $this->statusCode = $statusCode;
        $this->requestId = $requestId ?? 'req_' . substr(bin2hex(random_bytes(8)), 0, 12);
        $this->details = $details;
    }

    public function getCodeString(): string
    {
        return $this->codeString;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function setRequestId(string $requestId): self
    {
        $this->requestId = $requestId;
        return $this;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function toEnvelope(): array
    {
        $envelope = [
            'error' => [
                'code' => $this->codeString,
                'message' => $this->getMessage(),
                'request_id' => $this->requestId,
            ],
        ];

        if (!empty($this->details)) {
            $envelope['error']['details'] = $this->details;
        }

        return $envelope;
    }
}
