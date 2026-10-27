<?php
declare(strict_types=1);

namespace Ciptaone\Controllers;

use Ciptaone\Core\AppError;
use Ciptaone\Core\ErrorCode;
use Ciptaone\Core\RateLimiter;
use Ciptaone\Core\Request;
use Ciptaone\Core\Response;
use Ciptaone\Services\RouterService;

/**
 * OpenAI-compatible Gateway Controller: /v1/models and /v1/chat/completions.
 */
class OpenAIController extends BaseController
{
    private RouterService $routerService;
    private RateLimiter $limiter;

    public function __construct(
        ?RouterService $routerService = null,
        ?RateLimiter $limiter = null
    ) {
        parent::__construct();
        $this->routerService = $routerService ?? new RouterService();
        $this->limiter = $limiter ?? new RateLimiter();
    }

    public function listModels(Request $request): void
    {
        $res = $this->routerService->listOpenAIModels();
        Response::json($res);
    }

    public function listActiveModels(Request $request): void
    {
        $res = $this->routerService->listActiveModels();
        Response::json($res);
    }

    public function chatCompletions(Request $request): void
    {
        [$userId, $apiKeyId] = $this->getAuthForCompletion($request);

        // Rate limit per user / API key
        $limit = (int)$this->config->get('RATE_LIMIT_CHAT_PER_MINUTE', 60);
        $this->limiter->checkRateLimit("chat_usr_{$userId}", $limit);

        $body = $request->getBody();
        if (empty($body['model'])) {
            throw new AppError(ErrorCode::INVALID_REQUEST, "Parameter 'model' wajib dicantumkan.", 422);
        }
        if (empty($body['messages']) || !is_array($body['messages'])) {
            throw new AppError(ErrorCode::INVALID_REQUEST, "Parameter 'messages' wajib berupa array pesan.", 422);
        }

        $stream = !empty($body['stream']);
        if ($stream) {
            $this->routerService->chatCompletionStream($userId, $body, $apiKeyId);
        } else {
            $completion = $this->routerService->chatCompletion($userId, $body, $apiKeyId);
            Response::json($completion);
        }
    }
}
