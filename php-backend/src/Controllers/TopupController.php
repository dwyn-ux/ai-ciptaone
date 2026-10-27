<?php
declare(strict_types=1);

namespace Ciptaone\Controllers;

use Ciptaone\Core\AppError;
use Ciptaone\Core\ErrorCode;
use Ciptaone\Core\RateLimiter;
use Ciptaone\Core\Request;
use Ciptaone\Core\Response;
use Ciptaone\Core\Security;
use Ciptaone\Services\TopupService;

/**
 * Controller for Top-up creation, status checks, and safe payment proof uploads.
 */
class TopupController extends BaseController
{
    private TopupService $topupService;
    private RateLimiter $limiter;

    public function __construct(
        ?TopupService $topupService = null,
        ?RateLimiter $limiter = null
    ) {
        parent::__construct();
        $this->topupService = $topupService ?? new TopupService();
        $this->limiter = $limiter ?? new RateLimiter();
    }

    public function createTopup(Request $request): void
    {
        Security::validateCsrf($request, $this->config);
        $user = $this->getAuthUser($request);

        // Rate limit topup creation
        $limit = (int)$this->config->get('RATE_LIMIT_TOPUP_PER_MINUTE', 5);
        $this->limiter->checkRateLimit("topup_usr_{$user['id']}", $limit);

        $body = $request->getBody();
        $amountMinor = (int)($body['amount_minor'] ?? 0);
        $paymentMethod = (string)($body['payment_method'] ?? 'manual_transfer');

        $res = $this->topupService->createTopup(
            $user['id'],
            $user['email'],
            $amountMinor,
            $paymentMethod
        );

        Response::json($res, 201);
    }

    public function listTopups(Request $request): void
    {
        $user = $this->getAuthUser($request);
        $topups = $this->topupService->listUserTopups($user['id']);
        Response::json($topups);
    }

    public function getTopupStatus(Request $request, array $params): void
    {
        $user = $this->getAuthUser($request);
        $identifier = (string)($params['identifier'] ?? '');

        $topup = $this->topupService->getTopupByIdOrOrder($identifier, $user['id']);
        Response::json($topup);
    }

    public function uploadProof(Request $request, array $params): void
    {
        Security::validateCsrf($request, $this->config);
        $user = $this->getAuthUser($request);
        $identifier = (string)($params['identifier'] ?? '');

        $file = $request->getFile('file');
        if (!$file || empty($file['tmp_name'])) {
            throw new AppError(ErrorCode::INVALID_REQUEST, 'File bukti transfer tidak ditemukan pada request.', 422);
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new AppError(ErrorCode::INVALID_REQUEST, 'Gagal mengunggah file (upload error code: ' . $file['error'] . ').', 400);
        }

        $filename = (string)($file['name'] ?? 'proof.jpg');
        $contentType = (string)($file['type'] ?? 'image/jpeg');
        $fileBytes = file_get_contents((string)$file['tmp_name']);
        if ($fileBytes === false) {
            throw new AppError(ErrorCode::INVALID_REQUEST, 'Gagal membaca isi file yang diunggah.', 400);
        }

        $res = $this->topupService->uploadProof(
            $identifier,
            $user['id'],
            $filename,
            $contentType,
            $fileBytes
        );

        Response::json($res);
    }
}
