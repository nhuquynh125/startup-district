<?php
declare(strict_types=1);

/**
 * Level 2 checks. No Composer or PHPUnit needed.
 *
 *   php tests/run.php                                  database, schema, seed, router
 *   php tests/run.php --base-url=http://localhost:8000  ...plus live HTTP checks of the API
 *
 * Needs a migrated and seeded database:  php database/migrate.php --seed
 * Every write happens inside a transaction that is rolled back, so the
 * database is left exactly as it was found (no test accounts are kept).
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this script from the command line.\n");
}

require dirname(__DIR__) . '/backend/bootstrap.php';

use App\Config\Database;
use App\Routes\Router;
use App\Utils\DatabaseException;
use App\Utils\HttpException;
use App\Utils\Request;
use App\Utils\SqlScript;

/* ---------- Tiny harness ---------- */
$passed = 0;
$failed = 0;

function section(string $title): void
{
    echo "\n$title\n";
}

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  PASS  $name\n";
    } else {
        $failed++;
        echo "  FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}

/** Run $fn and return the exception it throws (null if it does not throw). */
function thrown(callable $fn): ?Throwable
{
    try {
        $fn();
        return null;
    } catch (Throwable $e) {
        return $e;
    }
}

/** Run $fn inside a transaction that is always rolled back. */
function rolledBack(callable $fn): void
{
    $pdo = Database::connection();
    $pdo->beginTransaction();
    try {
        $fn();
    } finally {
        $pdo->rollBack();
    }
}

function count_rows(string $table): int
{
    return (int) Database::fetchValue('SELECT COUNT(*) FROM ' . Database::quoteIdentifier($table));
}

function make_user(string $suffix): int
{
    $id = Database::insert('users', [
        'email'         => "test-$suffix@example.test",
        'username'      => "test_$suffix",
        'password_hash' => 'not-a-real-hash',
    ]);
    Database::insert('player_profiles', ['user_id' => $id, 'display_name' => "Tester $suffix", 'cash_cents' => 100000]);
    return $id;
}

function make_shop(int $userId, string $propertyCode = 'main-01', string $typeSlug = 'cafe'): int
{
    return Database::insert('shops', [
        'user_id'            => $userId,
        'property_id'        => (int) Database::fetchValue('SELECT id FROM properties WHERE code = ?', [$propertyCode]),
        'business_type_id'   => (int) Database::fetchValue('SELECT id FROM business_types WHERE slug = ?', [$typeSlug]),
        'name'               => 'Test Shop',
        'rent_per_day_cents' => 3000,
        'opened_day'         => 1,
    ]);
}

/* ---------- 1. Connection ---------- */
section('1. MySQL connection and PHP database layer');

$version = null;
try {
    $version = Database::fetchValue('SELECT VERSION()');
} catch (Throwable $e) {
    echo "  Cannot connect: {$e->getMessage()}\n  " . ($e->getPrevious()?->getMessage() ?? '') . "\n";
    echo "  Start MySQL, copy .env.example to .env, then run: php database/migrate.php --seed\n";
    exit(1);
}
check('connects through Database::connection()', $version !== null, (string) $version);
check('connection is cached (same PDO object)', Database::connection() === Database::connection());
check('session time zone is UTC', Database::fetchValue('SELECT @@session.time_zone') === '+00:00');
check('strict SQL mode is on', str_contains((string) Database::fetchValue('SELECT @@session.sql_mode'), 'STRICT_ALL_TABLES'));
check('fetchValue / fetchOne / fetchAll work', Database::fetchValue('SELECT 1 + 1') === 2
    && Database::fetchOne('SELECT 7 AS n') === ['n' => 7]
    && Database::fetchOne('SELECT 1 FROM business_types WHERE id = -1') === null
    && count(Database::fetchAll('SELECT id FROM business_types')) === 6);
check('integers come back as integers (not strings)', is_int(Database::fetchValue('SELECT COUNT(*) FROM products')));

$evil = "x'; DROP TABLE users; --";
check('prepared statements treat SQL in values as plain text',
    Database::fetchValue('SELECT ?', [$evil]) === $evil
    && Database::fetchValue('SELECT :v', ['v' => $evil]) === $evil
    && count_rows('users') >= 0);
check('typed binding: ints work in LIMIT', count(Database::fetchAll('SELECT id FROM products ORDER BY id LIMIT ?', [3])) === 3);
check('identifiers are validated', thrown(fn () => Database::quoteIdentifier('users; DROP TABLE users')) instanceof InvalidArgumentException);
check('update() refuses an empty WHERE', thrown(fn () => Database::update('users', ['status' => 'active'], [])) instanceof InvalidArgumentException);

$e = thrown(fn () => Database::query('SELECT * FROM table_that_does_not_exist'));
check('errors surface as DatabaseException with a generic message',
    $e instanceof DatabaseException && $e->getMessage() === 'Database query failed.' && $e->getPrevious() instanceof PDOException);

/* ---------- 2. Schema ---------- */
section('2. Schema');

$expected = [
    'schema_migrations', 'users', 'player_profiles', 'business_types', 'properties', 'products',
    'customer_segments', 'customer_segment_affinities', 'employees', 'upgrades', 'marketing_campaigns',
    'events', 'event_effects', 'competitors', 'achievements', 'shops', 'shop_products', 'shop_employees',
    'shop_upgrades', 'shop_campaigns', 'shop_customers', 'player_events', 'user_achievements',
    'transactions', 'notifications', 'ai_advice',
];
$actual  = Database::fetchAll('SELECT table_name AS t, engine, table_collation AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = ?', ['BASE TABLE']);
$names   = array_column($actual, 't');
$missing = array_values(array_diff($expected, $names));
check('all ' . count($expected) . ' expected tables exist', $missing === [], 'missing: ' . implode(', ', $missing));
check('no unexpected tables', array_values(array_diff($names, $expected)) === [], implode(', ', array_diff($names, $expected)));
check('every table is InnoDB', count(array_filter($actual, fn ($r) => strcasecmp((string) $r['engine'], 'InnoDB') !== 0)) === 0);
check('every table is utf8mb4', count(array_filter($actual, fn ($r) => !str_starts_with((string) $r['c'], 'utf8mb4'))) === 0);
check('every table has a primary key', (int) Database::fetchValue(
    "SELECT COUNT(*) FROM information_schema.tables t WHERE t.table_schema = DATABASE() AND t.table_type = 'BASE TABLE'
       AND NOT EXISTS (SELECT 1 FROM information_schema.table_constraints c
                        WHERE c.table_schema = t.table_schema AND c.table_name = t.table_name AND c.constraint_type = 'PRIMARY KEY')") === 0);
$fkCount = (int) Database::fetchValue("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND constraint_type = 'FOREIGN KEY'");
$declared = preg_match_all('/FOREIGN KEY/', file_get_contents(APP_ROOT . '/database/schema.sql'));
check('every foreign key declared in schema.sql exists in the database', $fkCount === $declared && $fkCount > 0, "$fkCount in database, $declared in schema.sql");
check('every foreign key has a supporting index', (int) Database::fetchValue(
    "SELECT COUNT(*) FROM information_schema.key_column_usage k
      WHERE k.table_schema = DATABASE() AND k.referenced_table_name IS NOT NULL AND k.ordinal_position = 1
        AND NOT EXISTS (SELECT 1 FROM information_schema.statistics s
                         WHERE s.table_schema = k.table_schema AND s.table_name = k.table_name
                           AND s.column_name = k.column_name AND s.seq_in_index = 1)") === 0);
check('all money columns are integers (*_cents)', (int) Database::fetchValue(
    "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
        AND column_name LIKE '%\\_cents' AND data_type NOT IN ('int','bigint')") === 0);

/* ---------- 3. Seed data ---------- */
section('3. Seed data');

$counts = [
    'business_types' => 6, 'properties' => 8, 'customer_segments' => 5, 'customer_segment_affinities' => 30,
    'upgrades' => 11, 'events' => 12, 'competitors' => 12, 'marketing_campaigns' => 5, 'achievements' => 8,
];
foreach ($counts as $table => $n) {
    check("$table has $n rows", count_rows($table) === $n, (string) count_rows($table));
}
check('products seeded (31)', count_rows('products') === 31);
check('event_effects seeded (18)', count_rows('event_effects') === 18);
check('no player accounts were seeded', count_rows('users') === 0 && count_rows('player_profiles') === 0 && count_rows('shops') === 0);
check('segment population shares add up to 100', (float) Database::fetchValue('SELECT SUM(population_share_pct) FROM customer_segments') === 100.0);
check('every business type has products', (int) Database::fetchValue(
    'SELECT COUNT(*) FROM business_types b WHERE NOT EXISTS (SELECT 1 FROM products p WHERE p.business_type_id = b.id)') === 0);
check('every business type has competitors', (int) Database::fetchValue(
    'SELECT COUNT(*) FROM business_types b WHERE NOT EXISTS (SELECT 1 FROM competitors c WHERE c.business_type_id = b.id)') === 0);
check('every segment has an affinity for every business type', (int) Database::fetchValue(
    'SELECT COUNT(*) FROM customer_segments s CROSS JOIN business_types b
      WHERE NOT EXISTS (SELECT 1 FROM customer_segment_affinities a WHERE a.segment_id = s.id AND a.business_type_id = b.id)') === 0);
check('street slots are unique and consecutive', Database::fetchAll('SELECT street_slot FROM properties ORDER BY street_slot') === array_map(fn ($i) => ['street_slot' => $i], range(1, 8)));
check('UTF-8 survives the round trip (Café)', Database::fetchValue("SELECT name FROM business_types WHERE slug = 'cafe'") === 'Café');
check("apostrophes in seed text are intact (Valentine's Day)", Database::fetchValue("SELECT name FROM events WHERE code = 'valentines-day'") === "Valentine's Day");

$before = array_map('count_rows', array_keys($counts + ['products' => 0, 'event_effects' => 0]));
rolledBack(function () use ($counts, $before): void {
    SqlScript::run(Database::connection(), file_get_contents(APP_ROOT . '/database/seed.sql'));
    $after = array_map('count_rows', array_keys($counts + ['products' => 0, 'event_effects' => 0]));
    check('running seed.sql again creates no duplicates', $before === $after);
});

/* ---------- 4. SQL script splitter ---------- */
section('4. SQL script splitter');

$statements = SqlScript::split("-- comment; with semicolon\nSELECT 'a;b' AS x; /* block; comment */ SELECT \"q;\"\"q\"; # hash;\nSELECT `c;d`, 'it''s; ok', 'back\\'slash;';\n  ;\nSELECT 5");
check('splits on real semicolons only', count($statements) === 4, (string) count($statements));
check('keeps quoted semicolons and escaped quotes', str_contains($statements[0], "'a;b'") && str_contains($statements[2], "'it''s; ok'") && str_contains($statements[2], "'back\\'slash;'"));
check('drops comments and empty statements', !str_contains(implode('', $statements), 'comment') && end($statements) === 'SELECT 5');
$e = thrown(fn () => SqlScript::run(Database::connection(), 'SELECT 1; SELECT * FROM nope_missing; SELECT 2'));
check('a failure in a LATER statement is reported (PDO::exec alone would miss it)', $e instanceof RuntimeException && str_contains($e->getMessage(), 'nope_missing'));

/* ---------- 5. Transactions ---------- */
section('5. Transactions');

$uid = null;
$result = Database::transaction(function () use (&$uid) {
    $uid = make_user('commit_a');
    return 'done';
});
check('transaction() commits and returns the callback result', $result === 'done' && Database::fetchValue('SELECT COUNT(*) FROM users WHERE id = ?', [$uid]) === 1);
Database::execute('DELETE FROM users WHERE id = ?', [$uid]); // clean up the committed test row
check('test account removed again', count_rows('users') === 0 && count_rows('player_profiles') === 0);

$e = thrown(fn () => Database::transaction(function () {
    make_user('rollback_a');
    throw new RuntimeException('boom');
}));
check('an exception rolls everything back', $e?->getMessage() === 'boom' && count_rows('users') === 0 && !Database::inTransaction());

$e = thrown(fn () => Database::transaction(function () {
    make_user('outer');
    $inner = thrown(fn () => Database::transaction(function () {
        make_user('inner');
        throw new RuntimeException('inner failed');
    }));
    check('nested failure is contained (savepoint)', $inner?->getMessage() === 'inner failed' && Database::inTransaction());
    Database::transaction(fn () => make_user('inner_ok'));
    check('outer work and successful inner work are kept until the end', count_rows('users') === 2);
    throw new RuntimeException('abort all');
}));
check('aborting the outer transaction undoes inner work too', $e?->getMessage() === 'abort all' && count_rows('users') === 0 && !Database::inTransaction());

/* ---------- 6. Constraints keep game data safe ---------- */
section('6. Constraints (every write below is rolled back)');

rolledBack(function (): void {
    $a = make_user('a');
    $b = make_user('b');

    $e = thrown(fn () => make_user('a'));
    check('duplicate email/username is rejected (isDuplicateKey)', $e instanceof DatabaseException && $e->isDuplicateKey());

    $e = thrown(fn () => Database::insert('player_profiles', ['user_id' => 999999, 'display_name' => 'Ghost']));
    check('profile without a user is rejected (isForeignKeyViolation)', $e instanceof DatabaseException && $e->isForeignKeyViolation());

    $e = thrown(fn () => Database::update('player_profiles', ['cash_cents' => -1], ['user_id' => $a]));
    check('cash can never go negative (CHECK)', $e instanceof DatabaseException && $e->isCheckViolation(), (string) $e?->getPrevious()?->getMessage());

    $shopA = make_shop($a, 'main-01');
    check('a shop can be created for a user and a lot', $shopA > 0);

    $e = thrown(fn () => make_shop($a, 'main-01'));
    check('same player cannot rent the same lot twice while it is open', $e instanceof DatabaseException && $e->isDuplicateKey());

    check('another player can rent the same lot (every player has their own street)', make_shop($b, 'main-01') > 0);

    Database::update('shops', ['status' => 'closed', 'closed_day' => 5], ['id' => $shopA]);
    check('after closing, the lot can be rented again', make_shop($a, 'main-01') > 0);
    check('closed shop stays in the table for the ledger', Database::fetchValue('SELECT status FROM shops WHERE id = ?', [$shopA]) === 'closed');

    $shop = make_shop($a, 'main-02', 'bakery');
    $croissant = (int) Database::fetchValue("SELECT id FROM products WHERE slug = 'croissant'");
    Database::insert('shop_products', ['shop_id' => $shop, 'product_id' => $croissant, 'sell_price_cents' => 350, 'stock_quantity' => 10]);

    $e = thrown(fn () => Database::execute('UPDATE shop_products SET stock_quantity = stock_quantity - 11 WHERE shop_id = ?', [$shop]));
    check('selling more than the stock is rejected (UNSIGNED underflow)', $e instanceof DatabaseException && $e->isOutOfRange(), (string) $e?->getPrevious()?->getMessage());
    check('stock is unchanged after the rejected sale', Database::fetchValue('SELECT stock_quantity FROM shop_products WHERE shop_id = ?', [$shop]) === 10);

    $e = thrown(fn () => Database::insert('shop_products', ['shop_id' => $shop, 'product_id' => $croissant, 'sell_price_cents' => 1]));
    check('a product is listed once per shop (composite primary key)', $e instanceof DatabaseException && $e->isDuplicateKey());

    $e = thrown(fn () => Database::insert('shops', [
        'user_id' => $a, 'property_id' => 999999, 'business_type_id' => 1, 'name' => 'x', 'rent_per_day_cents' => 1, 'opened_day' => 1]));
    check('shop on a non-existent lot is rejected', $e instanceof DatabaseException && $e->isForeignKeyViolation());

    // Ledger rows must point at a shop owned by the same user.
    Database::insert('transactions', ['user_id' => $a, 'shop_id' => $shop, 'type' => 'rent', 'amount_cents' => -3000, 'balance_after_cents' => 97000, 'game_day' => 1]);
    check('ledger row for your own shop is accepted', true);
    Database::insert('transactions', ['user_id' => $a, 'shop_id' => null, 'type' => 'initial_funds', 'amount_cents' => 100000, 'balance_after_cents' => 100000, 'game_day' => 1]);
    check('player-level ledger row (no shop) is accepted', true);
    $e = thrown(fn () => Database::insert('transactions', ['user_id' => $b, 'shop_id' => $shop, 'type' => 'rent', 'amount_cents' => -3000, 'balance_after_cents' => 1, 'game_day' => 1]));
    check("ledger row pointing at another player's shop is rejected", $e instanceof DatabaseException && $e->isForeignKeyViolation());
    $e = thrown(fn () => Database::insert('transactions', ['user_id' => $a, 'type' => 'other', 'amount_cents' => 0, 'balance_after_cents' => 1, 'game_day' => 1]));
    check('zero-amount ledger row is rejected (CHECK)', $e instanceof DatabaseException && $e->isCheckViolation());

    $e = thrown(fn () => Database::execute('DELETE FROM products WHERE id = ?', [$croissant]));
    check('catalog rows in use cannot be deleted (RESTRICT)', $e instanceof DatabaseException && $e->isForeignKeyViolation());

    Database::insert('notifications', ['user_id' => $a, 'title' => 'Hello', 'data' => json_encode(['shop_id' => $shop])]);
    Database::insert('ai_advice', ['user_id' => $a, 'shop_id' => $shop, 'advice' => 'Raise prices a little.', 'context' => json_encode(['cash' => 97000])]);
    Database::insert('user_achievements', ['user_id' => $a, 'achievement_id' => (int) Database::fetchValue("SELECT id FROM achievements WHERE code = 'first-shop'")]);
    $json = Database::fetchValue('SELECT data FROM notifications WHERE user_id = ?', [$a]);
    check('JSON columns store and return structured data', json_decode((string) $json, true) === ['shop_id' => $shop]);

    Database::execute('DELETE FROM users WHERE id = ?', [$a]);
    $left = (int) Database::fetchValue(
        'SELECT (SELECT COUNT(*) FROM shops WHERE user_id = ?) + (SELECT COUNT(*) FROM transactions WHERE user_id = ?)
              + (SELECT COUNT(*) FROM notifications WHERE user_id = ?) + (SELECT COUNT(*) FROM ai_advice WHERE user_id = ?)
              + (SELECT COUNT(*) FROM user_achievements WHERE user_id = ?) + (SELECT COUNT(*) FROM player_profiles WHERE user_id = ?)
              + (SELECT COUNT(*) FROM shop_products WHERE shop_id = ?)',
        [$a, $a, $a, $a, $a, $a, $shop]);
    check('deleting a user removes everything they own (cascade)', $left === 0, "$left rows left");
    check("other players' data is untouched by that delete", (int) Database::fetchValue('SELECT COUNT(*) FROM shops WHERE user_id = ?', [$b]) === 1);
});
check('all test rows were rolled back', count_rows('users') === 0 && count_rows('shops') === 0 && count_rows('transactions') === 0);

/* ---------- 7. Router ---------- */
section('7. Router');

$router = new Router();
$router->get('/ping', fn () => 'pong');
$router->group('/shops', function (Router $r): void {
    $r->get('/{id:int}', fn (Request $req, array $p) => 'shop ' . $p['id']);
    $r->post('/{id:int}/close', fn () => 'closed');
    $r->get('/by/{slug:slug}', fn (Request $req, array $p) => 'slug ' . $p['slug']);
});
$trace = [];
$router->group('/secure', function (Router $r) use (&$trace): void {
    $r->get('/data', function () use (&$trace) { $trace[] = 'handler'; return 'secret'; });
}, [
    function (Request $req, callable $next) use (&$trace) { $trace[] = 'outer'; return $next($req); },
    function (Request $req, callable $next) use (&$trace) { $trace[] = 'inner'; return $next($req); },
]);
$router->get('/locked', fn () => 'never', [fn (Request $req, callable $next) => throw HttpException::unauthorized()]);

check('simple route', $router->dispatch(new Request('GET', '/ping')) === 'pong');
check('trailing slash is ignored', $router->dispatch(new Request('GET', '/ping/')) === 'pong');
check('group prefix + typed path parameter', $router->dispatch(new Request('GET', '/shops/42')) === 'shop 42');
check('POST route in a group', $router->dispatch(new Request('POST', '/shops/7/close')) === 'closed');
check('slug parameter', $router->dispatch(new Request('GET', '/shops/by/corner-cafe')) === 'slug corner-cafe');
$e = thrown(fn () => $router->dispatch(new Request('GET', '/shops/abc')));
check('int parameter rejects letters (404)', $e instanceof HttpException && $e->status() === 404);
$e = thrown(fn () => $router->dispatch(new Request('GET', '/shops/by/Bad_Slug')));
check('slug parameter rejects invalid characters (404)', $e instanceof HttpException && $e->status() === 404);
$e = thrown(fn () => $router->dispatch(new Request('DELETE', '/ping')));
check('known path, wrong method -> 405', $e instanceof HttpException && $e->status() === 405 && $e->errorCode() === 'method_not_allowed');
$e = thrown(fn () => $router->dispatch(new Request('GET', '/nope')));
check('unknown path -> 404', $e instanceof HttpException && $e->status() === 404 && $e->errorCode() === 'not_found');
check('middleware runs in order around the handler', $router->dispatch(new Request('GET', '/secure/data')) === 'secret' && $trace === ['outer', 'inner', 'handler']);
$e = thrown(fn () => $router->dispatch(new Request('GET', '/locked')));
check('middleware can stop a request with an HttpException', $e instanceof HttpException && $e->status() === 401);

$e = thrown(fn () => (new Request('POST', '/x', [], '{"a":', ['content-type' => 'application/json']))->json());
check('invalid JSON body -> 400', $e instanceof HttpException && $e->status() === 400);
$e = thrown(fn () => (new Request('POST', '/x', [], 'a=1', ['content-type' => 'text/plain']))->json());
check('non-JSON content type -> 415', $e instanceof HttpException && $e->status() === 415);
check('valid JSON body is readable', (new Request('POST', '/x', [], '{"price":450}', ['content-type' => 'application/json; charset=utf-8']))->input('price') === 450);

/* ---------- 8. Live API (optional) ---------- */
$baseUrl = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--base-url=')) {
        $baseUrl = rtrim(substr($arg, 11), '/');
    }
}

/** @return array{status:int, headers:array<string,string>, body:?array, raw:string} */
function http(string $method, string $url, ?string $body = null, array $headers = []): array
{
    $context = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 10,
        'header' => implode("\r\n", $headers), 'content' => $body ?? '',
    ]]);
    $raw = (string) @file_get_contents($url, false, $context);
    $status = 0;
    $parsed = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        } elseif (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $parsed[strtolower(trim($k))] = trim($v);
        }
    }
    return ['status' => $status, 'headers' => $parsed, 'body' => json_decode($raw, true), 'raw' => $raw];
}

function is_envelope(?array $b): bool
{
    return is_array($b) && array_key_exists('success', $b) && array_key_exists('data', $b) && array_key_exists('message', $b);
}

if ($baseUrl === null) {
    section('8. Live API');
    echo "  SKIP  pass --base-url=http://localhost:8000 (start: php -S localhost:8000 router.php)\n";
} else {
    section("8. Live API at $baseUrl");

    $r = http('GET', "$baseUrl/api/health");
    check('GET /api/health -> 200 JSON', $r['status'] === 200 && str_starts_with($r['headers']['content-type'] ?? '', 'application/json'));
    check('success envelope: {success:true, data, message:null}', is_envelope($r['body']) && $r['body']['success'] === true && $r['body']['message'] === null);
    check('health reports database connected + table count', ($r['body']['data']['database']['connected'] ?? false) === true && ($r['body']['data']['database']['tables'] ?? 0) === 26);
    check('API sends nosniff and no-store headers', ($r['headers']['x-content-type-options'] ?? '') === 'nosniff' && ($r['headers']['cache-control'] ?? '') === 'no-store');
    check('response never contains credentials', !str_contains($r['raw'], (string) \App\Config\Config::get('db.pass')) || \App\Config\Config::get('db.pass') === '');

    $r = http('GET', "$baseUrl/api/health/");
    check('trailing slash still works', $r['status'] === 200);

    $r = http('GET', "$baseUrl/api/catalog/business-types");
    $types = $r['body']['data']['business_types'] ?? [];
    check('GET /api/catalog/business-types -> 6 types from the database', $r['status'] === 200 && count($types) === 6 && $types[0]['slug'] === 'bubble-tea');
    check('money is returned in cents (integers)', is_int($types[0]['startup_cost_cents'] ?? null));
    check('internal columns are not exposed', !array_key_exists('created_at', $types[0] ?? []) && !array_key_exists('sort_order', $types[0] ?? []));

    $r = http('GET', "$baseUrl/api/catalog/business-types/cafe");
    check('GET /api/catalog/business-types/cafe -> type with products', $r['status'] === 200 && ($r['body']['data']['name'] ?? '') === 'Café' && count($r['body']['data']['products'] ?? []) === 5);

    $r = http('GET', "$baseUrl/api/catalog/business-types/no-such-shop");
    check('unknown slug -> 404 error envelope', $r['status'] === 404 && is_envelope($r['body']) && $r['body']['success'] === false
        && $r['body']['data'] === null && ($r['body']['error']['code'] ?? '') === 'not_found' && ($r['body']['error']['status'] ?? 0) === 404);
    check('error.message mirrors message (Level 1 frontend compatibility)', ($r['body']['error']['message'] ?? 'x') === ($r['body']['message'] ?? 'y'));

    $r = http('GET', "$baseUrl/api/catalog/business-types/Bad_Slug!");
    check('invalid slug characters -> 404', $r['status'] === 404);

    $r = http('GET', "$baseUrl/api/does-not-exist");
    check('unknown endpoint -> 404 JSON', $r['status'] === 404 && ($r['body']['error']['code'] ?? '') === 'not_found');

    $r = http('POST', "$baseUrl/api/health", '{}', ['Content-Type: application/json']);
    check('wrong method -> 405 JSON with Allow header', $r['status'] === 405 && ($r['body']['error']['code'] ?? '') === 'method_not_allowed' && ($r['headers']['allow'] ?? '') === 'GET');

    foreach (['/.env', '/backend/config/settings.php', '/database/seed.sql', '/docs/database.md', '/tests/run.php'] as $path) {
        $r = http('GET', $baseUrl . $path);
        check("$path is not served", $r['status'] === 404);
    }
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
