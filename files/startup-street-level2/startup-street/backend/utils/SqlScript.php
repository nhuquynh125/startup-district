<?php
declare(strict_types=1);

namespace App\Utils;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Runs a .sql file one statement at a time.
 *
 * PDO::exec() with a multi-statement string only reports an error from the
 * FIRST statement and silently ignores failures in later ones. Splitting the
 * script and running each statement separately makes every failure loud.
 *
 * The splitter understands quoted strings ('..' ".." `..`, with '' and \'
 * escapes) and strips -- # and C-style comments, so a semicolon inside text or
 * a comment never ends a statement. It does not support the DELIMITER command.
 */
final class SqlScript
{
    /** @return list<string> */
    public static function split(string $sql): array
    {
        $statements = [];
        $buffer     = '';
        $quote      = null;
        $length     = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $quote !== '`') {          // \' or \\ inside a string
                    $buffer .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    if ($next === $quote) {                      // doubled quote = literal quote
                        $buffer .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote   = $char;
                $buffer .= $char;
            } elseif ($char === '#' || ($char === '-' && $next === '-' && self::isBlank($sql[$i + 2] ?? "\n"))) {
                $end = strpos($sql, "\n", $i);                   // line comment: skip to end of line
                $i   = $end === false ? $length : $end;
                $buffer .= ' ';
            } elseif ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);               // block comment
                $i   = $end === false ? $length : $end + 1;
                $buffer .= ' ';
            } elseif ($char === ';') {
                self::push($statements, $buffer);
                $buffer = '';
            } else {
                $buffer .= $char;
            }
        }
        self::push($statements, $buffer);

        return $statements;
    }

    /** Run every statement; returns how many ran. Throws on the first failure. */
    public static function run(PDO $pdo, string $sql): int
    {
        $count = 0;
        foreach (self::split($sql) as $statement) {
            try {
                // query() + closeCursor() instead of exec(): a statement that returns rows
                // (SELECT, SHOW) would otherwise leave an unread result set on the connection.
                $pdo->query($statement)->closeCursor();
            } catch (PDOException $e) {
                $preview = preg_replace('/\s+/', ' ', substr($statement, 0, 120));
                throw new RuntimeException("SQL failed: {$e->getMessage()}\n  in statement: $preview...", 0, $e);
            }
            $count++;
        }
        return $count;
    }

    private static function push(array &$statements, string $buffer): void
    {
        $buffer = trim($buffer);
        if ($buffer !== '') {
            $statements[] = $buffer;
        }
    }

    private static function isBlank(string $char): bool
    {
        return $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r";
    }
}
