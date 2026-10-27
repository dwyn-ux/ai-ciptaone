<?php
declare(strict_types=1);

namespace Ciptaone\Controllers;

use Ciptaone\Core\Database;
use Ciptaone\Core\Request;
use Ciptaone\Core\Response;

/**
 * Liveness and readiness health check controller.
 */
class HealthController extends BaseController
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        parent::__construct();
        $this->db = $db ?? Database::getInstance();
    }

    public function live(Request $request): void
    {
        Response::json([
            'status' => 'ok',
            'service' => 'ai-ciptaone-router',
            'runtime' => 'php-' . PHP_VERSION,
        ]);
    }

    public function ready(Request $request): void
    {
        try {
            $row = $this->db->fetchOne('SELECT 1 as is_ready');
            if ($row !== null && (int)($row['is_ready'] ?? 0) === 1) {
                Response::json([
                    'status' => 'ready',
                    'database' => 'connected',
                ]);
                return;
            }
        } catch (\Throwable $e) {
            Response::json([
                'status' => 'not_ready',
                'database_error' => $e->getMessage(),
            ], 503);
            return;
        }

        Response::json([
            'status' => 'not_ready',
            'error' => 'Database check failed',
        ], 503);
    }
}
