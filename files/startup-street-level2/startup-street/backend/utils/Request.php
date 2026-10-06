<?php
declare(strict_types=1);

namespace App\Utils;

use JsonException;

/** A read-only view of the incoming HTTP request. Controllers receive one of these. */
final class Request
{
    private const MAX_BODY_BYTES = 1_048_576; // 1 MB is plenty for a JSON game API

    private ?array $json = null;

    /**
     * @param array<string, mixed>  $query   parsed query string
     * @param array<string, string> $headers lower-cased header names
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly string $body = '',
        private readonly array $headers = [],
    ) {}

    /** Build the request from PHP globals. The path is relative to "/api". */
    public static function fromGlobals(): self
    {
        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // Strip everything up to and including "/api" so routes stay deployment-independent.
        $pos  = strpos($uriPath, '/api');
        $path = $pos === false ? '/' : (substr($uriPath, $pos + 4) ?: '/');

        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > self::MAX_BODY_BYTES) {
            throw new HttpException('Request body is too large.', 413);
        }
        $body = $length > 0 ? (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1) : '';
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new HttpException('Request body is too large.', 413);
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $server => $name) {
            if (isset($_SERVER[$server])) {
                $headers[$name] = (string) $_SERVER[$server];
            }
        }

        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $_GET, $body, $headers);
    }

    public function method(): string { return $this->method; }
    public function path(): string   { return $this->path; }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** The JSON body as an array ([] when there is no body). Throws 415 / 400 for bad input. */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        if (trim($this->body) === '') {
            return $this->json = [];
        }
        if (!str_contains(strtolower($this->header('content-type', '')), 'application/json')) {
            throw new HttpException('Send the body as application/json.', 415);
        }
        try {
            $decoded = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw HttpException::badRequest('Request body is not valid JSON.');
        }
        if (!is_array($decoded)) {
            throw HttpException::badRequest('Request body must be a JSON object.');
        }
        return $this->json = $decoded;
    }

    /** One value from the JSON body. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->json()[$key] ?? $default;
    }
}
