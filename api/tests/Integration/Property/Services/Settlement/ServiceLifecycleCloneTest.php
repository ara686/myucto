<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Services\Settlement;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Services\Settlement\ServiceLifecycleRepository;
use MyInvoice\Service\Anonymization\{AnonymizationOptions,AnonymizationService,DatabaseCloner,ServiceLifecycleCloneScrubber};
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Tests\Support\OwnedTestDatabaseCleanup;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServiceLifecycleCloneTest extends TestCase
{
    private array $owned = [];
    private PDO $server;
    private Connection $source;
    private ?Connection $target = null;
    private string|false $priorDatabase;
    private ServiceLifecycleNativeTest $fixture;
    private Config $config;

    public static function profiles(): array
    {
        return ['native balance'=>['service_balance_sheet',false],'native PL'=>['service_profit_and_loss',false],'opening balance'=>['service_balance_sheet',true],'opening PL'=>['service_profit_and_loss',true]];
    }

    protected function setUp(): void
    {
        $this->priorDatabase = getenv('MYINVOICE_DB_NAME');
        $this->config = Config::load(Bootstrap::rootDir());
        $base = (string) $this->config->get('db.name');
        self::assertStringEndsWith('_test',$base);
        self::assertContains($this->config->get('db.host'),['localhost','127.0.0.1','::1']);
        $token = bin2hex(random_bytes(5));
        $this->owned = ['myucto_lifecycle_clone_'.$token.'_source_test','myucto_lifecycle_clone_'.$token.'_target_test'];
        $this->server = new PDO('mysql:host='.$this->config->get('db.host').';port='.$this->config->get('db.port',3306).';charset=utf8mb4',$this->config->get('db.user'),$this->config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $q = $this->server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?,?)');
        $q->execute($this->owned);
        self::assertSame(0,(int) $q->fetchColumn());
        $cloner = new DatabaseCloner($this->server);
        $plan = $cloner->plan($base);
        $cloner->copyTables($plan,$this->owned[0],static function():void{});
        $cloner->finalize($plan,$this->owned[0]);
        putenv('MYINVOICE_DB_NAME='.$this->owned[0]);
        $this->fixture = new ServiceLifecycleNativeTest('testNativeBalanceYearRolloverUnwindsExactEstimateAndFinalizes');
        Connection::withoutSharedTestConnection(fn()=>(new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture));
        $this->source = (new \ReflectionProperty($this->fixture,'db'))->getValue($this->fixture);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->fixture)) (new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);
            if (isset($this->source)) $this->source->close();
            if ($this->target !== null) {if ($this->target->pdo()->inTransaction()) $this->target->pdo()->rollBack();$this->target->close();}
            if (isset($this->server)) foreach (array_reverse($this->owned) as $schema) OwnedTestDatabaseCleanup::drop($this->server,$schema,$this->owned);
        } finally {
            if (isset($this->priorDatabase)) putenv($this->priorDatabase === false ? 'MYINVOICE_DB_NAME' : 'MYINVOICE_DB_NAME='.$this->priorDatabase);
        }
    }

    private function native(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($this->fixture,$method))->invoke($this->fixture,...$args);
    }

    private function prepare(string $policy, bool $opening): array
    {
        $data = $opening ? $this->native('openingBase',$policy,false) : $this->native('base',$policy);
        $f = $data['f'];
        $owner = $data['owner'];
        $invoice = $this->native('invoice',$data);
        $q = $this->source->pdo()->prepare('UPDATE purchase_invoice_items SET description=? WHERE purchase_invoice_id=?');
        $q->execute(['Synthetic service private-recipient@synthetic.invalid',$invoice['invoice']]);
        $owner->unwind($f['supplier'],$data['case'],'2027-04-01',$f['actor']);
        $final = $owner->finalize($f['supplier'],$data['case'],$invoice['invoice'],$invoice['line']['id'],'2027-04-01','Synthetic private-title@synthetic.invalid',$f['actor']);
        $q = $this->source->pdo()->prepare('SELECT id,facts_json FROM property_service_lifecycle_cases WHERE supplier_id=? AND id=?');
        $q->execute([$f['supplier'],$data['case']]);
        $facts = json_decode($q->fetchColumn(1),true,flags:JSON_THROW_ON_ERROR);
        $facts['evidence'] = 'Synthetic private-policy@synthetic.invalid';
        $this->source->pdo()->prepare('UPDATE property_service_lifecycle_cases SET facts_json=?,facts_hash=? WHERE supplier_id=? AND id=?')->execute([json_encode($facts,JSON_THROW_ON_ERROR),PropertyInput::hash($facts),$f['supplier'],$data['case']]);
        if (!$opening) {
            $q = $this->source->pdo()->prepare("SELECT id,request_json FROM property_service_lifecycle_events WHERE supplier_id=? AND case_id=? AND event_key='estimate'");
            $q->execute([$f['supplier'],$data['case']]);$event = $q->fetch(PDO::FETCH_ASSOC);
            $request = json_decode($event['request_json'],true,flags:JSON_THROW_ON_ERROR);$request['coverage_title'] = 'Synthetic private-coverage@synthetic.invalid';
            $this->source->pdo()->prepare('UPDATE property_service_lifecycle_events SET request_json=?,request_hash=? WHERE supplier_id=? AND id=?')->execute([json_encode($request,JSON_THROW_ON_ERROR),PropertyInput::hash($request),$f['supplier'],$event['id']]);
        }
        $pending = $owner->openCase($f['supplier'],$f['units']['U02'],$f['service']['id'],'2028-01-01','2028-12-31',$policy,'Synthetic pending@synthetic.invalid',$f['actor'],'315.210');
        (new \ReflectionMethod($owner,'newEvent'))->invoke($owner,$f['supplier'],$pending,'unwind-0',['kind'=>'unwind','index'=>0,'accounting_date'=>'2028-01-01','actor'=>$f['actor']]);
        $this->source->pdo()->prepare('INSERT INTO property_service_lifecycle_commands(supplier_id,idempotency_key,request_hash,case_id,response_json,created_by,recorded_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))')->execute([$f['supplier'],'synthetic-private-command',str_repeat('a',64),$data['case'],'{"private":"original-command@synthetic.invalid"}',$f['actor']]);
        self::assertSame($final,$owner->current($f['supplier'],$data['case'])['final']);
        $this->native('applySettlement',$data,$final['cost_line_id'],$opening ? $data['paid'] : '0',false);
        $this->source->pdo()->commit();
        return $data+compact('final','pending');
    }

    #[DataProvider('profiles')]
    public function testNativeCloneRehashesLifecycleGraphAndDropsOnlyCommandReceipts(string $policy, bool $opening): void
    {
        $data = $this->prepare($policy,$opening);
        $before = $this->fingerprint($this->source);
        $options = new AnonymizationOptions($this->owned[0],$this->owned[1],seed:'synthetic-service-lifecycle-clone');
        (new AnonymizationService($this->config))->run($options,static function():void{});
        self::assertSame($before,$this->fingerprint($this->source));
        $config = $this->config->all();$config['db']['name'] = $this->owned[1];
        $this->target = Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($config,$this->config->dataDir())));
        $pdo = $this->target->pdo();$pdo->beginTransaction();
        $current = (new ServiceLifecycleRepository($this->target))->current($data['f']['supplier'],$data['case']);
        self::assertSame($data['final']['amount_cents'],$current['final']['amount_cents']);
        self::assertSame($data['final']['cost_line_id'],$current['final']['cost_line_id']);
        self::assertNotSame($data['final']['invoice']['document_hash'],$current['final']['invoice']['document_hash']);
        self::assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM property_service_lifecycle_commands')->fetchColumn());
        self::assertSame(1,(int) $pdo->query('SELECT COUNT(*) FROM property_service_lifecycle_events WHERE receipt_json IS NULL AND receipt_hash IS NULL')->fetchColumn());
        foreach (['property_service_lifecycle_cases','property_service_lifecycle_events'] as $table) {
            $json = json_encode($pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);
            foreach (['private-recipient@synthetic.invalid','private-title@synthetic.invalid','private-policy@synthetic.invalid','private-coverage@synthetic.invalid','pending@synthetic.invalid'] as $secret) self::assertStringNotContainsString($secret,$json);
        }
        self::assertSame($this->source->pdo()->query('SELECT supplier_id,event_id,line_id,claim_role FROM property_service_lifecycle_claims ORDER BY supplier_id,line_id,claim_role')->fetchAll(PDO::FETCH_ASSOC),$pdo->query('SELECT supplier_id,event_id,line_id,claim_role FROM property_service_lifecycle_claims ORDER BY supplier_id,line_id,claim_role')->fetchAll(PDO::FETCH_ASSOC));
        $pdo->rollBack();
    }

    public function testMissingCapturedInvoiceInputFailsClosedWithoutChangingThePartialClone(): void
    {
        $data = $this->prepare('service_balance_sheet',false);
        $cloner = new DatabaseCloner($this->server);$plan = $cloner->plan($this->owned[0]);$cloner->copyTables($plan,$this->owned[1],static function():void{});
        $config = $this->config->all();$config['db']['name'] = $this->owned[1];
        $this->target = Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($config,$this->config->dataDir())));
        $pdo = $this->target->pdo();$before = $this->fingerprint($this->target);$pdo->beginTransaction();
        try {
            $scrubber = new \MyInvoice\Service\Anonymization\TextScrubber(new \MyInvoice\Service\Anonymization\Pseudonymizer('synthetic-lifecycle-omission'));
            ServiceLifecycleCloneScrubber::run($this->target,$scrubber,[],static fn(array $data):array=>$data,static fn(string $hash,array $data):string=>PropertyInput::hash($data),[]);
            self::fail('Missing immutable invoice preimage was accepted.');
        } catch (\LogicException $error) {
            self::assertSame('Lifecycle clone hash source missing.',$error->getMessage());
        } finally {$pdo->rollBack();}
        self::assertSame($before,$this->fingerprint($this->target));
        foreach ([['UPDATE property_service_lifecycle_events SET receipt_hash=? WHERE receipt_json IS NULL','Lifecycle clone null receipt hash mismatch.'],['UPDATE property_service_lifecycle_cases SET facts_hash=?','Lifecycle clone source hash mismatch.']] as [$sql,$message]) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare($sql)->execute([str_repeat('b',64)]);
                ServiceLifecycleCloneScrubber::run($this->target,$scrubber,[],static fn(array $data):array=>$data,static fn(string $hash,array $data):string=>PropertyInput::hash($data),[]);
                self::fail('Corrupt immutable lifecycle hash was accepted.');
            } catch (\LogicException $error) {self::assertSame($message,$error->getMessage());}
            finally {$pdo->rollBack();}
            self::assertSame($before,$this->fingerprint($this->target));
        }
    }

    public static function unfinalizedProfiles(): array
    {
        return ['native estimate'=>['service_balance_sheet',false],'opening PL estimate'=>['service_profit_and_loss',true]];
    }

    #[DataProvider('unfinalizedProfiles')]
    public function testUnfinalizedRehashedEstimateCannotBeLaunderedByClone(string $policy, bool $opening): void
    {
        $data = $opening ? $this->native('openingBase',$policy,false) : $this->native('base',$policy);
        $supplier = $data['f']['supplier'];
        $q = $this->source->pdo()->prepare("SELECT id,receipt_json FROM property_service_lifecycle_events WHERE supplier_id=? AND case_id=? AND event_key='estimate'");
        $q->execute([$supplier,$data['case']]);$event = $q->fetch(PDO::FETCH_ASSOC);
        $receipt = json_decode($event['receipt_json'],true,flags:JSON_THROW_ON_ERROR);
        $receipt['sources'][0]['lines'][0]['amount'] = '10999.00';
        $this->source->pdo()->prepare('UPDATE property_service_lifecycle_events SET receipt_json=?,receipt_hash=? WHERE supplier_id=? AND id=?')->execute([json_encode($receipt,JSON_THROW_ON_ERROR),PropertyInput::hash($receipt),$supplier,$event['id']]);
        $this->source->pdo()->commit();$before = $this->fingerprint($this->source);
        try {
            (new AnonymizationService($this->config))->run(new AnonymizationOptions($this->owned[0],$this->owned[1],seed:'synthetic-unfinalized-source-corruption'),static function():void{});
            self::fail('Clone accepted a correctly rehashed estimate without its native source proof.');
        } catch (\DomainException $error) {self::assertSame('settlement_lifecycle_source_changed',$error->getMessage());}
        self::assertSame($before,$this->fingerprint($this->source));
        $q = $this->server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$q->execute([$this->owned[1]]);
        self::assertSame(0,(int) $q->fetchColumn());
    }
    public function testSourceChangedAfterFinalizationIsRejectedBeforeRewrite(): void
    {
        $data = $this->prepare('service_balance_sheet',false);
        $invoice = $data['final']['invoice']['invoice_id'];
        $this->source->pdo()->prepare('UPDATE purchase_invoice_items SET description=? WHERE purchase_invoice_id=?')->execute(['Synthetic changed-after-finalization@synthetic.invalid',$invoice]);
        $before = $this->fingerprint($this->source);
        try {
            (new AnonymizationService($this->config))->run(new AnonymizationOptions($this->owned[0],$this->owned[1],seed:'synthetic-lifecycle-source-corruption'),static function():void{});
            self::fail('Clone repaired a changed immutable source.');
        } catch (\DomainException $error) {self::assertSame('settlement_lifecycle_invoice_changed',$error->getMessage());}
        self::assertSame($before,$this->fingerprint($this->source));
        $q = $this->server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$q->execute([$this->owned[1]]);
        self::assertSame(0,(int) $q->fetchColumn());
    }
    public function testNativeKeepResetClearsLifecycleTablesAndPreservesAccountingConfiguration(): void
    {
        $data = $this->prepare('service_profit_and_loss',false);
        $supplier = $data['f']['supplier'];
        $kept = [];
        foreach (['supplier_accounting_frameworks','accounting_periods','chart_of_accounts'] as $table) $kept[$table] = $this->source->pdo()->query('SELECT * FROM '.$table.' WHERE supplier_id='.$supplier.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $runtime = sys_get_temp_dir().DIRECTORY_SEPARATOR.'myucto_lifecycle_reset_'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($runtime));
        try {
            $env = getenv();$env['MYINVOICE_DB_NAME'] = $this->owned[0];$env['MYINVOICE_DATA_DIR'] = $runtime;
            $code = '$root=$argv[1];$loader=require $root."/api/vendor/autoload.php";$loader->addPsr4("MyInvoice\\\\",$root."/api/src",true);require $root."/api/bin/reset.php";';
            $process = proc_open([PHP_BINARY,'-r',$code,Bootstrap::rootDir(),'--yes','--keep-users-supplier','--skip-files'],[0=>['pipe','r'],1=>['file',$runtime.'/output.log','w'],2=>['file',$runtime.'/error.log','w']],$pipes,Bootstrap::rootDir(),$env);
            self::assertIsResource($process);fclose($pipes[0]);$exit = proc_close($process);
            self::assertSame(0,$exit,file_get_contents($runtime.'/output.log').file_get_contents($runtime.'/error.log'));
            foreach ([...ServiceLifecycleCloneScrubber::TABLES,ServiceLifecycleCloneScrubber::COMMANDS] as $table) self::assertSame(0,(int) $this->source->pdo()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(),$table);
            foreach ($kept as $table=>$rows) self::assertSame($rows,$this->source->pdo()->query('SELECT * FROM '.$table.' WHERE supplier_id='.$supplier.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),$table);
            self::assertSame(0,(int) $this->source->pdo()->query("SELECT COUNT(*) FROM dimension_types WHERE system_key IN ('unit','service')")->fetchColumn());
        } finally {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($runtime,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            rmdir($runtime);
        }
    }
    private function fingerprint(Connection $db): string
    {
        $rows = [];
        foreach ([...ServiceLifecycleCloneScrubber::TABLES,ServiceLifecycleCloneScrubber::COMMANDS] as $table) $rows[$table] = $db->pdo()->query('SELECT * FROM '.$table.' ORDER BY 1,2,3')->fetchAll(PDO::FETCH_ASSOC);
        return PropertyInput::hash($rows);
    }
}
