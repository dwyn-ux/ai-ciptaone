<?php
declare(strict_types=1);

namespace Decomu\Controllers;

use Decomu\Core\Request;
use Decomu\Core\Response;
use Decomu\Services\RouterService;
use Decomu\Services\WalletService;

/**
 * Controller for user profile, balance, ledger transactions, and usage history.
 */
class MeController extends BaseController
{
    private WalletService $walletService;
    private RouterService $routerService;

    public function __construct(
        ?WalletService $walletService = null,
        ?RouterService $routerService = null
    ) {
        parent::__construct();
        $this->walletService = $walletService ?? new WalletService();
        $this->routerService = $routerService ?? new RouterService();
    }

    public function getMe(Request $request): void
    {
        $user = $this->getAuthUser($request);
        Response::json($user);
    }

    public function getWallet(Request $request): void
    {
        $user = $this->getAuthUser($request);
        $wallet = $this->walletService->getWallet($user['id']);
        Response::json($wallet);
    }

    public function getTransactions(Request $request): void
    {
        $user = $this->getAuthUser($request);
        $limit = (int)$request->getQuery('limit', 20);
        $offset = (int)$request->getQuery('offset', 0);

        $res = $this->walletService->listTransactions($user['id'], $limit, $offset);
        Response::json($res);
    }

    public function getUsage(Request $request): void
    {
        $user = $this->getAuthUser($request);
        $limit = (int)$request->getQuery('limit', 20);
        $offset = (int)$request->getQuery('offset', 0);

        $res = $this->routerService->listUserUsage($user['id'], $limit, $offset);
        Response::json($res);
    }
}
