<?php
declare(strict_types=1);

function stockXml(array $rows): string
{
    $document = new DOMDocument('1.0', 'UTF-8');
    $document->formatOutput = true;
    $root = $document->appendChild($document->createElement('stock_balances'));
    $fields = [
        'balance_id', 'drug_name', 'batch_number',
        'expiry_date', 'quantity', 'storage_temperature',
    ];

    foreach ($rows as $row) {
        $record = $root->appendChild($document->createElement('stock_balance'));
        foreach ($fields as $field) {
            $element = $document->createElement($field);
            $element->appendChild($document->createTextNode((string) $row[$field]));
            $record->appendChild($element);
        }
    }

    return $document->saveXML();
}

function errorXml(string $code, string $message): string
{
    $document = new DOMDocument('1.0', 'UTF-8');
    $document->formatOutput = true;
    $root = $document->appendChild($document->createElement('error'));
    foreach (['code' => $code, 'message' => $message] as $name => $value) {
        $element = $document->createElement($name);
        $element->appendChild($document->createTextNode($value));
        $root->appendChild($element);
    }
    return $document->saveXML();
}

function readStock(): array
{
    $configPath = getenv('PHARMACY_CONFIG') ?: '/etc/pharmacy-lab/config.php';
    if (!is_readable($configPath)) {
        throw new RuntimeException('Database configuration is missing or unreadable.');
    }
    $config = require $configPath;
    $database = new PDO($config['dsn'], $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $database->query(
        'SELECT balance_id, drug_name, batch_number, expiry_date, quantity, '
        . 'storage_temperature FROM stock_balances ORDER BY balance_id'
    )->fetchAll();
}
