<?php
declare(strict_types=1);

namespace App\Utils;

use PDOException;
use RuntimeException;

/**
 * Every database failure surfaces as this one type. The message is generic and
 * safe to log; the original PDOException (with the driver's detail) is kept as
 * the "previous" exception. Helpers let services react to specific failures
 * (for example turn a duplicate key into a 409) without parsing messages.
 */
final class DatabaseException extends RuntimeException
{
    public function __construct(string $message, ?PDOException $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** SQLSTATE such as "23000", or null when the driver gave none. */
    public function sqlState(): ?string
    {
        $previous = $this->getPrevious();
        return $previous instanceof PDOException ? ($previous->errorInfo[0] ?? null) : null;
    }

    /** MySQL/MariaDB error number such as 1062, or null. */
    public function driverCode(): ?int
    {
        $previous = $this->getPrevious();
        $code = $previous instanceof PDOException ? ($previous->errorInfo[1] ?? null) : null;
        return $code === null ? null : (int) $code;
    }

    public function isDuplicateKey(): bool        { return $this->driverCode() === 1062; }
    public function isForeignKeyViolation(): bool { return in_array($this->driverCode(), [1451, 1452], true); }
    public function isDeadlock(): bool            { return $this->driverCode() === 1213; }
    /** A value did not fit its column (for example UNSIGNED stock going below zero). */
    public function isOutOfRange(): bool          { return in_array($this->driverCode(), [1264, 1690], true); }
    /** A CHECK constraint rejected the row (MariaDB 4025, MySQL 3819). */
    public function isCheckViolation(): bool      { return in_array($this->driverCode(), [4025, 3819], true); }
}
