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

function stockFilters(array $input): array
{
    $textFields = ['drug_name' => 200, 'batch_number' => 100, 'storage_temperature' => 100];
    $integerFields = ['balance_id' => 1, 'min_quantity' => 0, 'max_quantity' => 0];
    $dateFields = ['expiry_after', 'expiry_before'];
    $allowed = array_merge(array_keys($textFields), array_keys($integerFields), $dateFields, ['download']);
    $filters = [];

    foreach ($input as $name => $value) {
        if (!in_array($name, $allowed, true)) {
            throw new InvalidArgumentException('Неизвестный параметр запроса.');
        }
        if (!is_string($value) || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw new InvalidArgumentException('Параметр ' . $name . ' должен быть строкой UTF-8.');
        }
        $value = trim($value);
        if ($name === 'download') {
            if (!in_array($value, ['0', '1'], true)) {
                throw new InvalidArgumentException('Параметр download принимает 0 или 1.');
            }
            continue;
        }
        if ($value === '') {
            continue;
        }
        if (isset($textFields[$name])) {
            if (preg_match_all('/./us', $value) > $textFields[$name]) {
                throw new InvalidArgumentException('Слишком длинное значение параметра ' . $name . '.');
            }
            $filters[$name] = $value;
        } elseif (isset($integerFields[$name])) {
            if (!preg_match('/^[0-9]+$/D', $value)
                || strlen($value) > 10
                || (float) $value > 4294967295
                || (int) $value < $integerFields[$name]) {
                throw new InvalidArgumentException('Недопустимое целое число в параметре ' . $name . '.');
            }
            $filters[$name] = (int) $value;
        } else {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value
                || $value < '1000-01-01' || $value > '9999-12-31') {
                throw new InvalidArgumentException('Параметр ' . $name . ' должен быть датой YYYY-MM-DD.');
            }
            $filters[$name] = $value;
        }
    }

    if (isset($filters['min_quantity'], $filters['max_quantity'])
        && $filters['min_quantity'] > $filters['max_quantity']) {
        throw new InvalidArgumentException('min_quantity не может быть больше max_quantity.');
    }
    if (isset($filters['expiry_after'], $filters['expiry_before'])
        && $filters['expiry_after'] > $filters['expiry_before']) {
        throw new InvalidArgumentException('expiry_after не может быть позже expiry_before.');
    }

    return $filters;
}

function readStock(array $filters = []): array
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

    $conditions = [
        'balance_id' => 'balance_id = :balance_id',
        'drug_name' => "drug_name LIKE :drug_name ESCAPE '!'",
        'batch_number' => 'batch_number = :batch_number',
        'storage_temperature' => 'storage_temperature = :storage_temperature',
        'min_quantity' => 'quantity >= :min_quantity',
        'max_quantity' => 'quantity <= :max_quantity',
        'expiry_after' => 'expiry_date >= :expiry_after',
        'expiry_before' => 'expiry_date <= :expiry_before',
    ];
    $where = [];
    foreach ($filters as $name => $value) {
        $where[] = $conditions[$name];
    }
    $sql = 'SELECT balance_id, drug_name, batch_number, expiry_date, quantity, '
        . 'storage_temperature FROM stock_balances';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY balance_id';

    $statement = $database->prepare($sql);
    foreach ($filters as $name => $value) {
        if ($name === 'drug_name') {
            $value = '%' . strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->execute();
    return $statement->fetchAll();
}
