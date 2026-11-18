<?php
declare(strict_types=1);
require_once __DIR__.'/AdminAuth.php';
require_once __DIR__.'/TransferRuntimePlan.php';

/** Admin-only, read-only projections. Never returns proof hashes or arbitrary archived payloads. */
final class TransferReview
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private function installed():void{if(!$this->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jingo_transfer_records'")->fetchColumn())throw new HttpError(503,'Cần cài schema 061 trước khi xem dữ liệu chuyển đổi.');}
    private static function payload(array $row):array
    {
        $data=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new HttpError(409,'Bản ghi kho chờ không hợp lệ.');ksort($data,SORT_STRING);
        if(!hash_equals($row['row_hash'],hash('sha256',json_encode((object)$data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))))throw new HttpError(409,'Bản ghi kho chờ thay đổi; cần đối soát lại.');return $data;
    }
    private static function order(array $data,string $key):array
    {
        $s=json_decode($data['snapshot'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);$c=json_decode($data['contact'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        return ['key'=>$key,'code'=>$data['code'],'createdAt'=>$data['created_at'],'bookingStatus'=>$data['booking_status'],'paymentStatus'=>$data['payment_status'],'productName'=>$s['productName'],'variantName'=>$s['variantName'],'visitDate'=>$s['date'],'total'=>(string)$s['total'],'contact'=>array_intersect_key($c,array_flip(['name','email','phone']))];
    }
    public function overview():array
    {
        $this->installed();$counts=[];$total=0;foreach($this->query('SELECT table_name,COUNT(*) AS total FROM jingo_transfer_records GROUP BY table_name ORDER BY table_name')->fetchAll(PDO::FETCH_ASSOC) as $r){$counts[$r['table_name']]=(int)$r['total'];$total+=(int)$r['total'];}
        $v2=isset($counts['jm_transfer_manifest']);$profilePath=dirname(__DIR__).'/docs/'.($v2?'TRANSFER-V2-SCHEMA.json':'TRANSFER-EXPORT-SCHEMA.json');$profile=json_decode((string)file_get_contents($profilePath),true);$tables=[];foreach($profile['tables'] as $name=>$columns)$tables[]=['name'=>$name,'rows'=>$counts[$name]??0];
        return ['operationalReady'=>false,'state'=>'HOLDING_ONLY','totalRows'=>$total,'tables'=>$tables,'runtimePlan'=>(new TransferRuntimePlan($this->db,$profilePath))->run()];
    }
    public function orders(array $input):array
    {
        $this->installed();if(array_diff(array_keys($input),['q','page'])||isset($input['q'])&&(!is_string($input['q'])||mb_strlen($input['q'],'UTF-8')>120)||isset($input['page'])&&(!is_scalar($input['page'])||!preg_match('/^[1-9][0-9]{0,4}$/D',(string)$input['page'])))throw new HttpError(400,'Bộ lọc kho chờ không hợp lệ.');
        $q=mb_strtolower(trim($input['q']??''),'UTF-8');$where="table_name='jm_transfer_orders'";$args=[];
        if($q!==''){$where.=" AND LOCATE(?,LOWER(CONCAT_WS(' ',JSON_UNQUOTE(JSON_EXTRACT(payload,'$.code')),JSON_UNQUOTE(JSON_EXTRACT(payload,'$.contact')))))>0";$args[]=$q;}
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->exec('SET TRANSACTION READ ONLY');$this->db->beginTransaction();
        try{
            $total=(int)$this->query('SELECT COUNT(*) FROM jingo_transfer_records WHERE '.$where,$args)->fetchColumn();$page=min((int)($input['page']??1),max(1,(int)ceil($total/50)));$offset=($page-1)*50;
            $rows=$this->query("SELECT row_key,row_hash,payload FROM jingo_transfer_records WHERE $where ORDER BY JSON_UNQUOTE(JSON_EXTRACT(payload,'$.created_at')) DESC,row_key LIMIT 50 OFFSET $offset",$args)->fetchAll(PDO::FETCH_ASSOC);$data=[];
            foreach($rows as $r)$data[]=self::order(self::payload($r),$r['row_key']);$this->db->commit();return ['data'=>$data,'page'=>$page,'total'=>$total,'pageSize'=>50,'operationalReady'=>false];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function detail(string $key):array
    {
        $this->installed();if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new HttpError(404,'Không tìm thấy đơn trong kho chờ.');
        $r=$this->query("SELECT row_key,row_hash,payload FROM jingo_transfer_records WHERE table_name='jm_transfer_orders' AND row_key=?",[$key])->fetch(PDO::FETCH_ASSOC);if(!$r)throw new HttpError(404,'Không tìm thấy đơn trong kho chờ.');$raw=self::payload($r);$order=self::order($raw,$key);$order['instructions']=$raw['instructions'];$order['history']=[];$order['payments']=[];$order['files']=[];
        foreach(['order_actions'=>['action','admin_id','note','created_at'],'order_payments'=>['amount','reference','admin_id','created_at'],'ticket_files'=>['id','name','mime','bytes','created_at','revoked_at','deleted_at','file_kind']] as $table=>$fields){
            $rows=$this->query("SELECT row_hash,payload FROM jingo_transfer_records WHERE table_name=? AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.order_id'))=? ORDER BY JSON_UNQUOTE(JSON_EXTRACT(payload,'$.created_at')) DESC,row_key LIMIT 100",['jm_transfer_'.$table,$raw['id']])->fetchAll(PDO::FETCH_ASSOC);$field=['order_actions'=>'history','order_payments'=>'payments','ticket_files'=>'files'][$table];foreach($rows as $record)$order[$field][]=array_intersect_key(self::payload($record),array_flip($fields));
        }
        $order['perSectionLimit']=100;$order['operationalReady']=false;return $order;
    }
}
