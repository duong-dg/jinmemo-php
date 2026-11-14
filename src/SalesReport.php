<?php
declare(strict_types=1);

final class SalesReport
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement
    {
        $s=$this->db->prepare($sql);$s->execute($args);return $s;
    }
    private static function amount(mixed $value):int
    {
        if(is_string($value)&&preg_match('/^[0-9]+$/D',$value)){
            if(strlen($value)>16||strlen($value)===16&&strcmp($value,'9007199254740991')>0)throw new HttpError(409,'Tổng báo cáo vượt phạm vi xử lý.');
            $value=(int)$value;
        }
        if(!is_int($value)||$value<0||$value>9007199254740991)throw new HttpError(409,'Dữ liệu tiền của báo cáo không hợp lệ.');
        return $value;
    }
    private static function add(int $a,int $b):int
    {
        if($b>9007199254740991-$a)throw new HttpError(409,'Tổng báo cáo vượt phạm vi xử lý.');return $a+$b;
    }
    public function build(array $input):array
    {
        if(array_diff(array_keys($input),['from','to','channel','counterId']))throw new HttpError(400,'Bộ lọc báo cáo không hợp lệ.');
        foreach(['from','to'] as $key){
            if(!is_string($input[$key] ?? null))throw new HttpError(400,'Chọn khoảng ngày báo cáo.');
            Pricing::validateDate($input[$key]);
        }
        $from=$input['from'];$to=$input['to'];$channel=$input['channel'] ?? 'ALL';$counter=$input['counterId'] ?? '';
        if(!in_array($channel,['ALL','WEB','POS'],true))throw new HttpError(400,'Kênh bán không hợp lệ.');
        if(!is_string($counter)||$counter!==''&&!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$counter))throw new HttpError(400,'Mã quầy không hợp lệ.');
        $zone=new DateTimeZone('Asia/Ho_Chi_Minh');$begin=new DateTimeImmutable($from.' 00:00:00',$zone);$last=new DateTimeImmutable($to.' 00:00:00',$zone);
        if($from>$to||$last->getTimestamp()-$begin->getTimestamp()>366*86400)throw new HttpError(400,'Khoảng báo cáo tối đa 367 ngày.');
        $end=$last->modify('+1 day');$args=[$begin->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),$end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
        // Apply the same channel/counter predicates to creation dates and collection dates.
        $where=$channel==='ALL'?'':($channel==='POS'?' AND po.order_id IS NOT NULL':' AND po.order_id IS NULL');
        if($counter!==''){$where.=' AND po.counter_id=?';$args[]=$counter;}
        $joins=' LEFT JOIN jingo_pos_orders po ON po.order_id=o.id LEFT JOIN jingo_counters c ON c.id=po.counter_id';
        $zero=['orders'=>0,'cancelled'=>0,'bookingValue'=>0,'collected'=>0,'cash'=>0,'bank'=>0,'unpaidBookingValue'=>0];
        $daily=[];for($day=$begin;$day<=$last;$day=$day->modify('+1 day')){$date=$day->format('Y-m-d');$daily[$date]=['date'=>$date]+$zero;}
        $totals=$zero;$groups=[];
        $dateOf=static fn(string $date):string=>(new DateTimeImmutable($date,new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d');
        $groupKey=static function(array $row)use(&$groups,$zero):string{
            $id=$row['counter_id'];$key=$id===null?'WEB':'POS:'.$id;
            if(!isset($groups[$key]))$groups[$key]=['channel'=>$id===null?'WEB':'POS','counterId'=>$id,'name'=>$id===null?'Website':$row['counter_name']]+$zero;
            return $key;
        };
        $record=static function(string $date,string $group,array $values)use(&$totals,&$daily,&$groups):void{
            foreach($values as $key=>$value){
                $totals[$key]=self::add($totals[$key],$value);
                $daily[$date][$key]=self::add($daily[$date][$key],$value);
                $groups[$group][$key]=self::add($groups[$group][$key],$value);
            }
        };
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();
        try{
            $orders=$this->query("SELECT o.booking_status,o.payment_status,o.created_at,JSON_EXTRACT(o.snapshot,'$.total') AS total,po.counter_id,JSON_UNQUOTE(JSON_EXTRACT(c.data,'$.name')) AS counter_name FROM jingo_orders o".$joins.' WHERE o.created_at>=? AND o.created_at<?'.$where.' ORDER BY o.created_at,o.id',$args);
            while($order=$orders->fetch(PDO::FETCH_ASSOC)){
                $date=$dateOf($order['created_at']);$group=$groupKey($order);
                if($order['booking_status']==='CANCELLED'){$record($date,$group,['cancelled'=>1]);continue;}
                $amount=self::amount(json_decode((string)$order['total'],true,512,JSON_THROW_ON_ERROR));
                $record($date,$group,['orders'=>1,'bookingValue'=>$amount,'unpaidBookingValue'=>$order['payment_status']==='UNPAID'?$amount:0]);
            }
            $payments=$this->query("SELECT p.amount,p.method,p.created_at,po.counter_id,JSON_UNQUOTE(JSON_EXTRACT(c.data,'$.name')) AS counter_name FROM jingo_order_payments p JOIN jingo_orders o ON o.id=p.order_id".$joins.' WHERE p.created_at>=? AND p.created_at<?'.$where.' ORDER BY p.created_at,p.id',$args);
            while($payment=$payments->fetch(PDO::FETCH_ASSOC)){
                if(!in_array($payment['method'],['CASH','BANK'],true))throw new HttpError(409,'Có phương thức thu chưa được hỗ trợ trong báo cáo.');
                $amount=self::amount($payment['amount']);$record($dateOf($payment['created_at']),$groupKey($payment),['collected'=>$amount,($payment['method']==='CASH'?'cash':'bank')=>$amount]);
            }
            ksort($groups,SORT_STRING);$this->db->commit();
            return ['from'=>$from,'to'=>$to,'channel'=>$channel,'counterId'=>$counter!==''?$counter:null,'supportedChannels'=>['WEB','POS'],'generatedAt'=>gmdate('Y-m-d\TH:i:s\Z'),'totals'=>$totals,'daily'=>array_values($daily),'channels'=>array_values($groups)];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public static function csv(array $report):string
    {
        $rows=[['Loại dòng','Từ ngày (GMT+7)','Đến ngày (GMT+7)','Ngày (GMT+7)','Kênh','Số đơn chưa hủy','Đơn hủy','Giá trị đơn (VND)','Đã thu (VND)','Tiền mặt (VND)','Chuyển khoản (VND)','Giá trị đơn chưa thu (VND)','Mã quầy','Tên quầy']];
        $row=static fn($kind,$date,$v,$channel,$counter,$name)=>[$kind,$report['from'],$report['to'],$date,$channel,$v['orders'],$v['cancelled'],$v['bookingValue'],$v['collected'],$v['cash'],$v['bank'],$v['unpaidBookingValue'],$counter,$name];
        $rows[]=$row('TỔNG','',$report['totals'],$report['channel'],$report['counterId'] ?? '','');
        foreach($report['daily'] as $day)$rows[]=$row('NGÀY',$day['date'],$day,$report['channel'],$report['counterId'] ?? '','');
        foreach($report['channels'] as $group)$rows[]=$row('KÊNH','',$group,$group['channel'],$group['counterId'] ?? '',$group['name']);
        $cell=static function($value){$value=str_replace("\0",'',(string)$value);if(preg_match('/^[\s\x{FEFF}]*[=+@-]|^[\t\r\n]/u',$value))$value="'".$value;return '"'.str_replace('"','""',$value).'"';};
        return "\xEF\xBB\xBF".implode("\r\n",array_map(fn($r)=>implode(',',array_map($cell,$r)),$rows))."\r\n";
    }
}
