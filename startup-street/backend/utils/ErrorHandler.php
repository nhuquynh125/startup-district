<?php
declare(strict_types=1);

namespace App\Utils;

use App\Config\Config;
use ErrorException;
use Throwable;

/** Turns every PHP error or uncaught exception into a JSON error response. */
final class ErrorHandler
{
    public static function register(): void
    {
        ini_set('display_errors', '0');
        error_reporting(E_ALL);

        set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            throw new ErrorException($msg, 0, $no, $file, $line);
        });
        set_exception_handler([self::class, 'handle']);
    }

    public static function handle(Throwable $e): void
    {
        error_log((string) $e);

        $details = Config::get('app.debug')
            ? ['type' => $e::class, 'message' => $e->getMessage(), 'file' => basename($e->getFile()) . ':' . $e->getLine()]
            : null;

        Response::error('Something went wrong on the server.', 500, $details);
    }
}
