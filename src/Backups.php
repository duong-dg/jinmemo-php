<?php
declare(strict_types=1);
final class Backups
{
    private const ID='/^jinmemo-backup-\d{8}T\d{6}Z-[a-f0-9]{8}$/D';
    private const PHASES=['backup','preflight','snapshot','encrypt-and-verify','discover','database-preflight','media-preflight','initial-media-copy','pause-writers','final-media-copy','database-dump','compose-snapshot','environment-snapshot','media-archive','source-revision','encryption','archive-verification','publish-backup'];
    public function __construct(private PDO $db,private string $directory){}
    private function root():?string
    {
        if(!preg_match('~^(?:[A-Za-z]:/|/)~',str_replace('\\','/',$this->directory))||is_link($this->directory))throw new HttpError(503,'BACKUP_DIR phải là thư mục riêng ngoài public.');$root=realpath($this->directory);if($root===false)return null;$public=realpath(dirname(__DIR__).'/public');$norm=static fn($p)=>strtolower(str_replace('\\','/',rtrim($p,'/\\')));if($public&&($norm($root)===$norm($public)||str_starts_with($norm($root),$norm($public).'/')))throw new HttpError(503,'BACKUP_DIR phải nằm ngoài public.');return $root;
    }
    private function open(string $name)
    {
        $root=$this->root();if(!$root||str_contains($name,'/')||str_contains($name,'\\'))throw new HttpError(404,'Không tìm thấy bản sao hoàn chỉnh.');$path=$root.DIRECTORY_SEPARATOR.$name;$before=@lstat($path);if(!$before||($before['mode']&0170000)!==0100000||is_link($path)||realpath($path)!==$path)throw new HttpError(404,'Không tìm thấy bản sao hoàn chỉnh.');$file=@fopen($path,'rb');if(!$file)throw new HttpError(404,'Không tìm thấy bản sao hoàn chỉnh.');$stat=fstat($file);clearstatcache(true,$path);$after=@lstat($path);if(!$stat||!$after||($after['mode']&0170000)!==0100000||$stat['ino']!==$before['ino']||$stat['dev']!==$before['dev']||$stat['ino']!==$after['ino']||realpath($path)!==$path){fclose($file);throw new HttpError(404,'Không tìm thấy bản sao hoàn chỉnh.');}return $file;
    }
    private function json(string $name):array{$f=$this->open($name);try{if(fstat($f)['size']>65536)throw new RuntimeException('Invalid metadata');$raw=stream_get_contents($f,65537);if(strlen($raw)>65536)throw new RuntimeException('Invalid metadata');$value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($value)||!str_starts_with(ltrim($raw),'{'))throw new RuntimeException('Invalid metadata');return $value;}finally{fclose($f);}}
    private static function date(mixed $v):bool{return is_string($v)&&preg_match('/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?Z$/D',$v,$m)&&checkdate((int)$m[2],(int)$m[3],(int)$m[1]);}
    private static function count(mixed $v,bool $positive=false):bool{return is_int($v)&&$v>=($positive?1:0)&&$v<=9007199254740991;}
    public function record(string $id):array
    {
        if(!preg_match(self::ID,$id))throw new HttpError(404,'Không tìm thấy bản sao hoàn chỉnh.');try{$v=$this->json($id.'.json');if(($v['id']??null)!==$id||!self::date($v['createdAt']??null)||!self::date($v['verifiedAt']??null)||!self::count($v['bytes']??null,true)||!is_string($v['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$v['sha256'])||!is_string($v['revision']??null)||!preg_match('/^[a-f0-9]{40,64}$/D',$v['revision']))throw new RuntimeException('Invalid metadata');$snapshot=[];foreach(['orders','carts','products','payments','ticketFiles'] as $key){if(!self::count($v['snapshot'][$key]??null))throw new RuntimeException('Invalid counts');$snapshot[$key]=$v['snapshot'][$key];}$f=$this->open($id.'.tar.gpg');try{if(fstat($f)['size']!==$v['bytes'])throw new RuntimeException('Incomplete archive');}finally{fclose($f);}$out=array_intersect_key($v,array_flip(['id','createdAt','verifiedAt','bytes','sha256','revision']));$out['snapshot']=$snapshot;if(($v['format']??null)==='jinmemo-php-database-backup-v1'&&($v['scope']??null)==='PHP_DATABASE_ONLY')$out['scope']='PHP_DATABASE_ONLY';if(($v['format']??null)==='jinmemo-php-application-backup-v1'&&($v['scope']??null)==='PHP_APPLICATION')$out['scope']='PHP_APPLICATION';try{$test=$this->json($id.'.restore-test.json');if(($test['id']??null)===$id&&($test['sha256']??null)===$out['sha256']&&self::date($test['testedAt']??null))$out['restoreTestedAt']=$test['testedAt'];}catch(Throwable){}return $out;}catch(Throwable){throw new HttpError(404,'Không tìm thấy bản sao hoàn chỉnh.');}
    }
    public function list():array
    {
        $root=$this->root();$names=$root?@scandir($root):false;if($names===false)return ['configured'=>false,'data'=>[],'setup'=>null,'lastRun'=>null];$names=array_filter($names,fn($n)=>str_ends_with($n,'.json')&&preg_match(self::ID,substr($n,0,-5)));rsort($names,SORT_STRING);$data=[];foreach(array_slice($names,0,90) as $name)try{$data[]=$this->record(substr($name,0,-5));}catch(HttpError){}$setup=$last=null;try{$v=$this->json('setup.json');if(($v['schedule']??null)==='03:15 Asia/Ho_Chi_Minh'&&is_int($v['keep']??null)&&$v['keep']>=1&&$v['keep']<=90&&self::date($v['installedAt']??null))$setup=array_intersect_key($v,array_flip(['schedule','keep','installedAt']));}catch(Throwable){}try{$v=$this->json('last-run.json');if(in_array($v['state']??null,['RUNNING','SUCCESS','FAILED'],true)&&self::date($v['updatedAt']??null)&&(!isset($v['startedAt'])||self::date($v['startedAt']))&&(!isset($v['phase'])||in_array($v['phase'],self::PHASES,true)))$last=array_intersect_key($v,array_flip(['state','updatedAt','startedAt','phase']));}catch(Throwable){}return ['configured'=>$setup!==null,'data'=>$data,'setup'=>$setup,'lastRun'=>$last];
    }
    public function archive(string $id,string $actor)
    {
        $record=$this->record($id);$f=$this->open($id.'.tar.gpg');try{$hash=hash_init('sha256');hash_update_stream($hash,$f);if(fstat($f)['size']!==$record['bytes']||!hash_equals($record['sha256'],hash_final($hash)))throw new HttpError(409,'Bản sao không khớp SHA-256; chưa tải file.');rewind($f);$q=$this->db->prepare('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)');$q->execute([bin2hex(random_bytes(16)),$actor,'backup.download',$id]);return $f;}catch(Throwable $e){fclose($f);throw $e;}
    }
}
