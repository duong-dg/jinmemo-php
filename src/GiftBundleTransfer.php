<?php
declare(strict_types=1);

/** Gift-only bundle graph. Missing lookup credentials are explicit NULL/pending, never fabricated. */
final class GiftBundleTransfer
{
    public function __construct(private PDO $db){$db->exec("SET time_zone='+00:00'");}
    private function query(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    public static function canonical(mixed $v):string
    {
        $sort=static function(mixed $v)use(&$sort):mixed{if($v instanceof stdClass){$a=get_object_vars($v);ksort($a,SORT_STRING);foreach($a as &$x)$x=$sort($x);return (object)$a;}return is_array($v)?array_map($sort,$v):$v;};return json_encode($sort($v),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    public static function refundDigest(array $rows):string
    {
        $out=[];foreach($rows as $row){$row['amount']=(string)$row['amount'];$row['data']=json_decode($row['data'],false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);ksort($row,SORT_STRING);$out[$row['id']]=self::canonical($row);}ksort($out,SORT_STRING);return hash('sha256',self::canonical($out));
    }
    public static function giftDigest(array $row):string{return hash('sha256',self::canonical([$row['id'],$row['code'],json_decode($row['data'],false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING)]));}
    public static function graphDigest(array $row,array $members,array $receipts):string
    {
        $row['total']=(string)$row['total'];$row['data']=json_decode($row['data'],false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);ksort($row,SORT_STRING);
        foreach($members as &$m)ksort($m,SORT_STRING);unset($m);foreach($receipts as &$r){$r['amount']=(string)$r['amount'];ksort($r,SORT_STRING);}unset($r);usort($members,static fn($a,$b)=>strcmp($a['kind'].':'.$a['entity_id'],$b['kind'].':'.$b['entity_id']));usort($receipts,static fn($a,$b)=>strcmp($a['reference'],$b['reference']));return hash('sha256',self::canonical([$row,$members,$receipts]));
    }
    public static function prepare(array $gifts,array $bundles,array $members,array $references,array $refunds):array
    {
        $giftMap=array_column($gifts,null,'id');$selected=[];$contexts=[];$errors=[];$refundMap=[];$refMap=[];$memberMap=[];
        foreach($refunds as $r)$refundMap[$r['root_kind'].':'.$r['root_id']][]=$r;
        foreach($references as $r)$refMap[$r['entity_id']][]=$r;
        foreach($members as $m)$memberMap[$m['bundle_id']][]=$m;
        foreach($bundles as $bundle){
            $d=json_decode($bundle['data'],false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);$id=$d->refs->giftId??null;if($id===null)continue;if(!is_string($id)||!isset($giftMap[$id])){$errors[]='GIFT_BUNDLE_CHILD_MISSING';continue;}
            try{
                if(count($selected)>=5000||isset($contexts[$id])||!preg_match('/^JB[A-F0-9]{10}$/D',$bundle['code'])||!in_array($bundle['status'],['PENDING','PAID'],true))throw new RuntimeException('GIFT_BUNDLE_INVALID');
                if($bundle['created_at']<'1970-01-01 00:00:01.000000'||$bundle['created_at']>'2038-01-19 03:14:07.999999')throw new RuntimeException('BUNDLE_TIME_OUTSIDE_RUNTIME_RANGE');
                if(!$d->refs instanceof stdClass||array_keys(get_object_vars($d->refs))!==['giftId'])throw new RuntimeException('MIXED_BUNDLE_REQUIRES_COMPONENT_ADAPTERS');
                $links=$memberMap[$bundle['id']]??[];if(count($links)!==1||$links[0]['kind']!=='giftId'||$links[0]['entity_id']!==$id)throw new RuntimeException('GIFT_BUNDLE_MEMBERS_DIFFER');
                if(array_diff(array_keys(get_object_vars($d)),['refs','amounts','locale','method','manual','fingerprint','contact','codes','paidAt','reference','paidMethod'])||!is_string($d->fingerprint??null)||!preg_match('/^[a-f0-9]{64}$/D',$d->fingerprint)||!in_array($d->locale??null,['vi','en'],true)||!is_string($d->method??null)||!preg_match('/^(?:CASH|PAY_LATER|MANUAL_[a-zA-Z0-9_-]{1,60}|VNPAY|VNPAY_QR|VNPAY_CARD|SEPAY|SEPAY_CARD|PAYPAL)$/D',$d->method))throw new RuntimeException('GIFT_BUNDLE_INVALID');
                $g=json_decode($giftMap[$id]['data'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);$total=$g['total']??null;
                if(!is_int($total)||$total<1||$total>15000000000||(string)$total!==$bundle['total']||!($d->amounts??null) instanceof stdClass||get_object_vars($d->amounts)!==[$id=>$total]||!($d->codes??null) instanceof stdClass||get_object_vars($d->codes)!==['gifts'=>$giftMap[$id]['code']])throw new RuntimeException('GIFT_BUNDLE_TOTAL_OR_CODES_DIFFER');
                if(!($d->contact??null) instanceof stdClass||($d->contact->name??null)!==($g['name']??null)||($d->contact->phone??null)!==($g['phone']??null)||array_diff(array_keys(get_object_vars($d->contact)),['name','phone']))throw new RuntimeException('GIFT_BUNDLE_CONTACT_DIFFERS');
                if(isset($d->manual)){if(!$d->manual instanceof stdClass||!is_string($d->manual->id??null)||$d->manual->id!==$d->method||array_diff(array_keys(get_object_vars($d->manual)),['id','name','englishName','instructions','englishInstructions']))throw new RuntimeException('GIFT_BUNDLE_MANUAL_METHOD_INVALID');foreach(['name','englishName','instructions','englishInstructions'] as $key)if(isset($d->manual->$key)&&!is_string($d->manual->$key))throw new RuntimeException('GIFT_BUNDLE_MANUAL_METHOD_INVALID');}
                $receipts=$refMap[$bundle['id']]??[];$history=$refundMap['BUNDLE:'.$bundle['id']]??[];
                if($bundle['status']==='PAID'){
                    if(count($receipts)!==1||$receipts[0]['kind']!=='BUNDLE_PAYMENT'||$receipts[0]['amount']!==$bundle['total']||mb_strlen($receipts[0]['reference'],'UTF-8')>200||$receipts[0]['reference']===''||str_contains($receipts[0]['reference'],"\0")||($d->reference??null)!==$receipts[0]['reference']||!in_array($d->paidMethod??null,['CASH','BANK','VNPAY','SEPAY','SEPAY_CARD','PAYPAL'],true)||!is_string($d->paidAt??null)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{3})?Z$/D',$d->paidAt))throw new RuntimeException('GIFT_BUNDLE_RECEIPT_INVALID');
                    $format=strlen($d->paidAt)===24?'Y-m-d\\TH:i:s.v\\Z':'Y-m-d\\TH:i:s\\Z';$date=DateTimeImmutable::createFromFormat('!'.$format,$d->paidAt,new DateTimeZone('UTC'));if(!$date||$date->format($format)!==$d->paidAt)throw new RuntimeException('GIFT_BUNDLE_PAID_DATE_INVALID');
                    foreach(['paymentStatus'=>'PAID','paidAmount'=>$total,'paymentReference'=>$d->reference,'paidAt'=>$d->paidAt,'bundleCode'=>$bundle['code']] as $key=>$expected)if(($g[$key]??null)!==$expected)throw new RuntimeException('GIFT_BUNDLE_CHILD_PAYMENT_DIFFERS');
                }else{
                    if($receipts||$history||array_intersect(array_keys(get_object_vars($d)),['paidAt','reference','paidMethod'])||array_intersect(array_keys($g),['paidAt','paymentReference','bundleCode'])||array_key_exists('paymentStatus',$g)&&$g['paymentStatus']!=='UNPAID'||array_key_exists('paidAmount',$g)&&$g['paidAmount']!==0)throw new RuntimeException('PENDING_GIFT_BUNDLE_HAS_FINANCIAL_HISTORY');
                }
                $sum=0;foreach($history as $r){if($r['root_code']!==$bundle['code']||!preg_match('/^[1-9][0-9]{0,10}$/D',$r['amount'])||(int)$r['amount']>15000000000||$sum>$total-(int)$r['amount'])throw new RuntimeException('GIFT_BUNDLE_REFUNDS_INVALID');$sum+=(int)$r['amount'];}
                $sha=self::graphDigest($bundle,$links,$receipts);$selected[$bundle['id']]=['row'=>$bundle,'member'=>$links[0],'receipts'=>$receipts,'bundle_sha'=>$sha,'gift_sha'=>self::giftDigest($giftMap[$id]),'refunds_digest'=>self::refundDigest($history)];$contexts[$id]=$bundle['id'];
            }catch(RuntimeException $e){$errors[]=$e->getMessage();}
        }
        foreach($members as $m)if($m['kind']==='giftId'&&isset($giftMap[$m['entity_id']])&&($contexts[$m['entity_id']]??null)!==$m['bundle_id'])$errors[]='GIFT_BUNDLE_SOURCE_LINK_UNRESOLVED';
        return ['bundles'=>$selected,'contexts'=>$contexts,'errors'=>array_values(array_unique($errors))];
    }
    public function schema():array
    {
        $errors=[];foreach(['jingo_checkout_bundles','jingo_checkout_members','jingo_bundle_transfers','jingo_payment_references'] as $table){$engine=$this->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE=\'BASE TABLE\'',[$table])->fetchColumn();if(strtoupper((string)$engine)!=='INNODB')$errors[]='BUNDLE_SCHEMA_062_REQUIRED';}
        $nullable=$this->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jingo_checkout_bundles' AND COLUMN_NAME='access_hash'")->fetchColumn();$pending=$this->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jingo_checkout_bundles' AND COLUMN_NAME='lookup_pending'")->fetchColumn();$precision=$this->query("SELECT DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jingo_payment_references' AND COLUMN_NAME='created_at'")->fetchColumn();if($nullable!=='YES'||$pending!=='tinyint'||(int)$precision!==6)$errors[]='BUNDLE_SCHEMA_062_REQUIRED';
        $specs=[
            'jingo_checkout_bundles'=>['id'=>'string:36:ascii','code'=>'string:12:ascii','access_hash'=>'string:64:ascii','total'=>'bigint','status'=>'string:8:ascii','data'=>'json','created_at'=>'timestamp6','lookup_pending'=>'tinyint'],
            'jingo_checkout_members'=>['kind'=>'string:16:ascii','entity_id'=>'string:80:ascii','bundle_id'=>'string:36:ascii'],
            'jingo_bundle_transfers'=>['bundle_id'=>'string:36:ascii','source_sha'=>'string:64:ascii','bundle_sha'=>'string:64:ascii','gift_sha'=>'string:64:ascii','refunds_digest'=>'string:64:ascii'],
            'jingo_payment_references'=>['reference_hash'=>'string:64:ascii','reference'=>'string:200:utf8mb4','kind'=>'string:32:','entity_id'=>'string:36:ascii','amount'=>'bigint','created_at'=>'datetime6']
        ];
        foreach($specs as $table=>$spec){$columns=$this->query('SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_SET_NAME,IS_NULLABLE,DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',[$table])->fetchAll(PDO::FETCH_ASSOC);if(array_column($columns,'COLUMN_NAME')!==array_keys($spec))$errors[]='BUNDLE_RUNTIME_SCHEMA_MISMATCH';
            foreach($columns as $c){$rule=$spec[$c['COLUMN_NAME']]??'';if(str_starts_with($rule,'string:')){[, $min,$charset]=explode(':',$rule);$ok=in_array($c['DATA_TYPE'],['char','varchar'],true)&&(int)$c['CHARACTER_MAXIMUM_LENGTH']>=(int)$min&&($charset===''||$c['CHARACTER_SET_NAME']===$charset);}else $ok=match($rule){'json'=>$c['DATA_TYPE']==='json'||$c['DATA_TYPE']==='longtext'&&$c['CHARACTER_SET_NAME']==='utf8mb4','timestamp6','datetime6'=>$c['DATA_TYPE']===substr($rule,0,-1)&&(int)$c['DATETIME_PRECISION']===6,'bigint','tinyint'=>$c['DATA_TYPE']===$rule,default=>false};if(!$ok||$c['IS_NULLABLE']!==($table==='jingo_checkout_bundles'&&$c['COLUMN_NAME']==='access_hash'?'YES':'NO'))$errors[]='BUNDLE_RUNTIME_CAPACITY_MISMATCH';}
            $keys=[];foreach($this->query('SELECT INDEX_NAME,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND NON_UNIQUE=0 ORDER BY INDEX_NAME,SEQ_IN_INDEX',[$table])->fetchAll(PDO::FETCH_ASSOC) as $k)$keys[$k['INDEX_NAME']][]=$k['COLUMN_NAME'];$expected=match($table){'jingo_checkout_bundles'=>['id'],'jingo_checkout_members'=>['kind','entity_id'],'jingo_bundle_transfers'=>['bundle_id'],default=>['reference_hash']};if(($keys['PRIMARY']??[])!==$expected||$table==='jingo_checkout_bundles'&&!in_array(['code'],$keys,true))$errors[]='BUNDLE_RUNTIME_KEYS_MISMATCH';
        }
        return array_values(array_unique($errors));
    }
    public function plan(array $prepared,bool $lock):array
    {
        if(!$this->db->inTransaction())throw new LogicException('Bundle plan requires transaction.');
        $new=[];$newRefs=[];$states=[];$errors=[];$matching=0;$suffix=$lock?' FOR UPDATE':'';
        foreach($prepared['bundles'] as $id=>$b){$row=$b['row'];$target=$this->query('SELECT * FROM jingo_checkout_bundles WHERE id=? OR code=?'.$suffix,[$id,$row['code']])->fetchAll(PDO::FETCH_ASSOC);
            if($target){$t=count($target)===1?$target[0]:null;$control=$this->query('SELECT * FROM jingo_bundle_transfers WHERE bundle_id=?'.$suffix,[$id])->fetch(PDO::FETCH_ASSOC);$same=$t&&$t['id']===$id&&$t['code']===$row['code']&&(string)$t['total']===$row['total']&&$t['status']===$row['status']&&$t['created_at']===$row['created_at']&&$control&&$control['bundle_sha']===$b['bundle_sha']&&$control['gift_sha']===$b['gift_sha']&&$control['refunds_digest']===$b['refunds_digest'];
                if($t){$data=json_decode($t['data'],false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);$secret=$data->secret??null;unset($data->secret);if(self::canonical($data)!==self::canonical(json_decode($row['data'],false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING)))$same=false;if(!in_array((int)$t['lookup_pending'],[0,1],true)||(int)$t['lookup_pending']===1&&($t['access_hash']!==null||$secret!==null))$same=false;if((int)$t['lookup_pending']===0&&(!is_string($t['access_hash'])||!preg_match('/^[a-f0-9]{64}$/D',$t['access_hash'])||!is_string($secret)||strlen(base64_decode($secret,true)?:'')!==92))$same=false;}
                if(!$same)$errors[]='EXISTING_BUNDLE_DIFFERS';else $matching++;$states[$id]=hash('sha256',self::canonical([$target,$control]));
            }else $new[$id]=$b;
            $links=$this->query('SELECT kind,entity_id,bundle_id FROM jingo_checkout_members WHERE bundle_id=? OR (kind=? AND entity_id=?) ORDER BY kind,entity_id'.$suffix,[$id,'giftId',$b['member']['entity_id']])->fetchAll(PDO::FETCH_ASSOC);
            if($target&&$links!==[$b['member']]||!$target&&$links)$errors[]='RUNTIME_GIFT_BUNDLE_MEMBERS_DIFFER';
            foreach($b['receipts'] as $receipt){$hash=hash('sha256',$receipt['reference']);$actual=$this->query('SELECT * FROM jingo_payment_references WHERE reference_hash=?'.$suffix,[$hash])->fetch(PDO::FETCH_ASSOC);if($actual){$expected=$receipt+['reference_hash'=>$hash];foreach($expected as $key=>$v)if((string)($actual[$key]??'')!==$v)$errors[]='EXISTING_BUNDLE_RECEIPT_DIFFERS';$states['receipt:'.$hash]=hash('sha256',self::canonical($actual));}elseif($target)$errors[]='MISSING_RUNTIME_BUNDLE_RECEIPT';else $newRefs[$hash]=$receipt;}
            $actualRefs=$this->query('SELECT reference_hash FROM jingo_payment_references WHERE entity_id=? ORDER BY reference_hash'.$suffix,[$id])->fetchAll(PDO::FETCH_COLUMN);$expectedRefs=array_map(static fn($p)=>hash('sha256',$p['reference']),$b['receipts']);if(array_diff($actualRefs,$expectedRefs))$errors[]='EXTRA_BUNDLE_RECEIPT';
            if(!$target&&$this->query("SELECT 1 FROM jingo_refunds WHERE root_kind='BUNDLE' AND root_id=? LIMIT 1".$suffix,[$id])->fetchColumn())$errors[]='ORPHAN_RUNTIME_BUNDLE_REFUND';
        }
        ksort($states,SORT_STRING);return ['new'=>$new,'newRefs'=>$newRefs,'digest'=>hash('sha256',self::canonical($states)),'matching'=>$matching,'errors'=>array_values(array_unique($errors))];
    }
    public function insert(array $plan,string $sourceSha):int
    {
        if(!$this->db->inTransaction())throw new LogicException('Bundle insert requires transaction.');
        foreach($plan['new'] as $id=>$b){$r=$b['row'];$this->query('INSERT INTO jingo_checkout_bundles(id,code,access_hash,total,status,data,created_at,lookup_pending) VALUES (?,?,NULL,?,?,?,?,1)',[$id,$r['code'],$r['total'],$r['status'],$r['data'],$r['created_at']]);$m=$b['member'];$this->query('INSERT INTO jingo_checkout_members(kind,entity_id,bundle_id) VALUES (?,?,?)',[$m['kind'],$m['entity_id'],$id]);$this->query('INSERT INTO jingo_bundle_transfers(bundle_id,source_sha,bundle_sha,gift_sha,refunds_digest) VALUES (?,?,?,?,?)',[$id,$sourceSha,$b['bundle_sha'],$b['gift_sha'],$b['refunds_digest']]);}
        foreach($plan['newRefs'] as $hash=>$r)$this->query('INSERT INTO jingo_payment_references(reference_hash,reference,kind,entity_id,amount,created_at) VALUES (?,?,?,?,?,?)',[$hash,$r['reference'],$r['kind'],$r['entity_id'],$r['amount'],$r['created_at']]);return count($plan['new']);
    }
}
