<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Services\Settlement;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Services\Settlement\{SettlementDocumentRepository,SettlementApplicationRepository};
use MyInvoice\Service\Anonymization\{AnonymizationService,AnonymizationOptions,DatabaseCloner};
use MyInvoice\Tests\Support\OwnedTestDatabaseCleanup;
use PDO;
use PHPUnit\Framework\TestCase;
final class SettlementCompositeCostCloneTest extends TestCase
{
    private Config $config;
    private PDO $server;
    private array $owned=[];
    private bool $ownsSchemas=false;
    private string|false $priorDatabase;
    private Connection $source;
    private ?Connection $target=null;
    private SettlementCompositeCostTest $fixture;
    protected function setUp():void
    {
        try{$this->initialize();}catch(\Throwable $failure){$this->tearDown();throw $failure;}
    }
    private function initialize():void
    {
        $this->priorDatabase=getenv('MYINVOICE_DB_NAME');$this->config=Config::load(Bootstrap::rootDir());$base=$this->config->get('db.name');self::assertStringEndsWith('_test',$base);self::assertContains($this->config->get('db.host'),['localhost','127.0.0.1','::1']);$token=bin2hex(random_bytes(5));$this->owned=['myucto_s8cost_clone_'.$token.'_source_test','myucto_s8cost_clone_'.$token.'_target_test'];$this->server=new PDO('mysql:host='.$this->config->get('db.host').';port='.$this->config->get('db.port',3306).';charset=utf8mb4',$this->config->get('db.user'),$this->config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$q=$this->server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?,?)');$q->execute($this->owned);self::assertSame(0,(int)$q->fetchColumn());$this->ownsSchemas=true;$cloner=new DatabaseCloner($this->server);$plan=$cloner->plan($base);$cloner->copyTables($plan,$this->owned[0],static function():void{});$cloner->finalize($plan,$this->owned[0]);putenv('MYINVOICE_DB_NAME='.$this->owned[0]);$config=$this->config->all();$config['db']['name']=$this->owned[0];$this->source=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($config,$this->config->dataDir())));$this->source->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");$this->source->pdo()->beginTransaction();$container=Bootstrap::buildContainer();$container->set(Connection::class,$this->source);$this->fixture=new SettlementCompositeCostTest('testReferenceNativeCompositeClears132kCostAndAdvanceWithoutInventedEntry');foreach(['db'=>$this->source,'container'=>$container] as $key=>$value)(new \ReflectionProperty($this->fixture,$key))->setValue($this->fixture,$value);
    }
    protected function tearDown():void
    {
        try{if(isset($this->source)){if($this->source->pdo()->inTransaction())$this->source->pdo()->rollBack();$this->source->close();}if($this->target!==null){if($this->target->pdo()->inTransaction())$this->target->pdo()->rollBack();$this->target->close();}if($this->ownsSchemas&&isset($this->server))foreach(array_reverse($this->owned) as $schema)OwnedTestDatabaseCleanup::drop($this->server,$schema,$this->owned);}finally{if(isset($this->priorDatabase))putenv($this->priorDatabase===false?'MYINVOICE_DB_NAME':'MYINVOICE_DB_NAME='.$this->priorDatabase);}
    }
    private function native(string $method,mixed ...$args):mixed{return(new \ReflectionMethod($this->fixture,$method))->invoke($this->fixture,...$args);}
    private function fingerprint(Connection $db):string
    {
        $all=[];foreach(['property_service_settlements','property_service_lifecycle_cases','property_service_lifecycle_events','property_service_settlement_applications'] as $table)$all[$table]=$db->pdo()->query('SELECT * FROM '.$table.' ORDER BY supplier_id,id')->fetchAll(PDO::FETCH_ASSOC);return hash('sha256',serialize($all));
    }
    public function testActualFullClonePreservesAllCompositeSourcesAndNativeReverse():void
    {
        $d=$this->native('base',false,true);$p=$this->native('applied',$d);$this->source->pdo()->commit();$before=$this->fingerprint($this->source);(new AnonymizationService($this->config))->run(new AnonymizationOptions($this->owned[0],$this->owned[1],seed:'synthetic-composite-cost-clone'),static function():void{});self::assertSame($before,$this->fingerprint($this->source));$config=$this->config->all();$config['db']['name']=$this->owned[1];$this->target=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($config,$this->config->dataDir())));$this->target->pdo()->beginTransaction();$container=Bootstrap::buildContainer();$container->set(Connection::class,$this->target);$doc=$container->get(SettlementDocumentRepository::class)->find($d['f']['supplier'],$p['saved']['id'],true);self::assertCount(2,$doc['document']['cost']['sources']);self::assertSame('13200000',$doc['document']['cost']['amount_cents']);self::assertSame(array_column($p['cost']['sources'],'source_id'),array_column($doc['document']['cost']['sources'],'source_id'));$json=json_encode($doc['document'],JSON_THROW_ON_ERROR);foreach(['composite.known@synthetic.invalid','composite.policy@synthetic.invalid','composite.final@synthetic.invalid','synthetic.context@example.test'] as $secret)self::assertStringNotContainsString($secret,$json);$closure=(new \MyInvoice\Repository\Property\Receivables\PropertySaldoReadClosure($this->target))->units($d['f']['supplier'],[$d['f']['units']['U02']]);self::assertNotEmpty($closure->rows['property_service_settlement_ledgers']);$owner=$container->get(SettlementApplicationRepository::class);$reverse=$owner->reverse($d['f']['supplier'],$p['done']['id'],'2027-04-02',1,'Synthetic native composite clone reversal','composite-clone-reverse',$d['f']['actor']);self::assertGreaterThan(0,$reverse['id']);self::assertTrue((new \MyInvoice\Service\Property\Receivables\PropertyReconciliation($this->target))->at($d['f']['supplier'],'2027-04-02',$d['f']['units']['U02'])['matches']);self::assertSame($before,$this->fingerprint($this->source));$this->target->pdo()->rollBack();
    }

    public function testMissingNativeCaptureFailsClosedBeforeCompositeCloneAcceptance():void
    {
        $d=$this->native('base');$this->native('applied',$d);$this->source->pdo()->commit();$before=$this->fingerprint($this->source);$cloner=new DatabaseCloner($this->server);$cloner->copyTables($cloner->plan($this->owned[0]),$this->owned[1],static function():void{});$config=$this->config->all();$config['db']['name']=$this->owned[1];$this->target=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($config,$this->config->dataDir())));$this->target->pdo()->beginTransaction();try{\MyInvoice\Service\Anonymization\SettlementCostCloneProof::verify($this->target,[]);self::fail('Composite clone accepted without its native pre-transform proof.');}catch(\LogicException $error){self::assertSame('Composite service clone requires its original native proof capture.',$error->getMessage());}self::assertSame($before,$this->fingerprint($this->source));$this->target->pdo()->rollBack();
    }
}
