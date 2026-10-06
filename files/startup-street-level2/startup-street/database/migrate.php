<?php
declare(strict_types=1);

/**
 * Database setup and migration runner.
 *
 *   php database/migrate.php            create the database, apply schema.sql and pending migrations
 *   php database/migrate.php --seed     ...and load seed.sql (game catalog data; safe to repeat)
 *   php database/migrate.php --fresh    development only: drop every table first, then rebuild
 *
 * Steps, in order:
 *   1. Create the database named by DB_NAME if it does not exist.
 *   2. Run database/schema.sql (idempotent: CREATE TABLE IF NOT EXISTS).
 *   3. Run every database/migrations/*.sql not yet recorded in schema_migrations,
 *      in filename order (001_xxx.sql, 002_xxx.sql, ...). Use migrations for any
 *      schema change made after the first release; schema.sql never alters tables.
 *   4. With --seed, run database/seed.sql inside a transaction (all or nothing).
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this script from the command line.\n");
}

require dirname(__DIR__) . '/backend/bootstrap.php';

use App\Config\Config;
use App\Config\Database;
use App\Utils\SqlScript;

$options = array_slice($argv, 1);
$unknown = array_diff($options, ['--seed', '--fresh', '--help']);
if ($unknown || in_array('--help', $options, true)) {
    fwrite($unknown ? STDERR : STDOUT, ($unknown ? 'Unknown option: ' . implode(', ', $unknown) . "\n" : '')
        . "Usage: php database/migrate.php [--seed] [--fresh]\n");
    exit($unknown ? 1 : 0);
}
$seed  = in_array('--seed', $options, true);
$fresh = in_array('--fresh', $options, true);

try {
    $pdo  = Database::connect(false);   // connect to the server first; the database may not exist yet
    $name = Database::quoteIdentifier(Database::name());

    $pdo->exec("CREATE DATABASE IF NOT EXISTS $name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE $name");

    if ($fresh) {
        if (Config::get('app.env') === 'production') {
            throw new RuntimeException('Refusing --fresh while APP_ENV=production.');
        }
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . Database::quoteIdentifier($table));
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        echo 'Dropped ' . count($tables) . " table(s).\n";
    }

    SqlScript::run($pdo, file_get_contents(__DIR__ . '/schema.sql'));
    $tableCount = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    echo "Schema: OK ($tableCount tables).\n";

    $applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files   = glob(__DIR__ . '/migrations/*.sql') ?: [];
    sort($files);

    $ran = 0;
    foreach ($files as $file) {
        $migration = basename($file);
        if (in_array($migration, $applied, true)) {
            continue;
        }
        SqlScript::run($pdo, file_get_contents($file));
        $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$migration]);
        echo "Applied: $migration\n";
        $ran++;
    }
    echo $ran === 0 ? "Database is up to date.\n" : "Done. $ran migration(s) applied.\n";

    if ($seed) {
        $pdo->beginTransaction();
        try {
            $statements = SqlScript::run($pdo, file_get_contents(__DIR__ . '/seed.sql'));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        echo "Seed: OK ($statements statements).\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
