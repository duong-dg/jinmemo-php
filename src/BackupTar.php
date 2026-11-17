<?php
declare(strict_types=1);
/** Bounded read-only tar reader. Never extracts archive-provided paths. */
final class BackupTar
{
    private $file;
    public array $entries=[];
    public ?string $gitRevision=null;
    public function __construct(string $path,int $limit=8589934592,int $maximum=100000)
    {
        if(is_link($path)||!is_file($path))throw new RuntimeException('Expected a regular tar file.');$this->file=fopen($path,'rb');if(!$this->file)throw new RuntimeException('Cannot read tar.');
        try{$size=fstat($this->file)['size'];if($size> $limit||$size<1024||$size%512!==0)throw new RuntimeException('Invalid tar size.');$pending=[];$zeros=0;$parents=[];$headers=0;
            while(ftell($this->file)<$size){$header=$this->bytes(512);if($header===str_repeat("\0",512)){if(++$zeros===2){if($pending)throw new RuntimeException('Dangling extended header.');while(!feof($this->file)){if(trim(fread($this->file,1048576),"\0")!=='')throw new RuntimeException('Trailing tar data.');}return;}continue;}if($zeros)throw new RuntimeException('Incomplete tar terminator.');if(++$headers>$maximum*3)throw new RuntimeException('Too many tar headers.');$check=self::octal(substr($header,148,8));$blank=substr_replace($header,str_repeat(' ',8),148,8);$sum=array_sum(unpack('C*',$blank));if($check!==$sum)throw new RuntimeException('Tar checksum mismatch.');
                $name=rtrim(substr($header,0,100),"\0");if(substr($header,257,6)==="ustar\0"){$prefix=rtrim(substr($header,345,155),"\0");if($prefix!=='')$name=$prefix.'/'.$name;}$type=$header[156];$length=self::octal(substr($header,124,12));if($length>$limit||ftell($this->file)+$length>$size)throw new RuntimeException('Invalid tar member size.');
                if(in_array($type,['x','g','L'],true)){if($length>1048576)throw new RuntimeException('Extended header too large.');$body=$this->bytes($length);$this->padding($length);if($type==='L'){if(isset($pending['path'])||!str_ends_with($body,"\0"))throw new RuntimeException('Invalid long filename.');$pending['path']=rtrim($body,"\0");}else{$values=self::pax($body);if($type==='g'){if(array_keys($values)!==['comment']||$this->gitRevision!==null||!preg_match('/^[a-f0-9]{40,64}$/D',$values['comment']))throw new RuntimeException('Unsupported global header.');$this->gitRevision=$values['comment'];}else{foreach($values as $key=>$value){if(!in_array($key,['path','mtime','atime','ctime'],true)||isset($pending[$key]))throw new RuntimeException('Unsupported extended attribute.');if($key!=='path'&&!preg_match('/^-?\d+(?:\.\d+)?$/D',$value))throw new RuntimeException('Invalid timestamp.');$pending[$key]=$value;}}}continue;}
                if(!in_array($type,["\0",'0','5'],true))throw new RuntimeException('Links and special tar members are not supported.');$name=$pending['path']??$name;$pending=[];$directory=$type==='5';$name=self::name($name,$directory);if(isset($this->entries[$name])||count($this->entries)>=$maximum||$directory&&$length!==0)throw new RuntimeException('Duplicate or excessive tar entries.');$parts=explode('/',$name);array_pop($parts);$ancestor='';foreach($parts as $part){$ancestor.=($ancestor===''?'':'/').$part;if(isset($this->entries[$ancestor])&&!$this->entries[$ancestor]['directory'])throw new RuntimeException('File/directory path conflict.');$parents[$ancestor]=true;}if(!$directory&&isset($parents[$name]))throw new RuntimeException('File/directory path conflict.');$offset=ftell($this->file);$hash=hash_init('sha256');$left=$length;while($left){$chunk=$this->bytes(min($left,1048576));hash_update($hash,$chunk);$left-=strlen($chunk);}$this->padding($length);$this->entries[$name]=['offset'=>$offset,'bytes'=>$length,'sha256'=>hash_final($hash),'directory'=>$directory];
            }throw new RuntimeException('Missing tar terminator.');
        }catch(Throwable $e){fclose($this->file);$this->file=null;throw $e;}
    }
    private static function octal(string $value):int{$value=trim($value,"\0 ");if($value===''||!preg_match('/^[0-7]{1,12}$/D',$value))throw new RuntimeException('Unsupported tar number.');return (int)octdec($value);}
    public static function name(string $name,bool $directory=false):string
    {
        if($directory)$name=rtrim($name,'/');if($name===''||strlen($name)>4096||str_starts_with($name,'/')||preg_match('/[\x00-\x1f\x7f:\\\\]/',$name)||!preg_match('//u',$name))throw new RuntimeException('Unsafe archive path.');if(count(explode('/',$name))>128)throw new RuntimeException('Archive path too deep.');foreach(explode('/',$name) as $part)if($part===''||$part==='.'||$part==='..')throw new RuntimeException('Unsafe archive path.');return $name;
    }
    private static function pax(string $body):array
    {
        $out=[];while($body!==''){if(!preg_match('/^([1-9][0-9]{0,6}) /',$body,$m))throw new RuntimeException('Invalid PAX record.');$length=(int)$m[1];$prefix=strlen($m[0]);if($length<=$prefix+2||$length>strlen($body)||$body[$length-1]!=="\n")throw new RuntimeException('Invalid PAX length.');$pair=explode('=',substr($body,$prefix,$length-$prefix-1),2);if(count($pair)!==2||isset($out[$pair[0]]))throw new RuntimeException('Invalid PAX attribute.');$out[$pair[0]]=$pair[1];$body=substr($body,$length);}return $out;
    }
    private function bytes(int $length):string{$out='';while(strlen($out)<$length){$chunk=fread($this->file,$length-strlen($out));if($chunk===false||$chunk==='')throw new RuntimeException('Truncated tar.');$out.=$chunk;}return $out;}
    private function padding(int $length):void{$n=(512-$length%512)%512;if($n&&$this->bytes($n)!==str_repeat("\0",$n))throw new RuntimeException('Invalid tar padding.');}
    public function read(string $name,int $maximum=1048576,int $start=0,?int $length=null):string
    {
        $e=$this->entries[$name]??null;if(!$e||$e['directory']||$start<0||$start>$e['bytes'])throw new RuntimeException('Missing archive file.');$length??=$e['bytes']-$start;if($length<0||$length>$maximum||$start+$length>$e['bytes'])throw new RuntimeException('Archive read exceeds limit.');fseek($this->file,$e['offset']+$start);return $this->bytes($length);
    }
    public function copy(string $name,string $destination):void
    {
        $e=$this->entries[$name]??null;if(!$e||$e['directory'])throw new RuntimeException('Missing archive file.');$out=fopen($destination,'xb');if(!$out)throw new RuntimeException('Cannot stage nested archive.');try{chmod($destination,0600);fseek($this->file,$e['offset']);$left=$e['bytes'];while($left){$chunk=$this->bytes(min($left,1048576));if(fwrite($out,$chunk)!==strlen($chunk))throw new RuntimeException('Cannot stage nested archive.');$left-=strlen($chunk);}}finally{fclose($out);}
    }
    public function __destruct(){if(is_resource($this->file))fclose($this->file);}
}
