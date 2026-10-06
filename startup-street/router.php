<?php
// Router for PHP's built-in server:  php -S localhost:8000 router.php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/api' || str_starts_with($path, '/api/')) {
    require __DIR__ . '/backend/public/index.php';
    return true;
}

if ($path === '/') {
    header('Location: /frontend/index.html');
    return true;
}

// Never serve secrets or server code as static files.
if (preg_match('#^/(\.env|backend/(?!public/)|database/|docs/)#', $path)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

return false; // let the built-in server serve static files
