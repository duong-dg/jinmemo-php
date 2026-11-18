<?php
declare(strict_types=1);
function riCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function riStatus(int $status,callable $fn):void{try{$fn();}catch(HttpError $e){riCheck($e->status===$status,'Status '.$e->status);return;}throw new RuntimeException('Expected status '.$status);}
function riRows(string $sql,string $table):array
{
    $out=[];preg_match_all('/^INSERT INTO `jm_transfer_'.$table.'` \(([^\n]+)\) VALUES \(([^\n]+)\);$/m',$sql,$matches,PREG_SET_ORDER);foreach($matches as $m){$names=array_map(static fn($n)=>trim($n,'`'),explode(',',$m[1]));$values=explode(',',$m[2]);$row=[];foreach($names as $i=>$name){$v=$values[$i];$row[$name]=$v==='NULL'?null:(str_starts_with($v,'CONVERT(')?hex2bin(substr($v,10,-16)):$v);}$out[]=$row;}return $out;
}
function riChange(string $sql,string $table,callable $mutate,int $index=1):string
{
    $seen=0;$sql=preg_replace_callback('/^INSERT INTO `jm_transfer_'.$table.'` \(([^\n]+)\) VALUES \(([^\n]+)\);$/m',static function($m)use(&$seen,$index,$mutate,$table){if(++$seen!==$index)return $m[0];$names=array_map(static fn($n)=>trim($n,'`'),explode(',',$m[1]));$v=explode(',',$m[2]);$row=[];foreach($names as $i=>$name){$raw=$v[$i];$row[$name]=$raw==='NULL'?null:(str_starts_with($raw,'CONVERT(')?hex2bin(substr($raw,10,-16)):$raw);}$row=$mutate($row);foreach($names as $i=>$name){$value=$row[$name];$v[$i]=$value===null?'NULL':((str_starts_with($v[$i],'CONVERT(')||$v[$i]==='NULL'&&!preg_match('/^-?[0-9]+$/D',(string)$value))?"CONVERT(X'".bin2hex($value)."' USING utf8mb4)":$value);}return 'INSERT INTO `jm_transfer_'.$table.'` ('.$m[1].') VALUES ('.implode(',',$v).');';},$sql);preg_match_all('/^INSERT INTO `jm_transfer_'.$table.'` .*\n/m',$sql,$lines);$hash=hash('sha256',implode('',$lines[0]));
    return preg_replace_callback('/^INSERT INTO `jm_transfer_manifest` \(([^\n]+)\) VALUES \(([^\n]+)\);$/m',static function($m)use($table,$hash){$v=explode(',',$m[2]);if($v[0]!=="CONVERT(X'".bin2hex('jinmemo_'.$table)."' USING utf8mb4)")return $m[0];$v[5]="CONVERT(X'".bin2hex($hash)."' USING utf8mb4)";return 'INSERT INTO `jm_transfer_manifest` ('.$m[1].') VALUES ('.implode(',',$v).');';},$sql);
}
