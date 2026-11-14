<?php
declare(strict_types=1);
require_once __DIR__.'/Pricing.php';
final class PriceGroups
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private static function fail(string $message='Thông tin nhóm giá không hợp lệ.'):never{throw new HttpError(400,$message);}
    private static function uuid(mixed $id):bool{return is_string($id)&&(bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$id);}
    private static function text(mixed $v,int $max):string{if(!is_string($v))self::fail();$v=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$v);if($v===null||$v===''||str_contains($v,"\0")||strlen(mb_convert_encoding($v,'UTF-16LE','UTF-8'))/2>$max)self::fail();return $v;}
    private static function integer(mixed $v,int $max):bool{return is_int($v)&&$v>=0&&$v<=$max;}
    public static function validate(array $input):array
    {
        if(array_diff(array_keys($input),['id','name','status','pricingMode','discountType','discountValue','version','rules'])||!self::uuid($input['id'] ?? null)||!in_array($input['status'] ?? null,['ACTIVE','ARCHIVED'],true)||!in_array($input['discountType'] ?? null,['AMOUNT','PERCENT'],true)||!self::integer($input['discountValue'] ?? null,100000000)||!self::integer($input['version'] ?? null,2147483646))self::fail();
        if($input['discountType']==='PERCENT'&&$input['discountValue']>10000)self::fail('Chiết khấu phần trăm tối đa 100%.');$out=['id'=>$input['id'],'name'=>self::text($input['name'] ?? null,100),'status'=>$input['status'],'discountType'=>$input['discountType'],'discountValue'=>$input['discountValue'],'version'=>$input['version']];
        if(array_key_exists('pricingMode',$input)){if(!in_array($input['pricingMode'],['DIRECT','DISCOUNT'],true))self::fail();$out['pricingMode']=$input['pricingMode'];}
        $rules=$input['rules'] ?? [];if((array_key_exists('rules',$input)&&$input['rules']===null)||!is_array($rules)||!array_is_list($rules)||count($rules)>1000)self::fail('Danh sách giá riêng tối đa 1000 dòng.');$out['rules']=[];$ids=[];$scopes=[];
        foreach($rules as $r){if(!is_array($r)||array_diff(array_keys($r),['id','slotId','productId','variantId','customerType','startDate','endDate','priority','amount'])||!self::uuid($r['id'] ?? null)||!in_array($r['customerType'] ?? null,Pricing::GROUPS,true)||!self::integer($r['priority'] ?? null,10000)||!self::integer($r['amount'] ?? null,100000000))self::fail('Dòng giá riêng không hợp lệ.');$id=strtolower($r['id']);if(isset($ids[$id]))self::fail('Mã giá riêng bị trùng.');$ids[$id]=true;
            $rule=['id'=>$r['id'],'productId'=>self::text($r['productId'] ?? null,80),'variantId'=>self::text($r['variantId'] ?? null,80),'customerType'=>$r['customerType'],'priority'=>$r['priority'],'amount'=>$r['amount']];foreach(['startDate','endDate'] as $key){if(!is_string($r[$key] ?? null))self::fail();Pricing::validateDate($r[$key]);$rule[$key]=$r[$key];}if($rule['startDate']>$rule['endDate'])self::fail('Ngày kết thúc phải sau ngày bắt đầu.');if(array_key_exists('slotId',$r)){if(!is_string($r['slotId'])||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$r['slotId']))self::fail();$rule['slotId']=$r['slotId'];}
            $scope=json_encode([$rule['productId'],$rule['variantId'],$rule['customerType'],$rule['priority']],JSON_THROW_ON_ERROR);foreach($scopes[$scope] ?? [] as $prior)if($prior['startDate']<=$rule['endDate']&&$rule['startDate']<=$prior['endDate'])self::fail('Giá riêng trùng phạm vi và độ ưu tiên.');$scopes[$scope][]=$rule;$out['rules'][]=$rule;
        }return $out;
    }
    public function list():array
    {
        $out=[];foreach($this->query('SELECT id,data,version FROM jingo_price_groups ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row){$g=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);$g=array_intersect_key($g,array_flip(['name','status','pricingMode','discountType','discountValue','rules']));$out[]=['id'=>$row['id']]+$g+['version'=>(int)$row['version']];}return $out;
    }
    public function save(array $input,string $actor):array
    {
        $g=self::validate($input);$this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' FOR UPDATE")->fetchColumn())throw new HttpError(503,'Chưa cài khóa danh mục.');$products=[];
            foreach($g['rules'] as $r){if(!isset($products[$r['productId']])){$raw=$this->query('SELECT data FROM jingo_products WHERE id=?',[$r['productId']])->fetchColumn();if($raw===false)self::fail('Giá riêng tham chiếu sản phẩm không tồn tại.');$products[$r['productId']]=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}$valid=false;foreach($products[$r['productId']]['variants'] as $v)if($v['id']===$r['variantId']&&array_key_exists($r['customerType'],$v['prices']))$valid=true;if(!$valid)self::fail('Giá riêng tham chiếu gói hoặc nhóm khách không hợp lệ.');}
            $version=$this->query('SELECT version FROM jingo_price_groups WHERE id=? FOR UPDATE',[$g['id']])->fetchColumn();if(($version===false&&$g['version']!==0)||($version!==false&&(int)$version!==$g['version']))throw new HttpError(409,'Nhóm giá đã thay đổi hoặc mã đã tồn tại. Tải lại trước khi lưu.');
            if($g['status']==='ARCHIVED')foreach($this->query('SELECT data FROM jingo_partners')->fetchAll(PDO::FETCH_COLUMN) as $raw)if((json_decode($raw,true,512,JSON_THROW_ON_ERROR)['groupId'] ?? null)===$g['id'])throw new HttpError(409,'Nhóm giá đang có CTV. Chuyển CTV sang nhóm khác trước.');
            $g['version']++;$json=json_encode($g,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if($version===false)$this->query('INSERT INTO jingo_price_groups(id,data,version) VALUES (?,?,?)',[$g['id'],$json,$g['version']]);else $this->query('UPDATE jingo_price_groups SET data=?,version=? WHERE id=?',[$json,$g['version'],$g['id']]);$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$actor,'price-group.save',$g['id']]);$this->db->commit();return $g;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException&&($e->errorInfo[1] ?? null)===1062)throw new HttpError(409,'Nhóm giá đã tồn tại.');throw $e;}
    }
}
