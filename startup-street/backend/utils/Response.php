<?php
declare(strict_types=1);

namespace App\Utils;

/** Every API reply uses one JSON envelope: { success, data | error }. */
final class Response
{
    public static function success(mixed $data = null, int $status = 200): never
    {
        self::send(['success' => true, 'data' => $data], $status);
    }

    public static function error(string $message, int $status = 400, ?array $details = null): never
    {
        $error = ['message' => $message, 'status' => $status];
        if ($details !== null) {
            $error['details'] = $details;
        }
        self::send(['success' => false, 'error' => $error], $status);
    }

    private static function send(array $payload, int $status): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
