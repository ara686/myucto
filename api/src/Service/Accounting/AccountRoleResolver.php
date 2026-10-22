<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;

final class AccountRoleResolver
{
    public function __construct(private readonly AccountingFrameworkService $frameworks) {}

    public static function fromConnection(Connection $db): self
    {
        return new self(new AccountingFrameworkService($db, new AccountingFrameworkRepository($db)));
    }

    public function forDate(int $supplierId, string $date): AccountingRoleProfile
    {
        return new AccountingRoleProfile($this->frameworks->forDate($supplierId, $date));
    }

    public function resolve(int $supplierId, string $role, string $date, ?string $legacy500Account = null): string
    {
        return $this->forDate($supplierId, $date)->account($role, $legacy500Account);
    }
}
