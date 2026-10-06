<?php
declare(strict_types=1);

namespace App\Models;

use App\Config\Database;

/**
 * Base class for models: the layer that talks to the database for one table.
 * Controllers and (later) services never write SQL; they call models.
 *
 * A model sets TABLE, COLUMNS (what the API may expose) and BOOLEANS
 * (0/1 columns returned as true/false).
 */
abstract class Model
{
    protected const TABLE    = '';
    protected const COLUMNS  = ['id'];
    protected const BOOLEANS = [];

    public static function find(int $id): ?array
    {
        $row = Database::fetchOne(
            sprintf('SELECT %s FROM %s WHERE id = ?', static::columnList(), static::table()),
            [$id]
        );
        return $row === null ? null : static::normalize($row);
    }

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return static::normalizeAll(Database::fetchAll(
            sprintf('SELECT %s FROM %s ORDER BY id', static::columnList(), static::table())
        ));
    }

    protected static function table(): string
    {
        return Database::quoteIdentifier(static::TABLE);
    }

    protected static function columnList(): string
    {
        return implode(', ', array_map([Database::class, 'quoteIdentifier'], static::COLUMNS));
    }

    protected static function normalize(array $row): array
    {
        foreach (static::BOOLEANS as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = (bool) $row[$column];
            }
        }
        return $row;
    }

    protected static function normalizeAll(array $rows): array
    {
        return array_map([static::class, 'normalize'], $rows);
    }
}
