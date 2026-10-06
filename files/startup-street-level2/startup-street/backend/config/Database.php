<?php
declare(strict_types=1);

namespace App\Config;

use App\Utils\DatabaseException;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * The only place that talks to MySQL.
 *
 *  - One lazy PDO connection (Database::connection()); nothing connects until needed.
 *  - Every value goes through prepared statements; identifiers (table/column
 *    names) are validated and quoted, never taken from user input.
 *  - Every failure is a DatabaseException (credentials never leave this class).
 *  - Database::transaction() gives atomic blocks with nesting (savepoints)
 *    and an automatic retry when MySQL reports a deadlock.
 */
final class Database
{
    /** Strict mode: bad data is rejected instead of silently truncated. */
    private const SQL_MODE = 'STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,'
                           . 'NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';
    private const DEADLOCK_ATTEMPTS = 3;

    private static ?PDO $pdo = null;
    private static int $depth = 0;

    /* ---------- Connection ---------- */

    /** Shared connection for the current request. */
    public static function connection(): PDO
    {
        return self::$pdo ??= self::connect();
    }

    /**
     * Open a new, uncached connection. Pass false to connect to the server
     * without selecting a database (used by migrate.php to create it).
     */
    public static function connect(bool $selectDatabase = true): PDO
    {
        $db  = Config::get('db');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            $db['host'], $db['port'], self::checkName((string) $db['charset'], 'charset')
        );
        if ($selectDatabase) {
            $dsn .= ';dbname=' . self::name();
        }

        try {
            $pdo = new PDO($dsn, (string) $db['user'], (string) $db['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,   // real server-side prepared statements
                PDO::ATTR_STRINGIFY_FETCHES  => false,   // ints stay ints
                PDO::ATTR_TIMEOUT            => 5,
            ]);
            // Every timestamp in the database is UTC.
            $pdo->exec("SET time_zone = '+00:00', sql_mode = '" . self::SQL_MODE . "'");
            return $pdo;
        } catch (PDOException $e) {
            throw new DatabaseException('Could not connect to the database.', $e);
        }
    }

    /** Close the shared connection (the next call reconnects). */
    public static function disconnect(): void
    {
        self::$pdo   = null;
        self::$depth = 0;
    }

    /** Configured database name, validated so it is safe to place in SQL. */
    public static function name(): string
    {
        return self::checkName((string) Config::get('db.name'), 'database name');
    }

    /** Backtick-quote a table or column name after validating it. */
    public static function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $identifier)) {
            throw new InvalidArgumentException("Invalid SQL identifier: $identifier");
        }
        return '`' . $identifier . '`';
    }

    private static function checkName(string $value, string $label): string
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $value)) {
            throw new InvalidArgumentException("Invalid $label in configuration.");
        }
        return $value;
    }

    /* ---------- Queries (always prepared) ---------- */

    /**
     * Run a prepared statement. Use "?" placeholders with a list, or ":name"
     * placeholders with an associative array.
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $statement = self::connection()->prepare($sql);
            foreach ($params as $key => $value) {
                $statement->bindValue(
                    is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':'),
                    $value,
                    self::paramType($value)
                );
            }
            $statement->execute();
            return $statement;
        } catch (PDOException $e) {
            throw new DatabaseException('Database query failed.', $e);
        }
    }

    /** @return list<array<string, mixed>> */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** First row, or null when nothing matched. */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** First column of the first row, or null. */
    public static function fetchValue(string $sql, array $params = []): mixed
    {
        $value = self::query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** Run INSERT/UPDATE/DELETE and return the number of changed rows. */
    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /** INSERT one row from [column => value] and return its auto-increment id. */
    public static function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new InvalidArgumentException('insert() needs at least one column.');
        }
        $columns = array_map([self::class, 'quoteIdentifier'], array_keys($data));
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::quoteIdentifier($table),
            implode(', ', $columns),
            implode(', ', array_fill(0, count($data), '?'))
        );
        self::query($sql, array_values($data));
        return (int) self::connection()->lastInsertId();
    }

    /**
     * UPDATE rows matching every [column => value] in $where (null means IS NULL).
     * An empty $where is refused so a typo can never rewrite a whole table.
     */
    public static function update(string $table, array $data, array $where): int
    {
        if ($data === [] || $where === []) {
            throw new InvalidArgumentException('update() needs both data and a WHERE condition.');
        }
        $params = [];
        $set = [];
        foreach ($data as $column => $value) {
            $set[]    = self::quoteIdentifier($column) . ' = ?';
            $params[] = $value;
        }
        $conditions = [];
        foreach ($where as $column => $value) {
            if ($value === null) {
                $conditions[] = self::quoteIdentifier($column) . ' IS NULL';
            } else {
                $conditions[] = self::quoteIdentifier($column) . ' = ?';
                $params[]     = $value;
            }
        }
        return self::execute(
            sprintf('UPDATE %s SET %s WHERE %s', self::quoteIdentifier($table), implode(', ', $set), implode(' AND ', $conditions)),
            $params
        );
    }

    /* ---------- Transactions ---------- */

    public static function inTransaction(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Run $callback atomically: commit when it returns, roll back when it throws.
     *
     *  - Nested calls become savepoints, so an inner failure only undoes the inner work.
     *  - If MySQL picks this transaction as a deadlock victim, the whole callback is
     *    run again (up to 3 attempts). Keep callbacks free of side effects outside the
     *    database (emails, files) for that reason.
     *  - DDL statements (CREATE/ALTER/DROP) commit implicitly in MySQL; never use them here.
     *
     * Inside a callback, lock rows you are about to change with SELECT ... FOR UPDATE.
     */
    public static function transaction(callable $callback): mixed
    {
        if (self::$depth > 0) {
            return self::savepoint($callback);
        }

        for ($attempt = 1; ; $attempt++) {
            $pdo = self::connection();
            try {
                $pdo->beginTransaction();
                self::$depth = 1;
                $result = $callback($pdo);
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    try { $pdo->rollBack(); } catch (PDOException) { /* connection already gone */ }
                }
                if ($e instanceof PDOException) {
                    $e = new DatabaseException('Database transaction failed.', $e);
                }
                if ($e instanceof DatabaseException && $e->isDeadlock() && $attempt < self::DEADLOCK_ATTEMPTS) {
                    usleep(random_int(20, 80) * 1000 * $attempt);
                    continue;
                }
                throw $e;
            } finally {
                self::$depth = 0;
            }
        }
    }

    private static function savepoint(callable $callback): mixed
    {
        $level = self::$depth;
        $name  = 'sp_' . $level;

        self::run("SAVEPOINT $name");
        self::$depth = $level + 1;
        try {
            $result = $callback(self::connection());
            self::$depth = $level;
            self::run("RELEASE SAVEPOINT $name");
            return $result;
        } catch (Throwable $e) {
            self::$depth = $level;
            try { self::run("ROLLBACK TO SAVEPOINT $name"); } catch (Throwable) { /* the outer transaction will roll back */ }
            throw $e;
        }
    }

    private static function run(string $sql): void
    {
        try {
            self::connection()->exec($sql);
        } catch (PDOException $e) {
            throw new DatabaseException('Database statement failed.', $e);
        }
    }

    private static function paramType(mixed $value): int
    {
        return match (true) {
            $value === null => PDO::PARAM_NULL,
            is_bool($value) => PDO::PARAM_BOOL,
            is_int($value)  => PDO::PARAM_INT,
            default         => PDO::PARAM_STR,
        };
    }
}
