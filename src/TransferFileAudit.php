<?php
declare(strict_types=1);
require_once __DIR__.'/TransferAudit.php';

/** Verifies original bytes only; PDFs are opaque attachments, never rendered or transformed. */
final class TransferFileAudit
{
    public function run(string $export,string $mediaDirectory,string $ticketDirectory):array
    {
        $roots=[];foreach(['media'=>$mediaDirectory,'ticket_files'=>$ticketDirectory] as $kind=>$dir){$real=realpath($dir);if($real===false||!is_dir($real)||is_link($dir))throw new InvalidArgumentException('Provide existing regular asset directories.');$roots[$kind]=$real;}
        $result=['scope'=>'original-transfer-files-only','ready'=>false,'sourceSha256'=>null,'manifestSha256'=>null,'counts'=>['media'=>0,'ticket_files'=>0,'bytes'=>0],'errors'=>[],'errorCount'=>0];$manifest=[];$seen=[];
        $error=static function(string $code,string $table,int $row)use(&$result):void{$result['errorCount']++;if(count($result['errors'])<100)$result['errors'][]=['code'=>$code,'table'=>$table,'row'=>$row];};
        $report=(new TransferAudit())->run($export,function(string $table,array $columns,array $values,int $line,int $row)use(&$result,&$manifest,&$seen,$roots,$error):void{
            $kind=substr($table,12);if(!isset($roots[$kind]))return;$r=[];foreach($columns as $i=>$c){$raw=$values[$i];$r[$c['name']]=$raw==='NULL'?null:(str_starts_with($raw,'CONVERT(')?hex2bin(substr($raw,10,-16)):$raw);}
            try{
                if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$r['id'])||isset($seen[$kind][$r['id']]))throw new RuntimeException();$seen[$kind][$r['id']]=true;
                $path=$roots[$kind].DIRECTORY_SEPARATOR.$r['id'].($kind==='media'?'.webp':'.bin');$real=realpath($path);
                if($real===false||!is_file($real)||is_link($path)||dirname($real)!==$roots[$kind]||!preg_match('/^[0-9]+$/D',$r['bytes'])||(int)$r['bytes']<1||(int)$r['bytes']>10485760||filesize($real)!==(int)$r['bytes'])throw new RuntimeException();
                $before=stat($real);$hash=hash_file('sha256',$real);clearstatcache(true,$real);$after=stat($real);if($hash===false||$before['size']!==$after['size']||$before['mtime']!==$after['mtime']||$before['ctime']!==$after['ctime'])throw new RuntimeException();
                if($kind==='ticket_files'){if(!preg_match('/^[a-f0-9]{64}$/D',$r['hash'])||!hash_equals($r['hash'],$hash))throw new RuntimeException();}
                else{$info=@getimagesize($real);if(!$info||$info[2]!==IMAGETYPE_WEBP||$info[0]!=(int)$r['width']||$info[1]!=(int)$r['height'])throw new RuntimeException();}
                $result['counts'][$kind]++;$result['counts']['bytes']+=(int)$r['bytes'];if($result['counts']['bytes']>2147483648)throw new RuntimeException();$manifest[]=$kind.':'.$r['id'].':'.$r['bytes'].':'.$hash;
            }catch(Throwable){$error('MISSING_CHANGED_OR_INVALID_ORIGINAL_FILE',$kind,$row);}
        });
        $result['sourceSha256']=$report['sha256'];if(!$report['profileMatches']){$error('SOURCE_EXPORT_INVALID','export',0);return $result;}
        sort($manifest,SORT_STRING);$result['ready']=$result['errorCount']===0;if($result['ready'])$result['manifestSha256']=hash('sha256',implode("\n",$manifest));return $result;
    }
}
