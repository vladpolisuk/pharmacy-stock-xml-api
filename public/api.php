<?php
declare(strict_types=1);

require __DIR__ . '/../src/stock.php';

header('Content-Type: application/xml; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo errorXml('method_not_allowed', 'Используйте GET /api/stock.');
    exit;
}

try {
    $xml = stockXml(readStock());
} catch (Throwable $error) {
    error_log('Pharmacy API: ' . $error->getMessage());
    http_response_code(503);
    echo errorXml('database_unavailable', 'Не удалось получить данные из базы.');
    exit;
}

if (($_GET['download'] ?? '') === '1') {
    header('Content-Disposition: attachment; filename="medicine-stock.xml"');
}

if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    echo $xml;
}
