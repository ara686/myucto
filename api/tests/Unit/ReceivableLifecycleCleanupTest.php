<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use MyInvoice\Tests\Integration\Property\ReceivableLifecycleTest;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class ReceivableLifecycleCleanupTest extends TestCase
{
    public function testRetriesTransientDirectoryCleanupAndDropsBothOwnedSchemas(): void
    {
        $pdo = new LifecycleDropPdo([self::dropError(1010, 'Directory not empty'), null, null]);
        self::cleanup($pdo, true);
        self::assertSame([
            'DROP DATABASE IF EXISTS `myucto_s5_lifecycle_0123456789_test_copy_test`',
            'DROP DATABASE IF EXISTS `myucto_s5_lifecycle_0123456789_test_copy_test`',
            'DROP DATABASE IF EXISTS `myucto_s5_lifecycle_0123456789_test`',
        ], $pdo->statements);
    }

    public function testPermissionFailureIsNotRetriedOrHidden(): void
    {
        $error = self::dropError(1044, 'Access denied');
        $pdo = new LifecycleDropPdo([$error]);
        try {
            self::cleanup($pdo, false);
            self::fail('Permission error was hidden.');
        } catch (PDOException $actual) {
            self::assertSame($error, $actual);
        }
        self::assertCount(1, $pdo->statements);
    }

    public function testPersistentDirectoryFailureExhaustsBoundedRetries(): void
    {
        $error = self::dropError(1010, 'Directory not empty');
        $pdo = new LifecycleDropPdo(array_fill(0, 5, $error));
        try {
            self::cleanup($pdo, false);
            self::fail('Persistent cleanup error was hidden.');
        } catch (PDOException $actual) {
            self::assertSame($error, $actual);
        }
        self::assertCount(5, $pdo->statements);
    }

    private static function cleanup(PDO $server, bool $copy): void
    {
        $test = new ReceivableLifecycleTest('testFullAnonymizationPreservesPaymentsLegalHistoryAndRetryWithoutPrivateText');
        foreach (['server' => $server, 'ownedSchema' => 'myucto_s5_lifecycle_0123456789_test',
            'targetSchema' => $copy ? 'myucto_s5_lifecycle_0123456789_test_copy_test' : null] as $name => $value) {
            (new \ReflectionProperty($test, $name))->setValue($test, $value);
        }
        (new \ReflectionMethod($test, 'tearDown'))->invoke($test);
    }

    private static function dropError(int $vendor, string $message): PDOException
    {
        $error = new PDOException($message);
        $error->errorInfo = ['HY000', $vendor, $message];
        return $error;
    }
}

final class LifecycleDropPdo extends PDO
{
    public array $statements = [];

    public function __construct(private array $outcomes) {}

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;
        $outcome = array_shift($this->outcomes);
        if ($outcome instanceof PDOException) {
            throw $outcome;
        }
        return 0;
    }
}
