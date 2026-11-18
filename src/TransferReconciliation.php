<?php
declare(strict_types=1);
require_once __DIR__.'/OrderCoreTransfer.php';

/** Cross-table source checks. All amounts remain exact integers; never calls business write services. */
final class TransferReconciliation
{
    private array $result=[];
    private function error(string $code,string $table):void{$this->result['errorCount']++;if(count($this->result['errors'])<100)$this->result['errors'][]=['code'=>$code,'table'=>$table];}
    private static function index(array $rows,string $column='id'):array{$out=[];foreach($rows as $r)$out[$r[$column]]=$r;return $out;}
    private static function number(mixed $v,int $min=0):int
    {
        if(is_string($v)&&preg_match('/^-?\d+$/D',$v)){$digits=ltrim($v,'-0');if(strlen($digits)>16||strlen($digits)===16&&strcmp($digits,'9007199254740991')>0)throw new RuntimeException();$v=(int)$v;}
        if(!is_int($v)||$v<$min||$v>Pricing::MAX_SAFE)throw new RuntimeException();return $v;
    }
    private static function add(int $a,int $b):int{if($b>Pricing::MAX_SAFE-$a)throw new RuntimeException();return $a+$b;}
    private static function cost(int $a,int $b):int{if($a!==0&&$b>intdiv(Pricing::MAX_SAFE,$a))throw new RuntimeException();return $a*$b;}
    private static function scope(array $r):string{return json_encode([$r['product_id'],$r['variant_id'],$r['visit_date'],$r['slot_id']],JSON_THROW_ON_ERROR);}
    private function check(string $table,array $rows,callable $fn):void{foreach($rows as $r){$this->result['rowsChecked']++;try{$fn($r);}catch(Throwable){$this->error('INVALID_OR_INCONSISTENT_ROW',$table);}}}
    public function run(array $all):array
    {
        if(PHP_INT_SIZE<8)throw new RuntimeException('64-bit PHP required.');
        $this->result=['scope'=>'source-cross-table-reconciliation','status'=>'FAILED','rowsChecked'=>0,'errors'=>[],'errorCount'=>0,
            'checked'=>['order_snapshot_arithmetic','order_channels','payment_receipts_and_references','partner_debt','voucher_uses','inventory_buckets_and_allocations','supply_quantity_and_supplier_debt','ticket_stock_and_delivery','cart_members_and_totals'],
            'unchecked'=>['RUNTIME_ADAPTERS_NOT_VERIFIED','BOOKING_RIDE_GIFT_REFUND_AND_GATEWAY_JSON_SEMANTICS_NOT_FULLY_VERIFIED','SESSION_AND_CREDENTIAL_ACTIVATION_NOT_IMPLEMENTED']];
        $get=static fn(string $t)=>$all['jm_transfer_'.$t]??[];
        $orders=self::index($get('orders'));$snapshots=[];$partners=self::index($get('partners'));$owners=self::index($get('partner_orders'),'order_id');$pos=self::index($get('pos_orders'),'order_id');
        $vouchers=self::index($get('vouchers'));$uses=self::index($get('voucher_uses'),'order_id');$payments=self::index($get('order_payments'),'order_id');$refs=self::index($get('payment_references'),'reference');$methods=self::index($get('payment_methods'),'payment_id');
        $this->check('orders',$get('orders'),function($r)use(&$snapshots,$owners,$pos,$uses,$payments):void{
            OrderCoreTransfer::validateRecord($r);$s=json_decode($r['snapshot'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);$snapshots[$r['id']]=$s;
            if(isset($owners[$r['id']],$pos[$r['id']])||$r['payment_status']==='CREDIT'&&!isset($owners[$r['id']])||isset($s['discount'])&&(isset($owners[$r['id']])||isset($pos[$r['id']])||!isset($uses[$r['id']])))throw new RuntimeException();
            if($r['payment_status']==='PAID'&&!isset($payments[$r['id']]))throw new RuntimeException();
        });
        $this->check('order_payments',$get('order_payments'),function($r)use($orders,&$snapshots,$refs,$methods):void{
            $o=$orders[$r['order_id']]??null;$ref=$refs[$r['reference']]??null;$amount=self::number($r['amount']);
            if(!$o||$o['payment_status']!=='PAID'||$amount!==($snapshots[$r['order_id']]['total']??null)||!$ref||$ref['kind']!=='ORDER_PAYMENT'||$ref['entity_id']!==$r['order_id']||self::number($ref['amount'])!==$amount||empty($methods[$r['id']]['method']))throw new RuntimeException();
        });
        $charges=[];$balances=[];$repaid=[];$repaymentRefs=[];
        $this->check('partner_ledger',$get('partner_ledger'),function($r)use($owners,$orders,&$snapshots,$refs,&$charges,&$balances,&$repaid,&$repaymentRefs):void{
            $id=$r['partner_id'];
            if($r['kind']==='CHARGE'){
                $amount=self::number($r['amount']);if($r['order_id']===null||($owners[$r['order_id']]['partner_id']??null)!==$id||($orders[$r['order_id']]['payment_status']??null)!=='CREDIT'||($snapshots[$r['order_id']]['total']??null)!==$amount||isset($charges[$r['order_id']])||$r['due_at']===null||$r['reference']!==$orders[$r['order_id']]['code'])throw new RuntimeException();
                $charges[$r['order_id']]=true;$balances[$id]=self::add($balances[$id]??0,$amount);
            }elseif($r['kind']==='REPAYMENT'){
                if(!preg_match('/^-[0-9]+$/D',$r['amount'])||$r['order_id']!==null||$r['due_at']!==null||isset($repaymentRefs[$r['reference']]))throw new RuntimeException();$amount=self::number(substr($r['amount'],1),1);$ref=$refs[$r['reference']]??null;
                if(!$ref||$ref['kind']!=='PARTNER_REPAYMENT'||$ref['entity_id']!==$id||self::number($ref['amount'])!==$amount)throw new RuntimeException();$repaymentRefs[$r['reference']]=true;$repaid[$id]=self::add($repaid[$id]??0,$amount);
            }else throw new RuntimeException();
        });
        foreach($orders as $id=>$o)if($o['payment_status']==='CREDIT'&&!isset($charges[$id]))$this->error('CREDIT_WITHOUT_CHARGE','orders');
        foreach($repaid as $id=>$amount)if($amount>($balances[$id]??0))$this->error('NEGATIVE_PARTNER_BALANCE','partner_ledger');
        $this->check('payment_references',$get('payment_references'),function($r)use($payments,$repaymentRefs):void{
            self::number($r['amount']);if($r['kind']==='ORDER_PAYMENT'&&($payments[$r['entity_id']]['reference']??null)!==$r['reference']||$r['kind']==='PARTNER_REPAYMENT'&&!isset($repaymentRefs[$r['reference']]))throw new RuntimeException();
        });
        $activeUses=[];
        $this->check('voucher_uses',$get('voucher_uses'),function($r)use($orders,&$snapshots,$vouchers,&$activeUses):void{
            $o=$orders[$r['order_id']]??null;$v=$vouchers[$r['voucher_id']]??null;if(!$o||!$v||($snapshots[$r['order_id']]['discount']['code']??null)!==$v['code']||($r['released']==='1')!==($o['booking_status']==='CANCELLED'))throw new RuntimeException();
            if($r['released']==='0')$activeUses[$r['voucher_id']]=($activeUses[$r['voucher_id']]??0)+1;
        });
        $this->check('vouchers',$get('vouchers'),function($r)use(&$activeUses):void{$v=json_decode($r['data'],true,512,JSON_THROW_ON_ERROR);if(!array_key_exists('maxUses',$v))throw new RuntimeException();if($v['maxUses']!==null&&self::number($v['maxUses'],1)<($activeUses[$r['id']]??0))throw new RuntimeException();});
        $buckets=[];$allocated=[];$configs=self::index($get('inventory_configs'),'product_id');
        $this->check('inventory_buckets',$get('inventory_buckets'),function($r)use(&$buckets):void{$buckets[self::scope($r)]=self::number($r['capacity']);self::number($r['low_stock_threshold']);});
        $this->check('inventory_allocations',$get('inventory_allocations'),function($r)use($orders,&$snapshots,&$allocated,$buckets,$configs):void{
            $o=$orders[$r['order_id']]??null;$s=$snapshots[$r['order_id']]??null;$qty=self::number($r['quantity'],1);if(!$o||!$s||$r['product_id']!==$s['productId']||isset($s['variantId'])&&$r['variant_id']!==$s['variantId']||$r['visit_date']!==$s['date']||$r['slot_id']!==($s['timeSlot']['id']??'')||$qty!==array_sum(array_column($s['lines'],'quantity'))||($r['released']==='1')!==($o['booking_status']==='CANCELLED'))throw new RuntimeException();
            if($r['released']==='0'){$key=self::scope($r);if(($configs[$r['product_id']]['enabled']??'0')==='1'&&!isset($buckets[$key]))throw new RuntimeException();$allocated[$key]=self::add($allocated[$key]??0,$qty);}
        });
        foreach($allocated as $key=>$qty)if(isset($buckets[$key])&&$qty>$buckets[$key])$this->error('INVENTORY_OVER_CAPACITY','inventory_buckets');
        $lots=self::index($get('supply_lots'));$lotUsed=[];$supplierDebt=[];$supplierPaid=[];
        $this->check('supply_lots',$get('supply_lots'),function($r)use(&$supplierDebt):void{$cost=self::number($r['unit_cost']);if($r['unlimited']==='0'){$amount=self::cost(self::number($r['total_quantity']),$cost);$supplierDebt[$r['supplier_id']]=self::add($supplierDebt[$r['supplier_id']]??0,$amount);}});
        $supplyByPair=[];
        $this->check('supply_allocations',$get('supply_allocations'),function($r)use($lots,$orders,&$snapshots,&$lotUsed,&$supplyByPair):void{
            $lot=$lots[$r['lot_id']]??null;$o=$orders[$r['order_id']]??null;$s=$snapshots[$r['order_id']]??null;$qty=self::number($r['quantity'],1);self::number($r['unit_cost']);
            if(!$lot||!$o||!$s||$lot['product_id']!==$s['productId']||isset($s['variantId'])&&$lot['variant_id']!==$s['variantId']||($r['released']==='1')!==($o['booking_status']==='CANCELLED'))throw new RuntimeException();
            if($r['released']==='0'){$lotUsed[$r['lot_id']]=self::add($lotUsed[$r['lot_id']]??0,$qty);$supplyByPair[$r['order_id'].':'.$r['lot_id']]=$qty;}
        });
        foreach($lotUsed as $id=>$qty)try{if($lots[$id]['unlimited']==='0'&&$qty>self::number($lots[$id]['total_quantity']))$this->error('SUPPLY_OVER_CAPACITY','supply_lots');}catch(Throwable){$this->error('INVALID_LOT_QUANTITY','supply_lots');}
        $this->check('supply_purchases',$get('supply_purchases'),function($r)use($lots,&$supplierDebt):void{$lot=$lots[$r['lot_id']]??null;if(!$lot||$lot['unlimited']!=='1'||$lot['supplier_id']!==$r['supplier_id'])throw new RuntimeException();$amount=self::cost(self::number($r['quantity'],1),self::number($r['unit_cost']));$supplierDebt[$r['supplier_id']]=self::add($supplierDebt[$r['supplier_id']]??0,$amount);});
        $this->check('supplier_payments',$get('supplier_payments'),function($r)use(&$supplierPaid):void{$supplierPaid[$r['supplier_id']]=self::add($supplierPaid[$r['supplier_id']]??0,self::number($r['amount'],1));});
        foreach($supplierPaid as $id=>$amount)if($amount>($supplierDebt[$id]??0))$this->error('NEGATIVE_SUPPLIER_BALANCE','supplier_payments');
        $tickets=self::index($get('order_tickets'));$delivered=[];
        $this->check('order_tickets',$get('order_tickets'),function($r)use($orders,&$delivered):void{if(!isset($orders[$r['order_id']])||!in_array($orders[$r['order_id']]['payment_status'],['PAID','CREDIT'],true)||hash('sha256',$r['code'])!==$r['code_hash'])throw new RuntimeException();$delivered[$r['order_id']]=self::add($delivered[$r['order_id']]??0,self::number($r['covers'],1));});
        foreach($delivered as $id=>$qty)if($qty>array_sum(array_column($snapshots[$id]['lines']??[],'quantity')))$this->error('DELIVERED_GUESTS_EXCEED_ORDER','order_tickets');
        $issued=[];
        $this->check('ticket_stock',$get('ticket_stock'),function($r)use($tickets,$supplyByPair,&$issued):void{
            if(!in_array($r['status'],['AVAILABLE','BLOCKED','ISSUED'],true)||hash('sha256',$r['code'])!==$r['code_hash'])throw new RuntimeException();
            if($r['status']==='ISSUED'){$t=$tickets[$r['ticket_id']]??null;if(!$t||$t['order_id']!==$r['order_id']||$t['code_hash']!==$r['code_hash']||self::number($t['covers'])!==1||$t['kind']!=='SUPPLIER_CODE')throw new RuntimeException();$key=$r['order_id'].':'.$r['lot_id'];$issued[$key]=($issued[$key]??0)+1;if($issued[$key]>($supplyByPair[$key]??0))throw new RuntimeException();}
            elseif($r['order_id']!==null||$r['ticket_id']!==null)throw new RuntimeException();
        });
        $this->check('ticket_files',$get('ticket_files'),function($r)use($tickets):void{if(!isset($tickets[$r['ticket_id']])||$tickets[$r['ticket_id']]['order_id']!==$r['order_id']||!preg_match('/^[a-f0-9]{64}$/D',$r['hash']))throw new RuntimeException();self::number($r['bytes'],1);});
        $cartMembers=[];
        $this->check('cart_orders',$get('cart_orders'),function($r)use($orders,&$snapshots,&$cartMembers):void{if(!isset($orders[$r['order_id']]))throw new RuntimeException();$id=$r['cart_id'];$pos=self::number($r['position']);if(isset($cartMembers[$id][$pos]))throw new RuntimeException();$cartMembers[$id][$pos]=$r['order_id'];});
        $this->check('carts',$get('carts'),function($r)use($cartMembers,&$snapshots,$owners,$pos):void{
            $members=$cartMembers[$r['id']]??[];if(!$members||!in_array($r['channel'],['WEB','CTV','POS'],true))throw new RuntimeException();$sum=0;
            foreach($members as $id){$sum=self::add($sum,self::number($snapshots[$id]['total']??null));if($r['channel']==='CTV'&&($owners[$id]['partner_id']??null)!==$r['partner_id']||$r['channel']==='POS'&&($pos[$id]['counter_id']??null)!==$r['counter_id']||$r['channel']==='WEB'&&(isset($owners[$id])||isset($pos[$id])))throw new RuntimeException();}
            if($sum!==self::number($r['total']))throw new RuntimeException();
        });
        $this->result['status']=$this->result['errorCount']===0?'PASSED':'FAILED';return $this->result;
    }
}
