<?php
declare(strict_types=1);
require_once __DIR__.'/TransferRelations.php';
require_once __DIR__.'/AdminAuth.php';
require_once __DIR__.'/ManualMethods.php';
require_once __DIR__.'/GiftCatalog.php';

/** Publishes selected, validated business settings directly from full-v2, without replaying save side effects. */
final class BusinessSettingsTransfer
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private static function canonical(string $json):string
    {
        $sort=static function(mixed $v)use(&$sort):mixed{if($v instanceof stdClass){$a=get_object_vars($v);ksort($a,SORT_STRING);foreach($a as &$x)$x=$sort($x);return (object)$a;}if(is_array($v))return array_map($sort,$v);return $v;};
        return json_encode($sort(json_decode($json,false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING)),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    public function run(string $file,string $scope='all',bool $apply=false,?string $fingerprint=null):array
    {
        if(!in_array($scope,['all','manual-payments','gifts'],true))throw new InvalidArgumentException('Scope: all, manual-payments hoặc gifts.');
        if($apply&&(!is_string($fingerprint)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint)))throw new InvalidArgumentException('Cần fingerprint từ dry-run.');
        $r=['scope'=>'runtime-business-settings:'.$scope,'mode'=>$apply?'apply':'dry-run','ready'=>false,'applied'=>false,'operationalReady'=>false,'fingerprint'=>null,'sourceSha256'=>null,'settings'=>[],'inserted'=>0,'matching'=>0,'errors'=>[],'errorCount'=>0];
        $error=static function(string $code,?string $id=null)use(&$r):void{$r['errorCount']++;if(count($r['errors'])<100)$r['errors'][]=array_filter(['code'=>$code,'setting'=>$id],static fn($v)=>$v!==null);};
        if(TransferFormat::detect($file)!=='full-v2'){$error('FULL_V2_REQUIRED');return $r;}
        $audit=(new TransferRelations())->run($file);$r['sourceSha256']=$audit['sha256'];
        if(!$audit['profileMatches']||$audit['relations']['errorCount']){$error('SOURCE_FILE_OR_RELATIONS_INVALID');return $r;}
        $source=[];$giftOrders=0;$giftOrdersDeclared=false;$settingsDeclared=false;
        $again=TransferFormat::audit($file)->run($file,static function(string $table,array $columns,array $values)use(&$source,&$giftOrders,&$giftOrdersDeclared,&$settingsDeclared):void{
            if($table==='jm_transfer_gift_orders'){$giftOrders++;return;}
            if(!in_array($table,['jm_transfer_settings','jm_transfer_manifest'],true))return;
            $row=[];foreach($columns as $i=>$c){$v=$values[$i];$row[$c['name']]=$v==='NULL'?null:(str_starts_with($v,'CONVERT(')?hex2bin(substr($v,10,-16)):$v);}
            if($table==='jm_transfer_manifest'){if($row['source_table']==='jinmemo_gift_orders'&&$row['status']==='EXPORTED')$giftOrdersDeclared=true;if($row['source_table']==='jinmemo_settings'&&$row['status']==='FILTERED_BUSINESS_SETTINGS')$settingsDeclared=true;return;}
            if(strlen($row['data'])>2097152||isset($source[$row['id']]))throw new RuntimeException('Settings limit or duplicate.');$source[$row['id']]=$row;
        });
        if(!$again['profileMatches']||$again['sha256']!==$audit['sha256']){$error('SOURCE_CHANGED_DURING_READ');return $r;}
        if(!$settingsDeclared){$error('SETTINGS_NOT_EXPORTED');return $r;}
        $ids=$scope==='all'?['manual-payments','gifts']:[$scope];$incoming=[];
        foreach($ids as $id){
            if(!isset($source[$id])){$error('SOURCE_SETTING_MISSING',$id);continue;}$row=$source[$id];
            try{
                if(!preg_match('/^[1-9][0-9]{0,9}$/D',$row['version'])||(int)$row['version']>2147483646)throw new RuntimeException();
                $obj=json_decode($row['data'],false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);if(!$obj instanceof stdClass||property_exists($obj,'version'))throw new RuntimeException();
                $data=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);$input=$data+['version'=>0];
                $valid=$id==='manual-payments'?ManualMethods::validate($input):GiftCatalog::validate($input);
                if(self::canonical($row['data'])!==self::canonical(json_encode($valid,JSON_THROW_ON_ERROR)))throw new RuntimeException();
                $incoming[$id]=$row;
            }catch(Throwable){$error('SETTING_NOT_RUNTIME_COMPATIBLE',$id);}
        }
        if(isset($incoming['gifts'])&&(!$giftOrdersDeclared||$giftOrders>0))$error('GIFT_ORDER_IMPORT_REQUIRED_BEFORE_STOCK','gifts');
        if($r['errorCount'])return $r;
        foreach(['jingo_settings','jingo_audit','jingo_catalog_locks','jingo_media','jingo_gift_orders'] as $table){$engine=$this->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE=\'BASE TABLE\'',[$table])->fetchColumn();if(strtoupper((string)$engine)!=='INNODB')$error('RUNTIME_SCHEMA_MISSING',$table);}
        $columns=$this->query("SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_SET_NAME,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jingo_settings' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
        if(array_column($columns,'COLUMN_NAME')!==['id','data','version'])$error('RUNTIME_SETTINGS_SCHEMA_MISMATCH');
        foreach($columns as $c){$valid=match($c['COLUMN_NAME']){'id'=>in_array($c['DATA_TYPE'],['char','varchar'],true)&&(int)$c['CHARACTER_MAXIMUM_LENGTH']>=80,'data'=>$c['DATA_TYPE']==='json'||$c['DATA_TYPE']==='longtext'&&$c['CHARACTER_SET_NAME']==='utf8mb4','version'=>$c['DATA_TYPE']==='int',default=>false};if(!$valid||$c['IS_NULLABLE']!=='NO')$error('RUNTIME_SETTINGS_CAPACITY_MISMATCH');}
        if($this->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jingo_settings' AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN)!==['id'])$error('RUNTIME_SETTINGS_PRIMARY_KEY_MISMATCH');
        if($r['errorCount'])return $r;
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');if(!$apply)$this->db->exec('SET TRANSACTION READ ONLY');$this->db->beginTransaction();
        try{
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog'".($apply?' FOR UPDATE':''))->fetchColumn())throw new RuntimeException('Missing catalog lock.');
            $plan=[];$new=[];
            foreach($incoming as $id=>$row){
                $target=$this->query('SELECT data,version FROM jingo_settings WHERE id=?'.($apply?' FOR UPDATE':''),[$id])->fetch(PDO::FETCH_ASSOC);
                if($target){if((string)$target['version']!==$row['version']||self::canonical($target['data'])!==self::canonical($row['data']))$error('EXISTING_SETTING_DIFFERS',$id);else $r['matching']++;}
                else $new[$id]=$row;
                $plan[$id]=['data'=>$row['data'],'version'=>$row['version'],'target'=>$target?:null];$r['settings'][$id]=['status'=>$target?'MATCHING_OR_CONFLICT':'NEW','version'=>$row['version']];
                if($id==='gifts'){
                    $g=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);(new Media($this->db,''))->validateIds(array_column($g['items'],'imageId'));
                    $features=$this->query("SELECT data FROM jingo_settings WHERE id='features'".($apply?' FOR UPDATE':''))->fetchColumn();
                    if($features!==false&&(json_decode($features,true,512,JSON_THROW_ON_ERROR)['giftsEnabled']??false)!==$g['enabled'])$error('FEATURE_FLAG_CONFLICT','gifts');
                    // Existing gift orders may have already changed the runtime stock.
                    if(!$target&&$this->query('SELECT 1 FROM jingo_gift_orders LIMIT 1')->fetchColumn())$error('RUNTIME_GIFT_ORDERS_EXIST','gifts');
                }
            }
            $fp=hash('sha256','runtime-business-settings-v1:'.$scope.':'.$audit['sha256'].':'.json_encode($plan,JSON_THROW_ON_ERROR));
            if($apply&&!hash_equals($fingerprint,$fp))$error('FINGERPRINT_OR_TARGET_CHANGED');
            if(!is_file($file)||is_link($file)||hash_file('sha256',$file)!==$audit['sha256'])$error('SOURCE_CHANGED_BEFORE_COMMIT');
            $r['ready']=$r['errorCount']===0;$r['fingerprint']=$r['ready']?$fp:null;
            if(!$apply||!$r['ready']){$this->db->rollBack();return $r;}
            foreach($new as $id=>$row){$this->query('INSERT INTO jingo_settings(id,data,version) VALUES (?,?,?)',[$id,$row['data'],$row['version']]);$r['inserted']++;}
            if($new)$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),'cli-import','transfer.business-settings',$fp]);
            $this->db->commit();$r['applied']=true;return $r;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
