<?php
declare(strict_types=1);

namespace App\Models;

use App\Config\Database;

/** Reference data: goods a business type can sell. Prices are in cents. */
final class Product extends Model
{
    protected const TABLE   = 'products';
    protected const COLUMNS = ['id', 'slug', 'name', 'unit', 'base_cost_cents', 'base_price_cents', 'popularity'];

    /** Active products of one business type, most popular first. */
    public static function forBusinessType(int $businessTypeId): array
    {
        return static::normalizeAll(Database::fetchAll(
            sprintf(
                'SELECT %s FROM %s WHERE business_type_id = ? AND is_active = 1 ORDER BY popularity DESC, id',
                static::columnList(), static::table()
            ),
            [$businessTypeId]
        ));
    }
}
