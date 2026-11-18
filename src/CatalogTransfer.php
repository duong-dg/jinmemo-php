<?php
declare(strict_types=1);
foreach(['AdminAuth','ProductTypes','Destinations','Suppliers','Counters','Media'] as $dependency)require_once __DIR__.'/'.$dependency.'.php';

/** Staging -> runtime metadata only. Does not call save(), reprice or change source IDs. */
final class CatalogTransfer
{
    private const TABLES=['product_types'=>ProductTypes::class,'destinations'=>Destinations::class,'suppliers'=>Suppliers::class,'counters'=>Counters::class];
    private array $errors=[];
    private int $bytes=0;
    public function __construct(private PDO $db,private string $mediaDirectory){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private static function identifier(string $v):string{if(!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_$-]{0,63}$/D',$v))throw new InvalidArgumentException('Tên database không hợp lệ.');return '`'.$v.'`';}
    private function error(string $table,int $row,string $code):void{if(count($this->errors)<100)$this->errors[]=['table'=>$table,'row'=>$row,'code'=>$code];}
    private static function stable(mixed $value):mixed
    {
        if($value instanceof stdClass){$out=get_object_vars($value);ksort($out,SORT_STRING);foreach($out as &$v)$v=self::stable($v);unset($v);return (object)$out;}
        if(is_array($value))return array_map([self::class,'stable'],$value);return $value;
    }
    private static function json(mixed $value):string{return json_encode(self::stable($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function canonical(array $row):string
    {
        if(isset($row['data']))$row['data']=self::stable(json_decode($row['data'],false,512,JSON_THROW_ON_ERROR));
        if(isset($row['version']))$row['version']=(string)$row['version'];ksort($row,SORT_STRING);return self::json($row);
    }
    private function rows(string $database,string $table,bool $lock=false):array
    {
        $old=$this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);$s=null;$rows=[];
        try{$s=$this->query('SELECT * FROM '.self::identifier($database).'.'.self::identifier($table).($lock?' FOR UPDATE':''));while($r=$s->fetch(PDO::FETCH_ASSOC)){$this->bytes+=strlen(json_encode($r,JSON_THROW_ON_ERROR));if(count($rows)>=50000||$this->bytes>16777216)throw new RuntimeException('Catalog metadata limit.');$rows[]=$r;}}finally{if($s)$s->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$old);}return $rows;
    }
    private function schema(string $database,string $table,array $columns):bool
    {
        $r=$this->query('SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$database,$table])->fetch(PDO::FETCH_ASSOC);
        $actual=$this->query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$database,$table])->fetchAll(PDO::FETCH_COLUMN);sort($actual);sort($columns);
        if(!$r||$r['TABLE_TYPE']!=='BASE TABLE'||strtoupper($r['ENGINE']??'')!=='INNODB'||$actual!==$columns){$this->error($table,0,'SCHEMA_MISMATCH');return false;}return true;
    }
    private function record(string $table,array $row,int $index):?array
    {
        try{
            if(!is_string($row['id'])||!is_string($row['data'])||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$row['version'])||(int)$row['version']>2147483646)throw new RuntimeException();
            $data=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($data)||array_is_list($data)||($data['id']??null)!==$row['id']||isset($row['slug'])&&($data['slug']??null)!==$row['slug'])throw new RuntimeException();
            $body=$data;$body['version']=(int)$row['version'];$class=self::TABLES[$table];$class::validate($body);
            return $body;
        }catch(Throwable){$this->error($table,$index,'INVALID_RECORD');return null;}
    }
    public function run(string $source,bool $apply=false,?string $expectedFingerprint=null):array
    {
        self::identifier($source);$target=(string)$this->db->query('SELECT DATABASE()')->fetchColumn();self::identifier($target);
        if(strcasecmp($source,$target)===0)throw new InvalidArgumentException('Staging phải khác database PHP.');
        if($apply&&(!is_string($expectedFingerprint)||!preg_match('/^[a-f0-9]{64}$/D',$expectedFingerprint)))throw new InvalidArgumentException('Cần --fingerprint từ dry-run trước --apply.');
        $this->errors=[];$this->bytes=0;$report=['scope'=>'catalog-foundations-only','sourceDatabase'=>$source,'targetDatabase'=>$target,'mode'=>$apply?'apply':'dry-run','ready'=>false,'applied'=>false,'fingerprint'=>null,'counts'=>[],'errors'=>[],'notImportedTables'=>[],'notImportedAliasRows'=>0];
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');if(!$apply)$this->db->exec('SET TRANSACTION READ ONLY');$this->db->beginTransaction();
        try{
            foreach(['jingo_catalog_locks','jingo_audit','jingo_slug_aliases','jingo_media'] as $table){$r=$this->query('SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$target,$table])->fetch(PDO::FETCH_ASSOC);if(!$r||$r['TABLE_TYPE']!=='BASE TABLE'||strtoupper($r['ENGINE']??'')!=='INNODB')$this->error($table,0,'MISSING_RUNTIME_TABLE');}
            $sourceTables=$this->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND LEFT(TABLE_NAME,12)='jm_transfer_'",[$source])->fetchAll(PDO::FETCH_COLUMN);
            $covered=array_merge(array_map(static fn($t)=>'jm_transfer_'.$t,array_keys(self::TABLES)),['jm_transfer_slug_aliases']);$report['notImportedTables']=array_values(array_diff($sourceTables,$covered));sort($report['notImportedTables']);
            $columns=[];foreach(self::TABLES as $table=>$class){$columns[$table]=in_array($table,['product_types','destinations'],true)?['id','slug','data','version']:['id','data','version'];$this->schema($source,'jm_transfer_'.$table,$columns[$table]);$this->schema($target,'jingo_'.$table,$columns[$table]);}
            $this->schema($source,'jm_transfer_slug_aliases',['kind','slug','entity_id']);$this->schema($target,'jingo_slug_aliases',['kind','slug','entity_id']);
            foreach($this->query('SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$target,'jingo_slug_aliases'])->fetchAll(PDO::FETCH_ASSOC) as $type){$minimum=['kind'=>20,'slug'=>160,'entity_id'=>80][$type['COLUMN_NAME']]??PHP_INT_MAX;if(!in_array($type['DATA_TYPE'],['char','varchar'],true)||(int)$type['CHARACTER_MAXIMUM_LENGTH']<$minimum)$this->error('jingo_slug_aliases',0,'RUNTIME_CAPACITY_MISMATCH');}
            // Require known runtime capacities before touching data; no coercion/truncation allowed.
            foreach(self::TABLES as $table=>$class){$types=$this->query('SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$target,'jingo_'.$table])->fetchAll(PDO::FETCH_ASSOC);foreach($types as $t){$name=$t['COLUMN_NAME'];if($name==='version'){$ok=$t['DATA_TYPE']==='int';}elseif($name==='data'){$ok=in_array($t['DATA_TYPE'],['json','longtext'],true)&&($t['DATA_TYPE']==='json'||$t['CHARACTER_SET_NAME']==='utf8mb4');}else{$min=$name==='slug'?160:(in_array($table,['product_types','counters'],true)?80:36);$ok=in_array($t['DATA_TYPE'],['char','varchar'],true)&&(int)$t['CHARACTER_MAXIMUM_LENGTH']>=$min;}if(!$ok)$this->error('jingo_'.$table,0,'RUNTIME_CAPACITY_MISMATCH');}}
            if($this->errors){$report['errors']=$this->errors;$this->db->rollBack();return $report;}
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog'".($apply?' FOR UPDATE':''))->fetchColumn())throw new RuntimeException('Missing catalog lock.');
            $all=[];$pending=[];$fingerprints=[];
            foreach(self::TABLES as $table=>$class){$existing=[];$bodies=[];$caseIds=[];$targetRows=$this->rows($target,'jingo_'.$table,$apply);foreach($targetRows as $i=>$row){$body=$this->record($table,$row,$i+1);if($body!==null)$bodies[$row['id']]=$body;$existing[$row['id']]=$row;$caseIds[strtolower($row['id'])]=$row['id'];}
                $sourceRows=$this->rows($source,'jm_transfer_'.$table);$report['counts'][$table]=['sourceRows'=>count($sourceRows),'newRows'=>0,'matchingRows'=>0,'insertedRows'=>0];$seen=[];
                foreach($sourceRows as $i=>$row){$body=$this->record($table,$row,$i+1);if($body===null)continue;$id=$row['id'];$key=strtolower($id);if(isset($seen[$key])){$this->error($table,$i+1,'DUPLICATE_ID');continue;}$seen[$key]=true;
                    if(isset($caseIds[$key])&&$caseIds[$key]!==$id){$this->error($table,$i+1,'ID_CASE_COLLISION');continue;}
                    $canonical=self::canonical($row);$fingerprints[]=$table.':'.$canonical;
                    if(isset($existing[$id])){if(self::canonical($existing[$id])!==$canonical)$this->error($table,$i+1,'EXISTING_METADATA_DIFFERS');else $report['counts'][$table]['matchingRows']++;}else{$pending[$table][]=$row;$report['counts'][$table]['newRows']++;}
                    $bodies[$id]=$body;
                }$all[$table]=$bodies;
            }
            // Route collisions include both languages and old aliases, without renaming either side.
            $owners=[];foreach(['product_types','destinations'] as $table)foreach($all[$table] as $id=>$body){$slugs=$table==='product_types'?['TYPE'=>$body['slug']]:['DESTINATION'=>$body['slug'],'DESTINATION_EN'=>($body['englishSlug']??'')?:$body['slug']];foreach($slugs as $kind=>$slug){$scope=$table==='destinations'?'DESTINATION_ANY':$kind;if(isset($owners[$scope][$slug])&&$owners[$scope][$slug]!==$id)$this->error($table,0,'SLUG_COLLISION');$owners[$scope][$slug]=$id;$owners[$kind][$slug]=$id;}}
            foreach($all['destinations'] as $body){$seen=[];$node=$body;while($node!==null){if(isset($seen[$node['id']])||count($seen)>=10){$this->error('destinations',0,'INVALID_TREE');break;}$seen[$node['id']]=true;$parent=$node['parentId'];if($parent===null)break;if(!isset($all['destinations'][$parent])){$this->error('destinations',0,'MISSING_PARENT');break;}$next=$all['destinations'][$parent];if($node['status']==='ACTIVE'&&$next['status']!=='ACTIVE')$this->error('destinations',0,'INACTIVE_PARENT');$node=$next;}}
            $files=[];$coverBytes=0;foreach($all['destinations'] as $body)if(isset($body['cover']['id'])){$id=$body['cover']['id'];if(isset($files[$id]))continue;try{$media=new Media($this->db,$this->mediaDirectory);$media->validateIds([$id]);$path=$media->file($id);$r=$this->query('SELECT bytes,width,height FROM jingo_media WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);$info=is_link($path)?false:@getimagesize($path);if(!$info||$info[2]!==IMAGETYPE_WEBP||filesize($path)!==(int)$r['bytes']||$info[0]!=(int)$r['width']||$info[1]!=(int)$r['height']||(int)$r['bytes']>10485760)throw new RuntimeException();$coverBytes+=(int)$r['bytes'];if($coverBytes>2147483648)throw new RuntimeException();$hash=hash_file('sha256',$path);if($hash===false)throw new RuntimeException();$files[$id]=['path'=>$path,'hash'=>$hash];$fingerprints[]='cover:'.$id.':'.$hash;}catch(Throwable){$this->error('destinations',0,'INVALID_COVER_FILE_OR_LIMIT');}}
            $aliases=[];$sourceAliases=$this->rows($source,'jm_transfer_slug_aliases');$targetAliases=$this->rows($target,'jingo_slug_aliases',$apply);$aliasSeen=[];$report['counts']['slug_aliases']=['sourceRows'=>count($sourceAliases),'selectedRows'=>0,'newRows'=>0,'matchingRows'=>0,'insertedRows'=>0];
            foreach([false=>$targetAliases,true=>$sourceAliases] as $fromSource=>$rows)foreach($rows as $i=>$row){$kind=$row['kind'];if(!in_array($kind,['TYPE','DESTINATION','DESTINATION_EN'],true)){if($fromSource)$report['notImportedAliasRows']++;continue;}$table=$kind==='TYPE'?'product_types':'destinations';$slug=$row['slug'];$id=$row['entity_id'];
                if(!is_string($slug)||strlen($slug)>160||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug)||!is_string($id)||!isset($all[$table][$id])){$this->error('slug_aliases',$i+1,'INVALID_ALIAS');continue;}
                $key=$kind.':'.$slug;if(isset($owners[$kind][$slug])&&$owners[$kind][$slug]!==$id)$this->error('slug_aliases',$i+1,'SLUG_COLLISION');$owners[$kind][$slug]=$id;
                if($fromSource){$report['counts']['slug_aliases']['selectedRows']++;if(isset($aliasSeen[$key]))$this->error('slug_aliases',$i+1,'DUPLICATE_ALIAS');$aliasSeen[$key]=true;$fingerprints[]='alias:'.self::canonical($row);if(isset($aliases[$key])){if($aliases[$key]['entity_id']!==$id)$this->error('slug_aliases',$i+1,'ALIAS_OWNER_DIFFERS');else $report['counts']['slug_aliases']['matchingRows']++;}else{$pending['slug_aliases'][]=$row;$report['counts']['slug_aliases']['newRows']++;}}$aliases[$key]=$row;
            }
            sort($fingerprints,SORT_STRING);$report['fingerprint']=hash('sha256',implode("\n",$fingerprints));if($apply&&!hash_equals($expectedFingerprint,$report['fingerprint']))$this->error('catalog',0,'FINGERPRINT_MISMATCH');
            $report['errors']=$this->errors;$report['ready']=!$this->errors;if(!$apply||!$report['ready']){$this->db->rollBack();return $report;}
            foreach($files as $file)if(!is_file($file['path'])||is_link($file['path'])||!hash_equals($file['hash'],(string)hash_file('sha256',$file['path'])))throw new RuntimeException('Cover changed during import.');
            foreach($pending as $table=>$rows){$cols=$table==='slug_aliases'?['kind','slug','entity_id']:$columns[$table];$insert=$this->db->prepare('INSERT INTO jingo_'.$table.' (`'.implode('`,`',$cols).'`) VALUES ('.implode(',',array_fill(0,count($cols),'?')).')');foreach($rows as $row)$insert->execute(array_map(static fn($col)=>$row[$col],$cols));$report['counts'][$table]['insertedRows']=count($rows);}
            if($pending)$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),'cli-import','catalog.import','catalog:'.$report['fingerprint']]);
            $this->db->commit();$report['applied']=true;return $report;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
