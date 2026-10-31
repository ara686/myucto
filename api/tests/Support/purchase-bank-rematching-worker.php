<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\PurchaseBankMatching;
use MyInvoice\Service\Bank\StatementMatcher;

$c = Bootstrap::buildContainer();
if (!str_ends_with((string)$c->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn(), '_test')) {
    throw new RuntimeException('Worker smí běžet pouze nad testovací databází.');
}
$result = ($argv[1] ?? '') === 'bank'
    ? $c->get(StatementMatcher::class)->match((int)$argv[5])
    : $c->get(PurchaseBankMatching::class)->retry((int)$argv[2], (int)$argv[3], (int)$argv[4]);
echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
