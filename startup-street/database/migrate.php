<?php
declare(strict_types=1);

/**
 * Migration runner.   Usage:  php database/migrate.php
 *
 * Runs every database/migrations/*.sql file that has not been applied yet,
 * in filename order (name them 001_xxx.sql, 002_xxx.sql, ...), and records
 * each one in schema_migrations. Run it again any time: applied files are skipped.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this script from the command line.\n");
}

$root = dirname(__DIR__);
foreach (['Env', 'Config'] as $class) {
    require "$root/backend/config/$class.php";
}

use App\Config\Config;
use App\Config\Env;

Env::load("$root/.env");
Config::load(require "$root/backend/config/settings.php");
$db = Config::get('db');

try {
    // Connect without a database first so schema.sql can create it.
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=%s', $db['host'], $db['port'], $db['charset']),
        $db['user'], $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
    $pdo->exec('USE `' . str_replace('`', '', $db['name']) . '`');

    $applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files   = glob(__DIR__ . '/migrations/*.sql') ?: [];
    sort($files);

    $ran = 0;
    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, $applied, true)) {
            continue;
        }
        $pdo->exec(file_get_contents($file));
        $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$name]);
        echo "Applied: $name\n";
        $ran++;
    }
    echo $ran === 0 ? "Database is up to date.\n" : "Done. $ran migration(s) applied.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
