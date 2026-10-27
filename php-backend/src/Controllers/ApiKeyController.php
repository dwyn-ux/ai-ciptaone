<?php
declare(strict_types=1);

namespace Ciptaone\Controllers;

use Ciptaone\Core\AppError;
use Ciptaone\Core\ErrorCode;
use Ciptaone\Core\Request;
use Ciptaone\Core\Response;
use Ciptaone\Core\Security;
use Ciptaone\Services\ApiKeyService;

/**
 * Controller for user API Key management.
 */
class ApiKeyController extends BaseController
{
    public function __construct(?ApiKeyService $apiKeyService = null)
    {
        parent::__construct(null, $apiKeyService);
    }

    public function createApiKey(Request $request): void
    {
        Security::validateCsrf($request, $this->config);
        $user = $this->getAuthUser($request);
        $name = (string)$request->get('name', '');

        if (trim($name) === '') {
            throw new AppError(ErrorCode::INVALID_REQUEST, 'Nama API key wajib diisi.', 422);
        }

        $res = $this->apiKeyService->createApiKey($user['id'], $name);
        Response::json($res, 201);
    }

    public function listApiKeys(Request $request): void
    {
        $user = $this->getAuthUser($request);
        $keys = $this->apiKeyService->listUserApiKeys($user['id']);
        Response::json($keys);
    }

    public function revokeApiKey(Request $request, array $params): void
    {
        Security::validateCsrf($request, $this->config);
        $user = $this->getAuthUser($request);
        $keyId = (string)($params['key_id'] ?? '');

        if ($keyId === '') {
            throw new AppError(ErrorCode::INVALID_REQUEST, 'Key ID wajib dicantumkan.', 400);
        }

        $this->apiKeyService->revokeApiKey($user['id'], $keyId);
        Response::json([
            'status' => 'success',
            'message' => 'API key berhasil dicabut.',
        ]);
    }
}
