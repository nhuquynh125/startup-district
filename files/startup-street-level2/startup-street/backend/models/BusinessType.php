<?php
declare(strict_types=1);

namespace App\Models;

use App\Config\Database;

/** Reference data: the kinds of shop a player can open (bubble tea, bakery, ...). */
final class BusinessType extends Model
{
    protected const TABLE    = 'business_types';
    protected const COLUMNS  = [
        'id', 'slug', 'name', 'description', 'tone_color',
        'startup_cost_cents', 'base_capacity', 'base_staff_slots', 'min_player_level',
    ];

    /** Active types in display order. */
    public static function active(): array
    {
        return static::normalizeAll(Database::fetchAll(
            sprintf('SELECT %s FROM %s WHERE is_active = 1 ORDER BY sort_order, id', static::columnList(), static::table())
        ));
    }

    public static function findBySlug(string $slug): ?array
    {
        $row = Database::fetchOne(
            sprintf('SELECT %s FROM %s WHERE slug = ? AND is_active = 1', static::columnList(), static::table()),
            [$slug]
        );
        return $row === null ? null : static::normalize($row);
    }
}
