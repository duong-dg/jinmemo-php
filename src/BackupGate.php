<?php
declare(strict_types=1);
/** Opt-in request/worker drain for a short application snapshot window. */
final class BackupGate
{
    private static array $shared=[];
    private function __construct(private $file,private string $directory){}
    private static function unavailable():never{if(PHP_SAPI!=='cli')header('Retry-After: 30');if(class_exists('HttpError'))throw new HttpError(503,'Hệ thống đang chụp bản sao lưu. Vui lòng thử lại sau.');throw new RuntimeException('Backup coordination is unavailable or busy.');}
    private static function directory(string $path):string
    {
        $normal=str_replace('\\','/',$path);if(!preg_match('~^(?:[A-Za-z]:/|/)~',$normal))self::unavailable();$r=realpath($path);$public=realpath(dirname(__DIR__).'/public');$norm=fn($v)=>strtolower(str_replace('\\','/',rtrim($v,'/\\')));
        if(!$r||!is_dir($r)||is_link($path)||$public&&($norm($r)===$norm($public)||str_starts_with($norm($r),$norm($public).'/'))||PHP_OS_FAMILY!=='Windows'&&(fileperms($r)&0077)!==0)self::unavailable();return $r;
    }
    private static function open(string $dir)
    {
        $path=$dir.'/.application-snapshot.lock';if(is_link($path))self::unavailable();$f=@fopen($path,'c+b');if(!$f)self::unavailable();$s=fstat($f);if(!$s||($s['mode']&0170000)!==0100000){fclose($f);self::unavailable();}@chmod($path,0600);return $f;
    }
    public static function shared(string $path):void
    {
        $dir=self::directory($path);if(isset(self::$shared[$dir]))return;$f=self::open($dir);if(!flock($f,LOCK_SH|LOCK_NB)){fclose($f);self::unavailable();}self::$shared[$dir]=$f;
    }
    public static function exclusive(string $path,int $seconds=30):self
    {
        if(PHP_SAPI!=='cli'||$seconds<0||$seconds>60)throw new LogicException('Exclusive snapshot gate is CLI only.');$dir=self::directory($path);if(isset(self::$shared[$dir]))throw new LogicException('Acquire snapshot gate before opening an application connection.');$f=self::open($dir);$end=microtime(true)+$seconds;
        do{if(flock($f,LOCK_EX|LOCK_NB))return new self($f,$dir);if(microtime(true)>=$end)break;usleep(100000);}while(true);fclose($f);self::unavailable();
    }
    public function owns(string $path):bool{return preg_match('~^(?:[A-Za-z]:/|/)~',str_replace('\\','/',$path))===1&&is_resource($this->file)&&realpath($path)===$this->directory;}
    public function release():void{if(is_resource($this->file)){flock($this->file,LOCK_UN);fclose($this->file);$this->file=null;}}
    public function __destruct(){$this->release();}
}
