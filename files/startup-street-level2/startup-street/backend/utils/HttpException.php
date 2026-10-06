<?php
declare(strict_types=1);

namespace App\Utils;

use RuntimeException;
use Throwable;

/**
 * Throw this anywhere (controller, service, middleware) to end the request with
 * a JSON error. ErrorHandler turns it into the standard error envelope.
 *
 *   throw HttpException::notFound('Shop not found.');
 *   throw HttpException::unprocessable('Invalid input.', ['price' => 'Must be positive.']);
 */
final class HttpException extends RuntimeException
{
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthorized',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        413 => 'payload_too_large',
        415 => 'unsupported_media_type',
        422 => 'validation_failed',
        429 => 'too_many_requests',
        500 => 'server_error',
    ];

    public function __construct(
        string $message,
        private readonly int $status = 400,
        private readonly ?string $errorCode = null,
        private readonly ?array $details = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Machine-readable code for a status, e.g. 404 -> "not_found". */
    public static function codeFor(int $status): string
    {
        return self::CODES[$status] ?? 'error';
    }

    public function status(): int        { return $this->status; }
    public function errorCode(): string  { return $this->errorCode ?? self::codeFor($this->status); }
    public function details(): ?array    { return $this->details; }

    public static function badRequest(string $message = 'Bad request.', ?array $details = null): self
    {
        return new self($message, 400, null, $details);
    }

    public static function unauthorized(string $message = 'Authentication required.'): self
    {
        return new self($message, 401);
    }

    public static function forbidden(string $message = 'You are not allowed to do that.'): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self($message, 404);
    }

    public static function methodNotAllowed(string $message = 'Method not allowed.'): self
    {
        return new self($message, 405);
    }

    public static function conflict(string $message = 'Conflict.', ?array $details = null): self
    {
        return new self($message, 409, null, $details);
    }

    public static function unprocessable(string $message = 'Validation failed.', ?array $details = null): self
    {
        return new self($message, 422, null, $details);
    }
}
