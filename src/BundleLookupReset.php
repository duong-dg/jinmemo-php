<?php
declare(strict_types=1);
require_once __DIR__.'/AdminAuth.php';
require_once __DIR__.'/GiftBundleTransfer.php';
require_once __DIR__.'/SecretCipher.php';

/** Explicit credential replacement after historical refunds match; never sends the private URL. */
final class BundleLookupReset
{
    public function __construct(private PDO $db,private string $keyDirectory,private string $baseUrl){$db->exec("SET time_zone='+00:00'");}
    private function query(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    public function run(string $code,bool $apply=false,?string $fingerprint=null,?string $output=null):array
    {
        if(!preg_match('/^JB[A-F0-9]{10}$/D',$code))throw new InvalidArgumentException('Mã JB không hợp lệ.');
        if($apply&&(!is_string($fingerprint)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint)||!is_string($output)))throw new InvalidArgumentException('Cần fingerprint dry-run và --output là file riêng mới.');
        $r=['scope'=>'imported-bundle-lookup-reset','code'=>$code,'total'=>null,'status'=>null,'refundRows'=>0,'mode'=>$apply?'apply':'dry-run','ready'=>false,'applied'=>false,'fingerprint'=>null,'privateFile'=>null,'errors'=>[]];$targetPath=null;$created=false;$commitAttempted=false;
        if($apply){$parent=realpath(dirname($output));$public=realpath(dirname(__DIR__));if($parent===false||!is_dir($parent)||is_link(dirname($output))||is_link($output)||file_exists($output)||!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,100}\.json$/D',basename($output)))throw new InvalidArgumentException('Dùng file .json mới trong thư mục riêng có sẵn.');$targetPath=$parent.DIRECTORY_SEPARATOR.basename($output);$normalized=strtolower(str_replace('\\','/',$targetPath));$root=strtolower(str_replace('\\','/',$public));if($normalized===$root||str_starts_with($normalized,$root.'/'))throw new InvalidArgumentException('Không lưu liên kết riêng trong thư mục dự án.');
            $url=parse_url($this->baseUrl);if(!$url||!in_array($url['scheme']??'', ['http','https'],true)||empty($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||!in_array($url['path']??'', ['', '/'],true))throw new InvalidArgumentException('PUBLIC_BASE_URL không hợp lệ.');if($url['scheme']==='http'&&!in_array($url['host'],['localhost','127.0.0.1','[::1]'],true))throw new InvalidArgumentException('Cần PUBLIC_BASE_URL HTTPS.');
        }
        foreach(['jingo_checkout_bundles','jingo_checkout_members','jingo_bundle_transfers','jingo_payment_references','jingo_refunds','jingo_gift_orders','jingo_audit','jingo_catalog_locks'] as $table){if(strtoupper((string)$this->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetchColumn())!=='INNODB')throw new RuntimeException('InnoDB schema 062 required.');}
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');if(!$apply)$this->db->exec('SET TRANSACTION READ ONLY');$this->db->beginTransaction();
        try{
            $suffix=$apply?' FOR UPDATE':'';if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog'".$suffix)->fetchColumn())throw new RuntimeException('Missing catalog lock.');
            $row=$this->query('SELECT * FROM jingo_checkout_bundles WHERE code=?'.$suffix,[$code])->fetch(PDO::FETCH_ASSOC);$control=$row?$this->query('SELECT * FROM jingo_bundle_transfers WHERE bundle_id=?'.$suffix,[$row['id']])->fetch(PDO::FETCH_ASSOC):false;
            if(!$row||!$control||(int)$row['lookup_pending']!==1||$row['access_hash']!==null){$r['errors'][]='BUNDLE_NOT_PENDING_LOOKUP_RESET';$this->db->rollBack();return $r;}
            $r['total']=(int)$row['total'];$r['status']=$row['status'];
            $refunds=$this->query("SELECT * FROM jingo_refunds WHERE root_kind='BUNDLE' AND root_id=? ORDER BY id".$suffix,[$row['id']])->fetchAll(PDO::FETCH_ASSOC);
            $r['refundRows']=count($refunds);
            if(GiftBundleTransfer::refundDigest($refunds)!==$control['refunds_digest'])$r['errors'][]='HISTORICAL_REFUNDS_NOT_RECONCILED';
            $d=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);$giftId=$d['refs']['giftId']??null;$gift=is_string($giftId)?$this->query('SELECT id,code,data FROM jingo_gift_orders WHERE id=?'.$suffix,[$giftId])->fetch(PDO::FETCH_ASSOC):false;
            $members=$this->query('SELECT kind,entity_id,bundle_id FROM jingo_checkout_members WHERE bundle_id=? ORDER BY kind,entity_id'.$suffix,[$row['id']])->fetchAll(PDO::FETCH_ASSOC);$receipts=$this->query('SELECT reference,kind,entity_id,amount,created_at FROM jingo_payment_references WHERE entity_id=? ORDER BY reference_hash'.$suffix,[$row['id']])->fetchAll(PDO::FETCH_ASSOC);foreach($receipts as &$receipt)$receipt['amount']=(string)$receipt['amount'];unset($receipt);
            $original=[];foreach(['id','code','total','status','data','created_at'] as $key)$original[$key]=(string)$row[$key];if(GiftBundleTransfer::graphDigest($original,$members,$receipts)!==$control['bundle_sha'])$r['errors'][]='IMPORTED_BUNDLE_GRAPH_CHANGED';
            if($gift&&GiftBundleTransfer::giftDigest($gift)!==$control['gift_sha'])$r['errors'][]='IMPORTED_GIFT_SNAPSHOT_CHANGED';
            if(!$gift||$members!==[['kind'=>'giftId','entity_id'=>$giftId,'bundle_id'=>$row['id']]])$r['errors'][]='BUNDLE_COMPONENTS_DIFFER';else{$g=json_decode($gift['data'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);if(($d['codes']['gifts']??null)!==$gift['code']||($d['amounts'][$giftId]??null)!==($g['total']??null)||(string)($g['total']??'')!==(string)$row['total'])$r['errors'][]='BUNDLE_COMPONENTS_DIFFER';if($row['status']==='PAID'&&(count($receipts)!==1||$receipts[0]['kind']!=='BUNDLE_PAYMENT'||$receipts[0]['amount']!==(string)$row['total']||$receipts[0]['reference']!==($d['reference']??null)||($g['paymentStatus']??null)!=='PAID'||($g['paymentReference']??null)!==($d['reference']??null)||($g['paidAmount']??null)!==($g['total']??null)||($g['paidAt']??null)!==($d['paidAt']??null)||($g['bundleCode']??null)!==$row['code']))$r['errors'][]='BUNDLE_RECEIPTS_DIFFER';if($row['status']==='PENDING'&&($receipts||($g['paymentStatus']??'UNPAID')!=='UNPAID'||($g['paidAmount']??0)!==0||array_intersect(array_keys($g),['paidAt','paymentReference','bundleCode'])))$r['errors'][]='PENDING_BUNDLE_HAS_RECEIPTS';}
            $fp=hash('sha256','bundle-lookup-reset-v1:'.GiftBundleTransfer::canonical([$row,$control,$members,$receipts,$gift]));if($apply&&!hash_equals($fingerprint,$fp))$r['errors'][]='FINGERPRINT_OR_TARGET_CHANGED';$r['ready']=$r['errors']===[];$r['fingerprint']=$r['ready']?$fp:null;
            if(!$apply||!$r['ready']){$this->db->rollBack();return $r;}
            $key=bin2hex(random_bytes(32));$d['secret']=(new SecretCipher($this->keyDirectory))->encrypt($key);$this->query('UPDATE jingo_checkout_bundles SET access_hash=?,data=?,lookup_pending=0 WHERE id=?',[hash('sha256',$key),json_encode($d,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$row['id']]);
            $this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),'cli-import','transfer.bundle-lookup-reset',$row['id']]);
            $f=@fopen($targetPath,'xb');if(!$f)throw new RuntimeException('Cannot create private file.');$created=true;if(!@chmod($targetPath,0600)&&DIRECTORY_SEPARATOR==='/'){fclose($f);throw new RuntimeException('Cannot secure private file.');}try{$text=json_encode(['code'=>$code,'lookupUrl'=>rtrim($this->baseUrl,'/').($d['locale']==='en'?'/en/checkout':'/chot-don').'#'.$code.':'.$key],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";if(fwrite($f,$text)!==strlen($text)||!fflush($f))throw new RuntimeException('Cannot write private file.');}finally{fclose($f);}
            $commitAttempted=true;$this->db->commit();$r['applied']=true;$r['privateFile']=$targetPath;return $r;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($created&&!$commitAttempted&&is_file($targetPath))unlink($targetPath);throw $e;}
    }
}
