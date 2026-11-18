<?php
declare(strict_types=1);
require_once __DIR__.'/TransferRelations.php';
require_once __DIR__.'/TransferReconciliation.php';
require_once __DIR__.'/TransferRuntimePlan.php';

/** Lossless archive of every exported table. No writes to operating business tables. */
final class FullTransferHolding
{
    private array $profile;
    private array $relations;
    public function __construct(private PDO $db)
    {
        $this->profile=json_decode((string)file_get_contents(dirname(__DIR__).'/docs/TRANSFER-EXPORT-SCHEMA.json'),true,512,JSON_THROW_ON_ERROR);
        $this->relations=json_decode((string)file_get_contents(dirname(__DIR__).'/docs/TRANSFER-RELATIONS.json'),true,512,JSON_THROW_ON_ERROR);
        if($this->profile['schemaSha256']!==$this->relations['schemaSha256'])throw new RuntimeException('Profiles differ.');
    }
    private static function json(array $row):string{ksort($row,SORT_STRING);return json_encode((object)$row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private function key(string $table,array $row):string
    {
        $types=array_column($this->profile['tables'][$table],'type','name');$tuple=[];
        foreach($this->relations['tables'][$table]['primary'] as $column){
            if(!isset($row[$column])||!is_string($row[$column]))throw new RuntimeException('Missing primary key.');
            $v=$row[$column];if($types[$column]==='CHAR(36)')$v=strtolower($v);
            if($types[$column]==='BIGINT'){$negative=str_starts_with($v,'-');$v=ltrim($negative?substr($v,1):$v,'0');$v=$v===''?'0':($negative?'-'.$v:$v);}
            $tuple[]=$v;
        }
        return hash('sha256',json_encode($tuple,JSON_THROW_ON_ERROR));
    }
    private function query(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    public function run(string $file,bool $apply=false,?string $fingerprint=null,?string $checkedSourceSha256=null):array
    {
        if($apply&&(!is_string($fingerprint)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint)))throw new InvalidArgumentException('Cần fingerprint từ dry-run trước --apply.');
        $audit=(new TransferRelations())->run($file);
        $report=['scope'=>'all-exported-tables-holding-only','mode'=>$apply?'apply':'dry-run','ready'=>false,'applied'=>false,'operationalReady'=>false,'fingerprint'=>null,'sourceSha256'=>$audit['sha256'],'sourceCommit'=>$this->profile['sourceCommit'],
            'tables'=>[],'counts'=>['sourceRows'=>0,'newRows'=>0,'matchingRows'=>0,'insertedRows'=>0],'errors'=>[],'errorCount'=>0,
            'fileAudit'=>['profileMatches'=>$audit['profileMatches'],'formatValid'=>$audit['formatValid'],'errorCount'=>$audit['errorCount'],'errors'=>$audit['errors']],
            'relations'=>$audit['relations'],'reconciliation'=>null,
            'activationBlockers'=>['RUNTIME_ADAPTERS_AND_ATOMIC_PUBLICATION_NOT_COMPLETE','PHYSICAL_FILES_NOT_VERIFIED','STAGING_AND_CPANEL_ACCEPTANCE_NOT_COMPLETE']];
        $error=static function(string $code,?string $table=null)use(&$report):void{$report['errorCount']++;if(count($report['errors'])<100)$report['errors'][]=array_filter(['code'=>$code,'table'=>$table],static fn($v)=>$v!==null);};
        if(!$audit['profileMatches']||$audit['relations']['errorCount']!==0){$error('SOURCE_FILE_OR_RELATIONS_INVALID');return $report;}
        if($checkedSourceSha256!==null&&!hash_equals($checkedSourceSha256,(string)$audit['sha256'])){$error('SOURCE_CHANGED_AFTER_FILE_CHECK');return $report;}
        if($audit['relations']['status']!=='PASSED')$report['activationBlockers'][]='SOURCE_REFERENCES_TABLES_NOT_EXPORTED';
        $records=[];$rows=[];$bytes=0;$count=0;
        $again=(new TransferAudit())->run($file,function(string $table,array $columns,array $values)use(&$records,&$rows,&$bytes,&$count):void{
            if(++$count>100000)throw new RuntimeException('Full transfer row limit.');$row=[];
            foreach($columns as $i=>$column){$raw=$values[$i];$value=$raw==='NULL'?null:(str_starts_with($raw,'CONVERT(')?hex2bin(substr($raw,10,-16)):$raw);$row[$column['name']]=$value;}
            $payload=self::json($row);$bytes+=strlen($payload);if($bytes>67108864)throw new RuntimeException('Full transfer metadata limit.');
            $key=$this->key($table,$row);if(isset($records[$table][$key]))throw new RuntimeException('Duplicate archive key.');
            $records[$table][$key]=['payload'=>$payload,'row_hash'=>hash('sha256',$payload)];$rows[$table][]=$row;
        });
        if(!$again['profileMatches']||$again['sha256']!==$audit['sha256']){$error('SOURCE_CHANGED_DURING_READ');return $report;}
        foreach($this->profile['tables'] as $table=>$columns){$rows[$table]??=[];$records[$table]??=[];$report['tables'][$table]=['sourceRows'=>count($rows[$table]),'newRows'=>0,'matchingRows'=>0,'insertedRows'=>0];}
        $report['counts']['sourceRows']=$count;$report['reconciliation']=(new TransferReconciliation())->run($rows);$report['runtimePlan']=(new TransferRuntimePlan($this->db))->run();
        $report['activationBlockers']=array_values(array_unique([...$report['activationBlockers'],...$report['runtimePlan']['blockers']]));
        if($report['reconciliation']['errorCount']>0){$error('SOURCE_RECONCILIATION_FAILED');return $report;}
        foreach($report['reconciliation']['unchecked'] as $item)$report['activationBlockers'][]=$item;
        $calculated=hash('sha256','all-exported-tables-holding-v1:'.$this->profile['schemaSha256'].':'.$audit['sha256']);
        if($apply&&!hash_equals($fingerprint,$calculated)){$error('FINGERPRINT_MISMATCH');return $report;}
        $target=(string)$this->db->query('SELECT DATABASE()')->fetchColumn();
        foreach(['jingo_transfer_records','jingo_catalog_locks','jingo_audit'] as $table){$r=$this->query('SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$target,$table])->fetch(PDO::FETCH_ASSOC);if(!$r||$r['TABLE_TYPE']!=='BASE TABLE'||strtoupper($r['ENGINE']??'')!=='INNODB')$error('SCHEMA_MISMATCH',$table);}
        $columns=$this->query("SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_SET_NAME,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='jingo_transfer_records'",[$target])->fetchAll(PDO::FETCH_ASSOC);
        if(array_column($columns,'COLUMN_NAME')!==['table_name','row_key','row_hash','payload'])$error('SCHEMA_MISMATCH','jingo_transfer_records');
        foreach($columns as $c){$ok=$c['COLUMN_NAME']==='payload'?($c['DATA_TYPE']==='json'||$c['DATA_TYPE']==='longtext'&&$c['CHARACTER_SET_NAME']==='utf8mb4'):in_array($c['DATA_TYPE'],['char','varchar'],true)&&(int)$c['CHARACTER_MAXIMUM_LENGTH']>=64;if(!$ok||$c['IS_NULLABLE']!=='NO')$error('RUNTIME_CAPACITY_MISMATCH','jingo_transfer_records');}
        $pk=$this->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME='jingo_transfer_records' AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX",[$target])->fetchAll(PDO::FETCH_COLUMN);if($pk!==['table_name','row_key'])$error('RUNTIME_PRIMARY_KEY_MISMATCH');
        if($report['errorCount'])return $report;
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');if(!$apply)$this->db->exec('SET TRANSACTION READ ONLY');$this->db->beginTransaction();
        try{
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog'".($apply?' FOR UPDATE':''))->fetchColumn())throw new RuntimeException('Missing catalog lock.');
            $existing=[];$old=$this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);$q=null;$seen=0;
            try{
                $q=$this->query('SELECT * FROM jingo_transfer_records'.($apply?' FOR UPDATE':''));
                while($r=$q->fetch(PDO::FETCH_ASSOC)){
                    if(++$seen>100000)throw new RuntimeException('Full transfer target row limit.');$bytes+=strlen($r['payload']);if($bytes>134217728)throw new RuntimeException('Full transfer target metadata limit.');
                    $table=$r['table_name'];$key=$r['row_key'];$payload=json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR);
                    if(!is_array($payload)||!isset($this->profile['tables'][$table])){$error('UNKNOWN_OR_INVALID_HELD_RECORD');continue;}
                    $canonical=self::json($payload);
                    if(!hash_equals($r['row_hash'],hash('sha256',$canonical))||$this->key($table,$payload)!==$key){$error('HELD_INTEGRITY_MISMATCH',$table);continue;}
                    if(!isset($records[$table][$key])){$error('EXTRA_HELD_RECORD',$table);continue;}
                    if(!hash_equals($records[$table][$key]['row_hash'],$r['row_hash']))$error('EXISTING_HELD_DIFFERS',$table);
                    else{$report['tables'][$table]['matchingRows']++;$report['counts']['matchingRows']++;}$existing[$table][$key]=true;
                }
            }finally{if($q)$q->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$old);}
            foreach($records as $table=>$entries)foreach($entries as $key=>$r)if(!isset($existing[$table][$key])){$report['tables'][$table]['newRows']++;$report['counts']['newRows']++;}
            // All passes must refer to one immutable file, including the period spent waiting for DB locks.
            if(!is_file($file)||is_link($file)||hash_file('sha256',$file)!==$audit['sha256'])$error('SOURCE_CHANGED_BEFORE_COMMIT');
            $report['ready']=$report['errorCount']===0;$report['fingerprint']=$report['ready']?$calculated:null;
            if(!$apply||!$report['ready']){$this->db->rollBack();return $report;}
            $insert=$this->db->prepare('INSERT INTO jingo_transfer_records(table_name,row_key,row_hash,payload) VALUES (?,?,?,?)');
            foreach($records as $table=>$entries)foreach($entries as $key=>$r)if(!isset($existing[$table][$key])){$insert->execute([$table,$key,$r['row_hash'],$r['payload']]);$report['tables'][$table]['insertedRows']++;$report['counts']['insertedRows']++;}
            if($report['counts']['insertedRows'])$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),'cli-import','transfer.full-hold','transfer:'.$calculated]);
            $this->db->commit();$report['applied']=true;return $report;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
