<?php
declare(strict_types=1);

/** Imports only blog data from the Node export's separate staging database. */
final class PostTransfer
{
    private array $errors=[];
    private array $warnings=[];
    private int $bytes=0;
    private const LIMIT_BYTES=16777216;
    private const LIMIT_ROWS=50000;
    public function __construct(private PDO $db,private string $mediaDirectory){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private static function identifier(string $name):string{if(!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_$-]{0,63}$/D',$name))throw new InvalidArgumentException('Tên database không hợp lệ.');return '`'.$name.'`';}
    private function error(string $scope,string $message):void{if(count($this->errors)<100)$this->errors[]=['scope'=>$scope,'message'=>$message];}
    private static function uuid(mixed $v):bool{return is_string($v)&&(bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$v);}
    private static function slug(mixed $v):bool{return is_string($v)&&strlen($v)<=160&&(bool)preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$v);}
    private static function integer(mixed $v,string $max='2147483646',bool $zero=false):bool{$s=(string)$v;return (is_int($v)||is_string($v))&&(bool)preg_match($zero?'/^(?:0|[1-9][0-9]*)$/D':'/^[1-9][0-9]*$/D',$s)&&(strlen($s)<strlen($max)||strlen($s)===strlen($max)&&strcmp($s,$max)<=0);}
    private static function text(mixed $v,int $max,bool $empty=true):bool{return is_string($v)&&($empty||$v!=='')&&mb_check_encoding($v,'UTF-8')&&mb_strlen($v,'UTF-8')<=$max;}
    private static function timestamp(mixed $v):bool
    {
        if(!is_string($v)||!preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?$/D',$v))return false;$normalized=str_contains($v,'.')?str_pad($v,26,'0'):$v.'.000000';$date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$normalized,new DateTimeZone('UTC'));return $date!==false&&$date->format('Y-m-d H:i:s.u')===$normalized;
    }
    private function schema(string $database):array{return $this->query("SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=?",[$database])->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);}
    private function columns(string $database,string $table):array{return $this->query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',[$database,$table])->fetchAll(PDO::FETCH_COLUMN);}
    private function targetTypes(string $database,string $table):void
    {
        $rows=$this->query('SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_SET_NAME,DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$database,'jingo_'.$table])->fetchAll(PDO::FETCH_ASSOC);
        $sizes=['id'=>36,'post_id'=>36,'slug'=>160,'kind'=>20,'entity_id'=>80,'name'=>100,'english_name'=>100,'saved_by'=>80,'reason'=>80];
        foreach($rows as $column){$name=$column['COLUMN_NAME'];$type=$column['DATA_TYPE'];if(in_array($name,['created_at','updated_at'],true)){if($type!=='datetime'||(int)$column['DATETIME_PRECISION']!==6)$this->error('jingo_'.$table.'.'.$name,'Thời gian đích cần DATETIME(6) để giữ microsecond.');}
            elseif($name==='views'){if($type!=='bigint')$this->error('jingo_'.$table.'.views','Lượt xem đích cần BIGINT, không dùng FLOAT/DOUBLE.');}
            elseif($name==='version'){if(!in_array($type,['int','bigint'],true))$this->error('jingo_'.$table.'.version','Kiểu version đích không phù hợp.');}
            elseif($name==='data'){if(!in_array($type,['json','longtext'],true)||$type!=='json'&&$column['CHARACTER_SET_NAME']!=='utf8mb4')$this->error('jingo_'.$table.'.data','JSON đích cần JSON hoặc LONGTEXT utf8mb4.');}
            elseif(isset($sizes[$name])){if(!in_array($type,['char','varchar','text','mediumtext','longtext'],true)||(int)$column['CHARACTER_MAXIMUM_LENGTH']<$sizes[$name])$this->error('jingo_'.$table.'.'.$name,'Độ dài hoặc kiểu chuỗi đích ngắn hơn contract.');if(in_array($name,['name','english_name','saved_by','reason'],true)&&$column['CHARACTER_SET_NAME']!=='utf8mb4')$this->error('jingo_'.$table.'.'.$name,'Trường nội dung đích cần utf8mb4.');}}
    }
    private function rows(string $database,string $table,string $where=''):array
    {
        $buffered=$this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);$statement=null;$rows=[];
        try{$statement=$this->query('SELECT * FROM '.self::identifier($database).'.'.self::identifier($table).$where);while($row=$statement->fetch(PDO::FETCH_ASSOC)){$this->bytes+=strlen(json_encode($row,JSON_THROW_ON_ERROR));if(count($rows)>=self::LIMIT_ROWS||$this->bytes>self::LIMIT_BYTES)throw new RuntimeException('Dữ liệu vượt giới hạn 16 MB hoặc 50.000 dòng mỗi bảng của đợt nhập này.');$rows[]=$row;}}finally{if($statement)$statement->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);}return $rows;
    }
    private function json(array $row,string $scope):?array
    {
        try{$object=json_decode($row['data'] ?? '',false,64,JSON_THROW_ON_ERROR);if(!$object instanceof stdClass)throw new RuntimeException('JSON phải là object.');$data=json_decode($row['data'],true,64,JSON_THROW_ON_ERROR);Posts::validate($data);return $data;}catch(Throwable){$this->error($scope,'JSON bài viết không phù hợp schema PHP; không tự bỏ trường hoặc sửa nội dung để ép nhập.');return null;}
    }
    private function references(array $data,string $scope,bool $historical=false):void
    {
        $media=ContentHtml::imageIds(ContentHtml::clean($data['contentHtml'] ?? ''));if(isset($data['english']))$media=array_merge($media,ContentHtml::imageIds(ContentHtml::clean($data['english']['contentHtml'] ?? '')));if(isset($data['cover']['id']))$media[]=$data['cover']['id'];
        foreach(array_unique($media) as $id){$row=self::uuid($id)?$this->query('SELECT bytes,width,height FROM jingo_media WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC):false;$path=self::uuid($id)?rtrim($this->mediaDirectory,'/\\').'/'.$id.'.webp':'';$ok=$row&&is_file($path)&&filesize($path)===(int)$row['bytes'];if($ok){$image=@getimagesize($path);$ok=$image&&($image['mime'] ?? '')==='image/webp'&&$image[0]===(int)$row['width']&&$image[1]===(int)$row['height'];}if(!$ok){if($historical)$this->warnings['historicalMediaUnresolved']=true;else $this->error($scope,'Ảnh tham chiếu chưa có metadata/file WebP phù hợp ở PHP. Cần chuyển media trước.');}}
        foreach(array_unique($data['relatedProductIds'] ?? []) as $id)if(!$this->query('SELECT id FROM jingo_products WHERE id=?',[$id])->fetchColumn()){if($historical)$this->warnings['historicalProductsUnresolved']=true;else $this->error($scope,'Sản phẩm liên quan chưa có ở PHP. Cần chuyển sản phẩm trước.');}
        if(!empty($data['relatedRideIds']))$this->warnings['rideLinksPreservedWithoutRuntime']=true;
    }
    public function run(string $sourceDatabase,bool $apply=false):array
    {
        self::identifier($sourceDatabase);$target=(string)$this->db->query('SELECT DATABASE()')->fetchColumn();self::identifier($target);if(strcasecmp($sourceDatabase,$target)===0)throw new InvalidArgumentException('Database staging phải khác database PHP đích.');$this->errors=[];$this->warnings=[];$this->bytes=0;
        $contract=json_decode(file_get_contents(dirname(__DIR__).'/docs/TRANSFER-CONTRACT.json'),true,512,JSON_THROW_ON_ERROR);$tables=$contract['postTables'];$tables['slug_aliases']=['kind','slug','entity_id'];$rows=[];$counts=[];
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');if(!$apply)$this->db->exec('SET TRANSACTION READ ONLY');$this->db->beginTransaction();try{
            $sourceSchema=$this->schema($sourceDatabase);$targetSchema=$this->schema($target);$notImported=[];foreach(array_keys($sourceSchema) as $name)if(str_starts_with($name,'jm_transfer_')&&!array_key_exists(substr($name,12),$tables))$notImported[]=$name;sort($notImported);
            foreach(['jingo_catalog_locks','jingo_audit'] as $required)if(!isset($targetSchema[$required])||$targetSchema[$required]['TABLE_TYPE']!=='BASE TABLE'||strtoupper($targetSchema[$required]['ENGINE'] ?? '')!=='INNODB')$this->error($required,'Thiếu bảng khóa/audit InnoDB; chạy php bin/install.php.');
            if(!$this->errors&&!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog'".($apply?' FOR UPDATE':''))->fetchColumn())$this->error('jingo_catalog_locks','Chưa cài khóa catalog; chạy php bin/install.php.');
            foreach($tables as $table=>$columns){foreach([[$sourceDatabase,$sourceSchema,'jm_transfer_'],[$target,$targetSchema,'jingo_']] as [$database,$schema,$prefix]){$name=$prefix.$table;if(!isset($schema[$name])||$schema[$name]['TABLE_TYPE']!=='BASE TABLE'||strtoupper($schema[$name]['ENGINE'] ?? '')!=='INNODB'){$this->error($prefix.$table,'Thiếu bảng InnoDB; kiểm tra import staging và php bin/install.php.');continue;}$actual=$this->columns($database,$name);sort($actual);$expected=$columns;sort($expected);if($actual!==$expected)$this->error($name,'Danh sách cột khác contract export; không tự bỏ cột.');if($prefix==='jingo_')$this->targetTypes($target,$table);}}
            $report=['scope'=>'posts-only','mode'=>$apply?'apply':'dry-run','sourceDatabase'=>$sourceDatabase,'targetDatabase'=>$target,'ready'=>false,'applied'=>false,'counts'=>[],'fingerprint'=>null,'errors'=>[],'warnings'=>[],'notImportedTables'=>$notImported];
            if($this->errors){$report['errors']=$this->errors;$this->db->rollBack();return $report;}
            foreach($tables as $table=>$columns){$where=$table==='slug_aliases'?" WHERE kind IN ('POST','POST_EN')":'';// Current read after the catalog lock prevents stale empty-target checks across waiting import workers.
                $occupied=$apply?$this->query('SELECT 1 FROM jingo_'.$table.$where.' LIMIT 1 FOR UPDATE')->fetchColumn()!==false:(int)$this->query('SELECT COUNT(*) FROM jingo_'.$table.$where)->fetchColumn()>0;if($occupied)$this->error('jingo_'.$table,'Bảng blog đích đã có dữ liệu; công cụ không ghi đè hoặc trộn dữ liệu.');$rows[$table]=$this->rows($sourceDatabase,'jm_transfer_'.$table,$where);$counts[$table]=count($rows[$table]);}
            $posts=[];$slugs=['POST'=>[],'POST_EN'=>[]];foreach($rows['posts'] as $index=>$row){$scope='posts row '.($index+1);$id=$row['id'] ?? null;if(!self::uuid($id)||!self::slug($row['slug'])||!self::integer($row['version'])||!self::timestamp($row['created_at'])||!self::timestamp($row['updated_at'])){$this->error($scope,'ID/slug/version/thời gian không phù hợp schema đích.');continue;}$key=strtolower($id);if(isset($posts[$key]))$this->error($scope,'Trùng ID nguồn.');$data=$this->json($row,$scope);if(!$data)continue;if(($data['id'] ?? null)!==$id||($data['slug'] ?? null)!==$row['slug'])$this->error($scope,'ID hoặc slug trong JSON khác cột nguồn.');$posts[$key]=['version'=>(int)$row['version'],'data'=>$data];foreach(['POST'=>$row['slug'],'POST_EN'=>($data['english']['slug'] ?? '') ?: $row['slug']] as $kind=>$slug){if(!self::slug($slug)){$this->error($scope,'Slug ngôn ngữ không hợp lệ.');continue;}if(isset($slugs[$kind][$slug])&&$slugs[$kind][$slug]!==$key)$this->error($scope,'Trùng slug ngôn ngữ trong nguồn.');$slugs[$kind][$slug]=$key;}$this->references($data,$scope);}
            $seen=[];foreach($rows['post_categories'] as $index=>$row){$scope='post_categories row '.($index+1);if(!self::text($row['name'],100,false)||($row['english_name']!==null&&!self::text($row['english_name'],100))||!self::integer($row['version']))$this->error($scope,'Tên, bản dịch hoặc version chuyên mục không hợp lệ.');$key=(string)$row['name'];if(isset($seen[$key]))$this->error($scope,'Trùng chuyên mục nguồn.');$seen[$key]=true;}
            $seen=[];foreach($rows['post_revisions'] as $index=>$row){$scope='post_revisions row '.($index+1);$key=strtolower((string)$row['post_id']);if(!isset($posts[$key])||!self::integer($row['version'])||(isset($posts[$key])&&(int)$row['version']>$posts[$key]['version'])||!self::text($row['saved_by'],80,false)||!self::text($row['reason'],80,false)||!self::timestamp($row['created_at']))$this->error($scope,'Thiếu bài cha hoặc version/người lưu/lý do/thời gian không hợp lệ.');$identity=$key.':'.$row['version'];if(isset($seen[$identity]))$this->error($scope,'Trùng phiên bản nguồn.');$seen[$identity]=true;$data=$this->json($row,$scope);if($data){if(strtolower((string)($data['id'] ?? ''))!==$key)$this->error($scope,'Revision JSON không thuộc bài cha.');$this->references($data,$scope,true);}}
            $seen=[];foreach($rows['post_metrics'] as $index=>$row){$scope='post_metrics row '.($index+1);$key=strtolower((string)$row['post_id']);if(!isset($posts[$key])||!self::integer($row['views'],'9223372036854775807',true))$this->error($scope,'Thiếu bài cha hoặc lượt xem không hợp lệ.');if(isset($seen[$key]))$this->error($scope,'Trùng bộ đếm nguồn.');$seen[$key]=true;}
            $seen=[];foreach($rows['slug_aliases'] as $index=>$row){$scope='slug_aliases row '.($index+1);$key=strtolower((string)$row['entity_id']);$kind=$row['kind'];$identity=$kind.':'.$row['slug'];if(!in_array($kind,['POST','POST_EN'],true)||!isset($posts[$key])||!self::slug($row['slug']))$this->error($scope,'Alias không hợp lệ hoặc thiếu bài cha.');if(isset($seen[$identity])||isset($slugs[$kind][$row['slug']])&&$slugs[$kind][$row['slug']]!==$key)$this->error($scope,'Alias trùng hoặc chiếm slug của bài khác.');$seen[$identity]=true;}
            $hash=hash_init('sha256');foreach($rows as $table=>$items){$encoded=[];foreach($items as $row){ksort($row);foreach($row as $key=>$value)if($value!==null)$row[$key]=(string)$value;$encoded[]=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}sort($encoded,SORT_STRING);hash_update($hash,$table."\n");foreach($encoded as $line)hash_update($hash,$line."\n");}
            $report['counts']=$counts;$report['fingerprint']=hash_final($hash);$report['errors']=$this->errors;$report['warnings']=array_keys($this->warnings);$report['ready']=!$this->errors;
            if(!$apply||!$report['ready']){$this->db->rollBack();return $report;}
            foreach($tables as $table=>$columns){$quoted=implode(',',array_map(fn($column)=>'`'.$column.'`',$columns));$insert=$this->db->prepare('INSERT INTO jingo_'.$table.' ('.$quoted.') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');foreach($rows[$table] as $row)$insert->execute(array_map(fn($column)=>$row[$column],$columns));}
            $this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),'cli-import','post.import','posts-transfer:'.$report['fingerprint']]);$this->db->commit();$report['applied']=true;return $report;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
