<?php
declare(strict_types=1);
require_once __DIR__.'/AdminAuth.php';
require_once __DIR__.'/Pricing.php';


/** Holds source channel links and action history. Never executes actions or publishes orders. */
final class OrderRelationTransfer
{
    private array $errors=[];
    private int $errorCount=0;
    private int $bytes=0;
    private array $profile;
    public function __construct(private PDO $db)
    {
        $this->profile=json_decode((string)file_get_contents(dirname(__DIR__).'/docs/TRANSFER-EXPORT-SCHEMA.json'),true,512,JSON_THROW_ON_ERROR)['tables'];
        if(PHP_INT_SIZE<8)throw new RuntimeException('64-bit PHP required.');
    }
    private static function identifier(string $s):string
    {
        if(!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_$-]{0,63}$/D',$s))throw new InvalidArgumentException('Tên database không hợp lệ.');return '`'.$s.'`';
    }
    private function query(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private function error(string $table,int $row,string $code):void
    {
        $this->errorCount++;if(count($this->errors)<100)$this->errors[]=['table'=>$table,'row'=>$row,'code'=>$code];
    }
    private static function stable(mixed $v):mixed
    {
        if($v instanceof stdClass){$a=get_object_vars($v);ksort($a,SORT_STRING);foreach($a as &$x)$x=self::stable($x);unset($x);return(object)$a;}
        return is_array($v)?array_map([self::class,'stable'],$v):$v;
    }
    private static function timestamp(string $s):string
    {
        if(preg_match('/^[1-9][0-9]{3}-\d\d-\d\d \d\d:\d\d:\d\d(?:\.\d{1,6})?$/D',$s)!==1)throw new RuntimeException('Invalid timestamp.');
        $s=str_contains($s,'.')?str_pad($s,26,'0'):$s.'.000000';$d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$s,new DateTimeZone('UTC'));
        if(!$d||$d->format('Y-m-d H:i:s.u')!==$s)throw new RuntimeException('Invalid timestamp.');return $s;
    }
    private static function canonical(array $r):string
    {
        unset($r['transfer_pending']);
        foreach(['snapshot','contact','data'] as $key)if(isset($r[$key]))$r[$key]=json_decode($r[$key],false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        if(isset($r['created_at']))$r['created_at']=self::timestamp($r['created_at']);
        foreach(['version','released'] as $key)if(isset($r[$key]))$r[$key]=(string)$r[$key];
        return json_encode(self::stable((object)$r),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
    private function schema(string $database,string $table,array $columns):void
    {
        $s=$this->query('SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$database,$table])->fetch(PDO::FETCH_ASSOC);
        $actual=$this->query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$database,$table])->fetchAll(PDO::FETCH_COLUMN);sort($actual);sort($columns);
        if(!$s||$s['TABLE_TYPE']!=='BASE TABLE'||strtoupper($s['ENGINE']??'')!=='INNODB'||$actual!==$columns)$this->error($table,0,'SCHEMA_MISMATCH');
    }
    private function rows(string $database,string $table,string $lock='',bool $idsOnly=false):array
    {
        $old=$this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);$q=null;$rows=[];$count=0;
        try{
            $q=$this->query('SELECT '.($idsOnly?'id':'*').' FROM '.self::identifier($database).'.'.self::identifier($table).$lock);
            while($r=$q->fetch(PDO::FETCH_ASSOC)){
                $this->bytes+=strlen(json_encode($r,JSON_THROW_ON_ERROR));
                if(++$count>50000||$this->bytes>33554432)throw new RuntimeException('Order relation metadata limit.');
                $key=$idsOnly?(string)$r['id']:strtolower((string)($r['id']??$r['order_id']));
                if(isset($rows[$key])){$this->error($table,$count,'DUPLICATE_KEY');continue;}
                $rows[$key]=$r;
            }
        }finally{if($q)$q->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$old);}return $rows;
    }
    private static function text(mixed $v,int $min,int $max):bool{return is_string($v)&&!str_contains($v,"\0")&&preg_match('//u',$v)===1&&mb_strlen($v,'UTF-8')>=$min&&mb_strlen($v,'UTF-8')<=$max;}
    private static function uuid(mixed $v):bool{return is_string($v)&&preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$v)===1;}
    private const TABLES=['partner_orders','pos_orders','order_actions'];
    private function valid(string $table,array $r,array $parents,array $partners,array $counters):void
    {
        if(!self::uuid($r['order_id'])||!isset($parents[strtolower($r['order_id'])])||$parents[strtolower($r['order_id'])]['id']!==$r['order_id'])throw new RuntimeException();
        if($table==='partner_orders'){
            if(!self::uuid($r['partner_id'])||!isset($partners[$r['partner_id']]))throw new RuntimeException();
        }elseif($table==='pos_orders'){
            if(!is_string($r['counter_id'])||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$r['counter_id'])||!isset($counters[$r['counter_id']])||!self::text($r['admin_id'],1,64))throw new RuntimeException();
        }else{
            if(!self::uuid($r['id'])||!self::text($r['admin_id'],1,64)||!in_array($r['action'],['ARCHIVE','RESTORE','INSTRUCTIONS','CONFIRM','CANCEL','CREDIT','PAY','DELIVER','ATTACH_FILE','REVOKE_FILE'],true)||!is_string($r['request_hash'])||!(preg_match('/^[a-f0-9]{64}$/D',$r['request_hash'])||$r['action']==='REVOKE_FILE'&&self::uuid($r['request_hash']))||!self::text($r['note'],0,65535)||strlen($r['note'])>65535)throw new RuntimeException();
            self::timestamp($r['created_at']);
        }
    }
    public function run(string $source,bool $apply=false,?string $fingerprint=null):array
    {
        self::identifier($source);$target=(string)$this->db->query('SELECT DATABASE()')->fetchColumn();self::identifier($target);
        if(strcasecmp($source,$target)===0)throw new InvalidArgumentException('Staging phải khác database PHP.');
        if($apply&&(!is_string($fingerprint)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint)))throw new InvalidArgumentException('Cần fingerprint từ dry-run trước --apply.');
        $this->errors=[];$this->errorCount=0;$this->bytes=0;
        $report=['scope'=>'order-relations-holding-only','mode'=>$apply?'apply':'dry-run','sourceDatabase'=>$source,'targetDatabase'=>$target,'ready'=>false,'applied'=>false,'operationalReady'=>false,'fingerprint'=>null,'counts'=>[],'channels'=>['WEB'=>0,'CTV'=>0,'POS'=>0],'errors'=>[],'errorCount'=>0,
            'notImported'=>['live_order_relations','action_order_versions_not_in_source','archive_and_cart_links','partner_shares','transport_jobs','payments','inventory','stock','files','mail','partner_ledger','activation']];
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');if(!$apply)$this->db->exec('SET TRANSACTION READ ONLY');$this->db->beginTransaction();
        try{
            $coreColumns=array_column($this->profile['jm_transfer_orders'],'name');$this->schema($source,'jm_transfer_orders',$coreColumns);$this->schema($target,'jingo_transfer_orders',$coreColumns);
            foreach(['partners','counters'] as $table)$this->schema($source,'jm_transfer_'.$table,array_column($this->profile['jm_transfer_'.$table],'name'));
            foreach(self::TABLES as $table){
                $columns=array_column($this->profile['jm_transfer_'.$table],'name');$this->schema($source,'jm_transfer_'.$table,$columns);$this->schema($target,'jingo_transfer_'.$table,$columns);
                $types=$this->query('SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,DATETIME_PRECISION,CHARACTER_SET_NAME,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$target,'jingo_transfer_'.$table])->fetchAll(PDO::FETCH_ASSOC);
                foreach($types as $t){$name=$t['COLUMN_NAME'];$ok=match($name){'created_at'=>$t['DATA_TYPE']==='datetime'&&(int)$t['DATETIME_PRECISION']===6,'note'=>in_array($t['DATA_TYPE'],['text','longtext'],true)&&$t['CHARACTER_SET_NAME']==='utf8mb4','admin_id'=>in_array($t['DATA_TYPE'],['char','varchar'],true)&&(int)$t['CHARACTER_MAXIMUM_LENGTH']>=64&&$t['CHARACTER_SET_NAME']==='utf8mb4','request_hash'=>$t['DATA_TYPE']==='varchar'&&(int)$t['CHARACTER_MAXIMUM_LENGTH']>=64,default=>in_array($t['DATA_TYPE'],['char','varchar'],true)&&(int)$t['CHARACTER_MAXIMUM_LENGTH']>=(['id'=>36,'order_id'=>36,'partner_id'=>36,'counter_id'=>80,'admin_id'=>64,'action'=>24][$name]??PHP_INT_MAX)};if(!$ok||$t['IS_NULLABLE']!=='NO')$this->error($table,0,'RUNTIME_CAPACITY_MISMATCH');}
                $primary=$this->query('SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',[$target,'jingo_transfer_'.$table,'PRIMARY'])->fetchAll(PDO::FETCH_COLUMN);if($primary!==[$table==='order_actions'?'id':'order_id'])$this->error($table,0,'RUNTIME_PRIMARY_KEY_MISMATCH');
            }
            foreach(['jingo_catalog_locks','jingo_audit','jingo_partners','jingo_counters'] as $table){$r=$this->query('SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$target,$table])->fetch(PDO::FETCH_ASSOC);if(!$r||$r['TABLE_TYPE']!=='BASE TABLE'||strtoupper($r['ENGINE']??'')!=='INNODB')$this->error($table,0,'SCHEMA_MISMATCH');}
            if($this->errors){$report['errors']=$this->errors;$report['errorCount']=$this->errorCount;$this->db->rollBack();return $report;}
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog'".($apply?' FOR UPDATE':''))->fetchColumn())throw new RuntimeException('Missing catalog lock.');
            $parents=$this->rows($source,'jm_transfer_orders',$apply?' LOCK IN SHARE MODE':'');$heldParents=$this->rows($target,'jingo_transfer_orders',$apply?' FOR UPDATE':'');
            $partners=array_fill_keys(array_column($this->rows($target,'jingo_partners',$apply?' LOCK IN SHARE MODE':'',true),'id'),true);$counters=array_fill_keys(array_column($this->rows($target,'jingo_counters',$apply?' LOCK IN SHARE MODE':'',true),'id'),true);
            $partners=array_intersect_key($partners,array_fill_keys(array_column($this->rows($source,'jm_transfer_partners',$apply?' LOCK IN SHARE MODE':'',true),'id'),true));$counters=array_intersect_key($counters,array_fill_keys(array_column($this->rows($source,'jm_transfer_counters',$apply?' LOCK IN SHARE MODE':'',true),'id'),true));
            $hashes=['scope:order-relations-holding-only'];
            foreach($parents as $key=>$r){if(!isset($heldParents[$key])||self::canonical($heldParents[$key])!==self::canonical($r))$this->error('orders',0,'CORE_NOT_HELD_OR_DIFFERS');$hashes[]='orders:'.hash('sha256',self::canonical($r));}
            $src=[];$held=[];$pending=[];
            foreach(self::TABLES as $table){
                $src[$table]=$this->rows($source,'jm_transfer_'.$table,$apply?' LOCK IN SHARE MODE':'');$held[$table]=$this->rows($target,'jingo_transfer_'.$table,$apply?' FOR UPDATE':'');
                $report['counts'][$table]=['sourceRows'=>count($src[$table]),'newRows'=>0,'matchingRows'=>0,'insertedRows'=>0];$row=0;
                foreach($src[$table] as $key=>$r){
                    $row++;try{$this->valid($table,$r,$parents,$partners,$counters);$canonical=self::canonical($r);}catch(Throwable){$this->error($table,$row,'INVALID_RELATION_OR_PARENT');continue;}
                    $hashes[]=$table.':'.hash('sha256',$canonical);
                    if(isset($held[$table][$key])){if(self::canonical($held[$table][$key])!==$canonical)$this->error($table,$row,'EXISTING_HELD_DIFFERS');else$report['counts'][$table]['matchingRows']++;}
                    else{$pending[$table][]=$r;$report['counts'][$table]['newRows']++;}
                }
                foreach($held[$table] as $key=>$r)if(isset($parents[strtolower($r['order_id'])])&&!isset($src[$table][$key]))$this->error($table,0,'EXTRA_HELD_RELATION');
            }
            foreach($parents as $key=>$r){
                $isPartner=isset($src['partner_orders'][$key]);$isPos=isset($src['pos_orders'][$key]);
                if($isPartner&&$isPos)$this->error('orders',0,'MULTIPLE_CHANNELS');
                if($r['payment_status']==='CREDIT'&&!$isPartner)$this->error('orders',0,'CREDIT_WITHOUT_PARTNER');
                $snapshot=json_decode($r['snapshot'],true,512,JSON_THROW_ON_ERROR);if(($isPartner||$isPos)&&array_key_exists('discount',$snapshot))$this->error('orders',0,'NON_WEB_VOUCHER_SNAPSHOT');
                $report['channels'][$isPartner?'CTV':($isPos?'POS':'WEB')]++;
            }
            foreach($src['order_actions'] as $r)if($r['action']==='CREDIT'&&!isset($src['partner_orders'][strtolower((string)$r['order_id'])]))$this->error('order_actions',0,'CREDIT_ACTION_WITHOUT_PARTNER');
            sort($hashes,SORT_STRING);$calculated=hash('sha256',implode("\n",$hashes));if($apply&&!hash_equals($fingerprint,$calculated))$this->error('relations',0,'FINGERPRINT_MISMATCH');
            $report['errors']=$this->errors;$report['errorCount']=$this->errorCount;$report['ready']=$this->errorCount===0;$report['fingerprint']=$report['ready']?$calculated:null;
            if(!$apply||!$report['ready']){$this->db->rollBack();return $report;}
            foreach($pending as $table=>$rows){$columns=array_column($this->profile['jm_transfer_'.$table],'name');$insert=$this->db->prepare('INSERT INTO jingo_transfer_'.$table.' (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');foreach($rows as $r)$insert->execute(array_map(static fn($c)=>$r[$c],$columns));$report['counts'][$table]['insertedRows']=count($rows);}
            if($pending)$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),'cli-import','order-relations.hold-import','order-relations:'.$report['fingerprint']]);
            $this->db->commit();$report['applied']=true;return $report;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
