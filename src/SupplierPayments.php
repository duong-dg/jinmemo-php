<?php
declare(strict_types=1);

final class SupplierPayments
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private static function amount(mixed $value):int
    {
        $text=(string)$value;if(!preg_match('/^[0-9]+$/D',$text))throw new HttpError(409,'Sổ nguồn có số tiền không hợp lệ.');$text=ltrim($text,'0') ?: '0';if(strlen($text)>16||strlen($text)===16&&strcmp($text,'9007199254740991')>0)throw new HttpError(409,'Tổng công nợ vượt phạm vi xử lý.');return (int)$text;
    }
    private function totals(?string $id=null):array
    {
        $sql="SELECT s.id,s.data,COALESCE(l.total,0)+COALESCE(p.total,0) AS purchased,COALESCE(r.total,0) AS paid FROM jingo_suppliers s LEFT JOIN (SELECT supplier_id,SUM(CAST(unit_cost AS DECIMAL(30,0))*total_quantity) AS total FROM jingo_supply_lots WHERE unlimited=0 GROUP BY supplier_id) l ON l.supplier_id=s.id LEFT JOIN (SELECT supplier_id,SUM(CAST(unit_cost AS DECIMAL(30,0))*quantity) AS total FROM jingo_supply_purchases GROUP BY supplier_id) p ON p.supplier_id=s.id LEFT JOIN (SELECT supplier_id,SUM(CAST(amount AS DECIMAL(30,0))) AS total FROM jingo_supplier_payments GROUP BY supplier_id) r ON r.supplier_id=s.id".($id===null?'':' WHERE s.id=?').' ORDER BY s.id';$rows=$this->query($sql,$id===null?[]:[$id])->fetchAll(PDO::FETCH_ASSOC);$result=[];foreach($rows as $row){$data=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);$purchased=self::amount($row['purchased']);$paid=self::amount($row['paid']);$result[]=['id'=>$row['id'],'name'=>$data['name'],'purchased'=>$purchased,'paid'=>$paid,'balance'=>$purchased-$paid];}return $result;
    }
    public function balances():array
    {
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();try{$rows=$this->totals();$payments=$this->query('SELECT id,supplier_id,amount,method,reference,note,created_at FROM jingo_supplier_payments ORDER BY created_at DESC,id')->fetchAll(PDO::FETCH_ASSOC);$bySupplier=[];foreach($payments as $p){$p['amount']=self::amount($p['amount']);$bySupplier[$p['supplier_id']][]=$p;}foreach($rows as &$r)$r['payments']=$bySupplier[$r['id']] ?? [];unset($r);$this->db->commit();return $rows;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private static function text(mixed $value,int $max,bool $required=false):string
    {
        if(!is_string($value))throw new HttpError(400,'Thông tin khoản trả không hợp lệ.');$value=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$value);if($value===null||$required&&$value===''||strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))/2>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u',$value))throw new HttpError(400,'Kiểm tra độ dài mã giao dịch và ghi chú.');return $value;
    }
    public function pay(array $raw,string $actor):array
    {
        if(array_diff(array_keys($raw),['requestId','supplierId','amount','method','reference','note']))throw new HttpError(400,'Dữ liệu khoản trả không hợp lệ.');foreach(['requestId','supplierId'] as $key)if(!is_string($raw[$key] ?? null)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$raw[$key]))throw new HttpError(400,'Mã thao tác/nhà cung cấp không hợp lệ.');if(!is_int($raw['amount'] ?? null)||$raw['amount']<1||$raw['amount']>15000000000||!in_array($raw['method'] ?? null,['BANK','CASH'],true))throw new HttpError(400,'Số tiền hoặc hình thức trả không hợp lệ.');
        // Field order and trimmed defaults match the Node payment schema/hash.
        $input=['requestId'=>$raw['requestId'],'supplierId'=>$raw['supplierId'],'amount'=>$raw['amount'],'method'=>$raw['method'],'reference'=>self::text($raw['reference'] ?? '',200),'note'=>self::text($raw['note'] ?? null,1000,true)];if($input['method']==='BANK'&&($input['reference']===''||preg_match('/[\r\n\t]/',$input['reference'])))throw new HttpError(400,'Nhập mã giao dịch ngân hàng hợp lệ.');$hash=hash('sha256',json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR));
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' FOR UPDATE")->fetchColumn())throw new HttpError(503,'Chưa cài khóa danh mục.');if(!$this->query('SELECT id FROM jingo_suppliers WHERE id=? FOR UPDATE',[$input['supplierId']])->fetchColumn())throw new HttpError(404,'Không tìm thấy nhà cung cấp.');$prior=$this->query('SELECT request_hash FROM jingo_supplier_payments WHERE id=?',[$input['requestId']])->fetchColumn();if($prior!==false){if(!hash_equals($prior,$hash))throw new HttpError(409,'Mã thao tác đã dùng với nội dung khác.');$this->db->commit();return ['ok'=>true];}
            $balance=$this->totals($input['supplierId'])[0];if($input['amount']>$balance['balance'])throw new HttpError(400,'Số tiền vượt công nợ nhà cung cấp.');$reference=$input['method']==='CASH'?'CASH:'.$input['requestId']:$input['reference'];$this->query('INSERT INTO jingo_supplier_payments(id,supplier_id,amount,method,reference,note,request_hash) VALUES (?,?,?,?,?,?,?)',[$input['requestId'],$input['supplierId'],$input['amount'],$input['method'],$reference,$input['note'],$hash]);$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$actor,'supplier.payment',$input['supplierId']]);$this->db->commit();return ['ok'=>true];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException&&($e->errorInfo[1] ?? null)===1062)throw new HttpError(409,'Mã giao dịch hoặc khoản trả đã được ghi nhận.');throw $e;}
    }
}
