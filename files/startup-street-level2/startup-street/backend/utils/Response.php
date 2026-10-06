<?php
declare(strict_types=1);

namespace App\Utils;

/**
 * Every API reply uses one JSON envelope with the same top-level keys.
 *
 *   success: { "success": true,  "data": <any>, "message": null | "text" }
 *   error:   { "success": false, "data": null,  "message": "text",
 *              "error": { "code": "not_found", "status": 404, "message": "text", "details"?: {...} } }
 *
 * "error.message" repeats the top-level "message" so the Level 1 frontend
 * client (api.js reads payload.error.message) keeps working unchanged.
 */
final class Response
{
    public static function success(mixed $data = null, int $status = 200, ?string $message = null): never
    {
        self::send(['success' => true, 'data' => $data, 'message' => $message], $status);
    }

    public static function created(mixed $data = null, ?string $message = null): never
    {
        self::success($data, 201, $message);
    }

    public static function error(string $message, int $status = 400, ?array $details = null, ?string $code = null): never
    {
        $error = [
            'code'    => $code ?? HttpException::codeFor($status),
            'status'  => $status,
            'message' => $message,
        ];
        if ($details !== null) {
            $error['details'] = $details;
        }
        self::send(['success' => false, 'data' => null, 'message' => $message, 'error' => $error], $status);
    }

    private static function send(array $payload, int $status): never
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) { // e.g. NAN in a float; never send an empty body
            $status = 500;
            $json   = '{"success":false,"data":null,"message":"Could not encode the response.",'
                    . '"error":{"code":"server_error","status":500,"message":"Could not encode the response."}}';
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo $json;
        exit;
    }
}
