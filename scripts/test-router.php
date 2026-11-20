<?php
// Router for localhost-only checks with PHP's development server.
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/api/stock') {
    require __DIR__ . '/../public/api.php';
    return true;
}
return false;
