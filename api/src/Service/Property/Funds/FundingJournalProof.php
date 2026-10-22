<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Funds;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\PropertyInput;
final class FundingJournalProof
{
    public function __construct(private readonly Connection $db){}
    private function row(int $supplier,string $date,string $account,string $side,string $cents,array $dimensions=[],array $context=[],bool $active=false):array
    {
        $table=$active?\MyInvoice\Repository\AccountDefinitionRepository::relation($date,$supplier,true):'chart_of_accounts';$fields=$active?'id,is_active':'id';$q=$this->db->pdo()->prepare('SELECT '.$fields.' FROM '.$table.' a WHERE supplier_id=? AND account_code=? FOR UPDATE');$q->execute([$supplier,$account]);$found=$q->fetch(\PDO::FETCH_ASSOC);if(!$found||($active&&!$found['is_active']))FundingException::reject('account_missing',['account_code'=>$account]);$line=['account_id'=>(int)$found['id'],'account_code'=>$account,'side'=>$side,'amount'=>bcdiv($cents,'100',2),'dimensions'=>$dimensions,'dimension_origins'=>array_fill_keys(array_keys($dimensions),['origin'=>'module','ref'=>'property_funding'])];foreach(['dimension_splits','dimension_split_origins','cost_center','project_id'] as $field)if(($context[$field]??null)!==null&&$context[$field]!==[])$line[$field]=$context[$field];return $line;
    }
    public function lines(int $supplier,array $plan,bool $active=false):array
    {
        $r=$plan['request'];$proofs=$plan['proofs'];$rows=[];$source=new FundingSourceProof($this->db);$role=\MyInvoice\Service\Accounting\AccountRoleResolver::fromConnection($this->db);
        if($r['kind']==='draw'){
            $cost=$proofs['cost']['line'];$context=$cost['dimensions'];$q=$this->db->pdo()->prepare("SELECT id FROM dimension_types WHERE supplier_id=? AND system_key IN ('unit','service')");$q->execute([$supplier]);foreach($q->fetchAll(\PDO::FETCH_COLUMN) as $type)unset($context[(int)$type]);$coverage=$role->resolve($supplier,$r['classification']==='administration'?'svj.coverage.admin':'svj.coverage.long_term',$r['accounting_date']);
            foreach($r['sources'] as $i=>$selection){$fund=$proofs['advances'][$i]??throw new FundingException('property_funding_source_binding');$dims=$source->dimensions($supplier,$fund['unit_id']);$rows[]=$this->row($supplier,$r['accounting_date'],$fund['account_code'],'debit',$selection['amount_cents'],$dims,active:$active);$rows[]=$this->row($supplier,$r['accounting_date'],$coverage,'credit',$selection['amount_cents'],$context+$dims,$cost,$active);}
        }elseif($r['kind']==='income'){
            $rows[]=$this->row($supplier,$r['accounting_date'],$proofs['bank']['money_account'],'debit',$r['amount_cents'],active:$active);foreach($proofs['registry']['calculation']['lines'] as $part)if($part['amount_cents']!=='0')$rows[]=$this->row($supplier,$r['accounting_date'],$role->resolve($supplier,'svj.owner_common_part_income',$r['accounting_date']),'credit',$part['amount_cents'],$source->dimensions($supplier,$part['unit_id']),active:$active);
        }elseif(in_array($r['kind'],['transfer','payout'],true)){
            foreach($r['dispositions'] as $i=>$d){$income=$proofs['income'][$i]??throw new FundingException('property_funding_source_binding');$dims=$source->dimensions($supplier,$income['unit_id']);$rows[]=$this->row($supplier,$r['accounting_date'],$role->resolve($supplier,'svj.owner_common_part_income',$r['accounting_date']),'debit',$d['amount_cents'],$dims,active:$active);if($r['kind']==='transfer')$rows[]=$this->row($supplier,$r['accounting_date'],$role->resolve($supplier,$r['target']==='long_term'?'svj.advance.long_term':'svj.advance.admin',$r['accounting_date']),'credit',$d['amount_cents'],$dims,active:$active);}
            if($r['kind']==='payout')$rows[]=$this->row($supplier,$r['accounting_date'],$proofs['bank']['money_account'],'credit',$r['amount_cents'],active:$active);
        }else foreach($proofs['original']['receipt']['ledger']['lines'] as $line){$line=\MyInvoice\Service\Accounting\JournalLineAmount::reversal($line);$q=$this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code=? FOR UPDATE');$q->execute([$supplier,$line['account_code']]);$line['account_id']=(int)$q->fetchColumn();$rows[]=$line;}
        if($rows===[])FundingException::reject('empty_operation');return $rows;
    }
    public function assertPlan(int $supplier,array $plan,?array $nativeLines=null):void
    {
        $q=$this->db->pdo()->prepare("SELECT o.id,o.parent_id,v.type_id,v.id value_id FROM property_objects o JOIN dimension_values v ON v.supplier_id=o.supplier_id AND v.id=o.dimension_value_id WHERE o.supplier_id=? AND o.kind='unit' FOR UPDATE");$q->execute([$supplier]);$units=[];$values=[];foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $unit){$units[(int)$unit['id']]=$unit;$values[(int)$unit['value_id']]=(int)$unit['id'];}
        $effects=[];$journal=[];$sums=[];$r=$plan['request'];
        $want=$this->lines($supplier,$plan);$canonical=\MyInvoice\Service\Property\Payments\PaymentJournalProof::canonicalLines(...);if(PropertyInput::hash($canonical($nativeLines??$plan['journal']['lines']))!==PropertyInput::hash($canonical($want)))FundingException::reject('financial_journal');
        foreach($plan['effects'] as $effect){$unit=$effect['unit_id'];$account=$effect['account_code'];$amount=$effect['amount_cents'];if(!isset($units[$unit])||(int)$units[$unit]['parent_id']!==$r['house_id']||!is_string($amount)||!preg_match('/^-?[1-9][0-9]*$/D',$amount)||(!str_starts_with($account,'955')&&!in_array($account,['324.200','379.920'],true)))FundingException::reject('financial_effect');$key=$unit.':'.$account;$effects[$key]=bcadd($effects[$key]??'0',$amount,0);$bucket=$account==='379.920'?'income':'advance';$sums[$bucket]=bcadd($sums[$bucket]??'0',$amount,0);}
        foreach($nativeLines??$plan['journal']['lines'] as $line){$account=$line['account_code'];if(!str_starts_with($account,'955')&&!in_array($account,['324.200','379.920'],true))continue;$matches=[];foreach($line['dimensions']??[] as $value)if(isset($values[$value]))$matches[]=$values[$value];if(count($matches)!==1||($line['dimension_splits']??[])!==[]||($line['currency_code']??null)!==null||($line['is_red_storno']??false))FundingException::reject('financial_journal');$key=$matches[0].':'.$account;$amount=bcmul($line['amount'],'100',0);$journal[$key]=bcadd($journal[$key]??'0',$line['side']==='credit'?$amount:'-'.$amount,0);}
        ksort($effects);ksort($journal);if($effects!==$journal)FundingException::reject('financial_reciprocity');
        $derived=[];
        if($r['kind']==='income')$derived=FundingSourceProof::incomeEffects($plan['proofs']['registry']);
        elseif($r['kind']==='draw'){
            foreach($r['sources'] as $i=>$selection){$fund=$plan['proofs']['advances'][$i]??null;if($fund===null||$fund['pointer']!==$selection['source'])FundingException::reject('source_binding');$derived[]=['unit_id'=>$fund['unit_id'],'account_code'=>$fund['account_code'],'amount_cents'=>'-'.$selection['amount_cents'],'source_kind'=>$selection['source']['kind'],'source_id'=>$selection['source']['id'],'parties'=>null];}
            $allocated=[];foreach($derived as $effect)$allocated[$effect['unit_id']]=bcadd($allocated[$effect['unit_id']]??'0',ltrim($effect['amount_cents'],'-'),0);foreach($plan['proofs']['registry']['calculation']['lines'] as $line)if(($allocated[$line['unit_id']]??'0')!==$line['amount_cents'])FundingException::reject('key_amount');

        }elseif(in_array($r['kind'],['transfer','payout'],true))foreach($r['dispositions'] as $i=>$d){$income=$plan['proofs']['income'][$i]??null;if($income===null||!FundingSourceProof::isIncomeOperation($income['operation'])||$income['id']!==$d['source_id'])FundingException::reject('source_binding');$effect=['unit_id'=>$income['unit_id'],'account_code'=>'379.920','amount_cents'=>'-'.$d['amount_cents'],'source_kind'=>'income','source_id'=>$d['source_id'],'parties'=>$income['parties']];$derived[]=$effect;if($r['kind']==='transfer')$derived[]=array_replace($effect,['account_code'=>$r['target']==='long_term'?'955.100':'324.200','amount_cents'=>$d['amount_cents'],'source_kind'=>null,'source_id'=>null]);}
        if($r['kind']!=='reverse'&&PropertyInput::hash($derived)!==PropertyInput::hash($plan['effects']))FundingException::reject('source_binding');
        $amount=$r['amount_cents']??'0';$expected=match($r['kind']){'draw'=>['advance'=>'-'.$amount],'income'=>['income'=>$amount],'transfer'=>['advance'=>$amount,'income'=>'-'.$amount],'payout'=>['income'=>'-'.$amount],'reverse'=>null};
        if($expected!==null){ksort($expected);ksort($sums);if($expected!==$sums)FundingException::reject('financial_amount');}
        else{$expected=[];foreach($plan['proofs']['original']['receipt']['effects'] as $effect){unset($effect['id']);$effect['amount_cents']=bcsub('0',$effect['amount_cents'],0);$expected[]=$effect;}if(PropertyInput::hash($expected)!==PropertyInput::hash($plan['effects']))FundingException::reject('reversal_effect');}
    }
}
