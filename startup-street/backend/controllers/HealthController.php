<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Config;
use App\Config\Database;
use App\Utils\Response;
use Throwable;

/** Lets the frontend (and you) confirm the API and database are reachable. */
final class HealthController
{
    public function index(): void
    {
        Response::success([
            'app'      => Config::get('app.name'),
            'env'      => Config::get('app.env'),
            'php'      => PHP_VERSION,
            'database' => $this->databaseStatus(),
            'time'     => gmdate('c'),
        ]);
    }

    private function databaseStatus(): array
    {
        try {
            Database::connection()->query('SELECT 1');
            return ['connected' => true];
        } catch (Throwable $e) {
            $status = ['connected' => false];
            if (Config::get('app.debug')) {
                $status['reason'] = $e->getMessage();
            }
            return $status;
        }
    }
}
