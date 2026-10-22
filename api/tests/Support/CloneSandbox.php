<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Service\Anonymization\AnonymizationOptions;
use MyInvoice\Service\Anonymization\AnonymizationService;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use PDO;

/**
 * Vlastní databáze testů klonů a anonymizace, znovu použitá v rámci procesu.
 *
 * Testy klonů zakládaly pro každý test celou kopii testovací DB (775 tabulek, 539
 * triggerů) a druhou kopii pro anonymizaci. {@see acquire()} vrátí databázi, jejíž
 * tabulky, obsah i AUTO_INCREMENT jsou totožné se zdrojem, tedy s tím, co by vznikl
 * přes {@see DatabaseCloner::copyTables()}. S `$triggers` má navíc triggery, pohledy
 * a rutiny zdroje jako po {@see DatabaseCloner::finalize()}, bez něj nemá žádné,
 * jako kopie před dokončením, kterou přepisují scrubbery anonymizace.
 *
 * Volná databáze se nezahazuje. Při dalším `acquire()` se srovná se zdrojem: tabulky,
 * jejichž obsah nebo čítač se liší, se obnoví (TRUNCATE nebo u verzované tabulky
 * DELETE a DELETE HISTORY, potom INSERT … SELECT ze zdroje bez triggerů dotčené
 * tabulky), triggery se srovnají. Shoda se po obnově ověří znovu. Když se liší
 * definice tabulek nebo rutin, nebo obnova neprojde, databáze se zahodí a založí
 * znovu celou.
 *
 * Zdrojem může být testovací DB i jiná zapůjčená databáze (třeba s fixturou, ze
 * které si každý test vezme vlastní kopii). Test databázi vrací přes {@see release()}
 * po zavření svých spojení. Zbylé databáze se zahazují na konci procesu.
 */
final class CloneSandbox
{
    private const PATTERN = '/\Amyucto_clonesandbox_[a-f0-9]{10}_test\z/D';

    /** @var array{0: list<string>, 1: list<string>} volné databáze bez triggerů a s nimi */
    private static array $idle = [[], []];

    /** @var array<string,true> */
    private static array $owned = [];

    /** @var array<string,int> zapůjčená databáze => 1 s triggery, 0 bez nich */
    private static array $leased = [];

    private static ?PDO $server = null;

    private static bool $shutdownRegistered = false;

    /** Počet založení a obnov od začátku procesu (pro test pomocníka). */
    public static int $writes = 0;

    public static function acquire(PDO $server, string $source, bool $triggers = false): string
    {
        if (!str_ends_with($source, '_test')) {
            throw new \LogicException('Kopírovat lze jen testovací databázi.');
        }
        self::$server ??= $server;
        $schema = null;
        while ($schema === null && self::$idle[(int) $triggers] !== []) {
            $candidate = array_pop(self::$idle[(int) $triggers]);
            if (self::restore($server, $source, $candidate, $triggers)) {
                $schema = $candidate;
            } else {
                self::drop($server, $candidate);
            }
        }
        $schema ??= self::create($server, $source, $triggers);
        self::$leased[$schema] = (int) $triggers;
        // Spojení končí ve stejném stavu jako po DatabaseCloner::copyTables().
        $server->exec('USE ' . DatabaseCloner::quote($schema));
        $server->exec('SET SESSION FOREIGN_KEY_CHECKS = 0');

        return $schema;
    }

    /**
     * Anonymizovaná kopie zdroje bez zakládání nové databáze: vezme databázi bez
     * triggerů se stejným obsahem jako `$source` a přepíše ji produkční transformací
     * {@see AnonymizationService::anonymizeCopy()}, tedy stejnými kroky jako
     * {@see AnonymizationService::run()} mezi kopií tabulek a založením triggerů.
     * Kopii triggery nezakládá a nehledá úniky proti originálu; to ověřuje
     * {@see \MyInvoice\Tests\Integration\Anonymization\AnonymizationServiceTest}.
     */
    public static function anonymized(PDO $server, Config $config, string $source, string $seed): string
    {
        $target = self::acquire($server, $source);
        (new AnonymizationService($config))->anonymizeCopy(new AnonymizationOptions($source, $target, seed: $seed), static function (): void {});

        return $target;
    }

    public static function isLeased(string $schema): bool
    {
        return isset(self::$leased[$schema]);
    }

    public static function release(PDO $server, string $schema): void
    {
        if (!isset(self::$leased[$schema])) {
            throw new \LogicException('Databáze ' . $schema . ' není zapůjčená.');
        }
        $mode = self::$leased[$schema];
        unset(self::$leased[$schema]);
        OwnedTestDatabaseSessions::close($server, [$schema]);
        self::$idle[$mode][] = $schema;
    }

    /**
     * Úklid vlastních databází testu: zapůjčenou vrátí, ostatní zahodí přes
     * {@see OwnedTestDatabaseCleanup::drop()}.
     *
     * @param list<string> $ownedSchemas
     */
    public static function dropOrRelease(PDO $server, string $schema, array $ownedSchemas): void
    {
        if (isset(self::$leased[$schema])) {
            self::release($server, $schema);
            return;
        }
        OwnedTestDatabaseCleanup::drop($server, $schema, $ownedSchemas);
    }

    /** Zahodí všechny databáze procesu. Volá se na konci procesu, test pomocníka i dřív. */
    public static function dropAll(): void
    {
        $server = self::$server;
        if ($server === null) {
            return;
        }
        foreach (array_keys(self::$owned) as $schema) {
            OwnedTestDatabaseSessions::close($server, [$schema]);
            self::drop($server, $schema);
        }
        self::$idle = [[], []];
        self::$leased = [];
    }

    private static function create(PDO $server, string $source, bool $triggers): string
    {
        $schema = 'myucto_clonesandbox_' . bin2hex(random_bytes(5)) . '_test';
        $exists = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $exists->execute([$schema]);
        if ((int) $exists->fetchColumn() !== 0) {
            throw new \LogicException('Databáze ' . $schema . ' už existuje.');
        }
        self::$owned[$schema] = true;
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'dropAll']);
        }
        $cloner = new DatabaseCloner($server);
        $plan = $cloner->plan($source);
        $cloner->copyTables($plan, $schema, static function (): void {});
        if ($triggers) {
            $cloner->finalize($plan, $schema);
        }
        ++self::$writes;

        return $schema;
    }

    private static function drop(PDO $server, string $schema): void
    {
        if (preg_match(self::PATTERN, $schema) !== 1 || !isset(self::$owned[$schema])) {
            throw new \LogicException('Zahodit lze jen vlastní databázi.');
        }
        OwnedTestDatabaseCleanup::drop($server, $schema, [$schema]);
        unset(self::$owned[$schema], self::$leased[$schema]);
        foreach (self::$idle as $mode => $schemas) {
            self::$idle[$mode] = array_values(array_diff($schemas, [$schema]));
        }
    }

    /** Srovná databázi se zdrojem. False = nelze obnovit, musí se založit znovu. */
    private static function restore(PDO $server, string $source, string $schema, bool $triggers): bool
    {
        $cloner = new DatabaseCloner($server);
        $expected = self::state($server, $cloner, $source, $triggers);
        $actual = self::state($server, $cloner, $schema, true);
        if ($expected['tables'] !== $actual['tables']) {
            return false;
        }
        $dirty = [];
        foreach ($expected['rows'] as $table => $row) {
            if ($actual['rows'][$table] !== $row) {
                $dirty[$table] = true;
            }
        }
        $increments = [];
        foreach ($actual['increments'] + $expected['increments'] as $table => $_) {
            if (($actual['increments'][$table] ?? null) !== ($expected['increments'][$table] ?? null) || isset($dirty[$table])) {
                // Čítač, který zdroj nemá, vrátí hodnota 1 (InnoDB ji zvedne nad nejvyšší klíč).
                $increments[$table] = $expected['increments'][$table] ?? 1;
            }
        }
        $objectsMatch = $expected['objects'] === $actual['objects'];
        if ($dirty === [] && $increments === [] && $objectsMatch) {
            return true;
        }

        $versioned = array_fill_keys($expected['plan']['system_versioned'], true);
        $server->exec('USE ' . DatabaseCloner::quote($schema));
        $server->exec('SET SESSION FOREIGN_KEY_CHECKS = 0');
        $server->exec("SET SESSION system_versioning_alter_history = 'KEEP'");
        try {
            // Pohledy, triggery a rutiny, které nesedí, se založí znovu celé; jinak jen
            // triggery obnovovaných tabulek, aby INSERT původních řádků neodmítly.
            if (!$objectsMatch) {
                self::dropObjects($server, $schema);
            }
            foreach (TriggerMetadata::read($server, $schema) as $trigger) {
                if (isset($dirty[(string) $trigger['EVENT_OBJECT_TABLE']])) {
                    $server->exec('DROP TRIGGER ' . DatabaseCloner::quote((string) $trigger['TRIGGER_NAME']));
                }
            }
            foreach (array_keys($dirty) as $table) {
                $name = DatabaseCloner::quote($table);
                if (isset($versioned[$table])) {
                    // Verzovaná tabulka TRUNCATE nedovolí; čerstvá kopie nemá historii.
                    $server->exec('DELETE FROM ' . $name);
                    $server->exec('DELETE HISTORY FROM ' . $name);
                } else {
                    $server->exec('TRUNCATE TABLE ' . $name);
                }
                $columns = $expected['plan']['tables'][$table]['columns'];
                $server->exec('INSERT INTO ' . $name . ' (' . $columns . ') SELECT ' . $columns
                    . ' FROM ' . DatabaseCloner::quote($source) . '.' . $name);
            }
            foreach ($increments as $table => $value) {
                $server->exec('ALTER TABLE ' . DatabaseCloner::quote($table) . ' AUTO_INCREMENT = ' . $value);
            }
            if ($triggers) {
                if (!$objectsMatch) {
                    $cloner->finalize($expected['plan'], $schema);
                } else {
                    foreach ($expected['plan']['triggers'] as $trigger) {
                        if (isset($dirty[$trigger['table']])) {
                            $server->exec($trigger['ddl']);
                        }
                    }
                }
            }
        } finally {
            $server->exec('SET SESSION FOREIGN_KEY_CHECKS = 1');
            $server->exec('SET SESSION system_versioning_alter_history = DEFAULT');
        }
        ++self::$writes;
        $after = self::state($server, $cloner, $schema, true);

        return $after['tables'] === $expected['tables']
            && $after['objects'] === $expected['objects']
            && $after['rows'] === $expected['rows']
            && $after['increments'] === $expected['increments'];
    }

    /** Zahodí pohledy, triggery a rutiny databáze. */
    private static function dropObjects(PDO $server, string $schema): void
    {
        $cloner = new DatabaseCloner($server);
        foreach ($cloner->tableTypes($schema) as $name => $type) {
            if ($type === 'VIEW') {
                $server->exec('DROP VIEW ' . DatabaseCloner::quote($schema) . '.' . DatabaseCloner::quote($name));
            }
        }
        foreach (TriggerMetadata::read($server, $schema) as $trigger) {
            $server->exec('DROP TRIGGER ' . DatabaseCloner::quote($schema) . '.' . DatabaseCloner::quote((string) $trigger['TRIGGER_NAME']));
        }
        $routines = $server->prepare('SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?');
        $routines->execute([$schema]);
        foreach ($routines->fetchAll(PDO::FETCH_NUM) as [$name, $type]) {
            $server->exec('DROP ' . $type . ' ' . DatabaseCloner::quote($schema) . '.' . DatabaseCloner::quote((string) $name));
        }
    }

    /**
     * Definice tabulek bez AUTO_INCREMENT, pohledy s triggery a rutinami (bez `$objects`
     * prázdné), otisk obsahu každé tabulky a AUTO_INCREMENT zvlášť.
     *
     * @return array{plan:array, tables:string, objects:string, rows:array<string,string>, increments:array<string,int>}
     */
    private static function state(PDO $server, DatabaseCloner $cloner, string $database, bool $objects): array
    {
        $plan = $cloner->plan($database);
        $tables = [];
        $increments = [];
        foreach ($plan['tables'] as $table => $definition) {
            if (preg_match('/\sAUTO_INCREMENT=(\d+)/', $definition['ddl'], $m) === 1) {
                $increments[$table] = (int) $m[1];
            }
            $tables[$table] = preg_replace('/\sAUTO_INCREMENT=\d+/', '', $definition['ddl']);
        }
        $rows = [];
        if ($tables !== []) {
            $quoted = DatabaseCloner::quote($database) . '.';
            $names = array_keys($tables);
            $versioned = array_fill_keys($plan['system_versioned'], true);
            $plain = array_values(array_filter($names, static fn (string $t): bool => !isset($versioned[$t])));
            $checksums = [];
            if ($plain !== []) {
                foreach ($server->query('CHECKSUM TABLE ' . implode(', ', array_map(static fn (string $t): string => $quoted . DatabaseCloner::quote($t), $plain)) . ' EXTENDED')->fetchAll(PDO::FETCH_NUM) as $row) {
                    $checksums[substr((string) $row[0], strlen($database) + 1)] = (string) $row[1];
                }
            }
            // CHECKSUM TABLE počítá i historii a čas verze. Čerstvá kopie verzované tabulky
            // nese jen aktuální řádky, srovnávají se proto jen ty.
            foreach (array_keys($versioned) as $table) {
                $values = implode(', ', array_map(static fn (string $c): string => 'QUOTE(' . $c . ')', explode(', ', $plan['tables'][$table]['columns'])));
                $checksums[$table] = (string) $server->query("SELECT CONCAT(COALESCE(SUM(CRC32(r)), 0), '/', COALESCE(BIT_XOR(CONV(SUBSTR(MD5(r), 1, 16), 16, 10)), 0))"
                    . " FROM (SELECT CONCAT_WS(',', " . $values . ') r FROM ' . $quoted . DatabaseCloner::quote($table) . ') x')->fetchColumn();
            }
            $counts = $server->query(implode(' UNION ALL ', array_map(
                static fn (string $t): string => 'SELECT ' . $server->quote($t) . ', COUNT(*) FROM ' . $quoted . DatabaseCloner::quote($t),
                $names,
            )))->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($names as $table) {
                $rows[$table] = ($checksums[$table] ?? '?') . ':' . ($counts[$table] ?? '?');
            }
        }

        return [
            'plan' => $plan,
            'tables' => hash('sha256', json_encode([$plan['charset'], $plan['collation'], $tables, $plan['system_versioned']], JSON_THROW_ON_ERROR)),
            'objects' => hash('sha256', json_encode($objects ? [$plan['views'], $plan['triggers'], $plan['routines']] : [[], [], []], JSON_THROW_ON_ERROR)),
            'rows' => $rows,
            'increments' => $increments,
        ];
    }
}
