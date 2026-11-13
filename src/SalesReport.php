<?php
declare(strict_types=1);

final class SalesReport
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private static function amount(mixed $value):int
    {
        if(is_string($value)&&preg_match('/^[0-9]+$/D',$value)){if(strlen($value)>16||strlen($value)===16&&strcmp($value,'9007199254740991')>0)throw new HttpError(409,'Tổng báo cáo vượt phạm vi xử lý.');$value=(int)$value;}
        if(!is_int($value)||$value<0||$value>9007199254740991)throw new HttpError(409,'Dữ liệu tiền của báo cáo không hợp lệ.');return $value;
    }
    private static function add(int $a,int $b):int{if($b>9007199254740991-$a)throw new HttpError(409,'Tổng báo cáo vượt phạm vi xử lý.');return $a+$b;}
    public function build(array $input):array
    {
        if(array_diff(array_keys($input),['from','to','channel']))throw new HttpError(400,'Bộ lọc báo cáo không hợp lệ.');foreach(['from','to'] as $key){if(!is_string($input[$key] ?? null))throw new HttpError(400,'Chọn khoảng ngày báo cáo.');Pricing::validateDate($input[$key]);}$from=$input['from'];$to=$input['to'];$channel=$input['channel'] ?? 'ALL';if(!in_array($channel,['ALL','WEB'],true))throw new HttpError(400,'Bản PHP hiện hỗ trợ báo cáo kênh Website.');$zone=new DateTimeZone('Asia/Ho_Chi_Minh');$begin=new DateTimeImmutable($from.' 00:00:00',$zone);$last=new DateTimeImmutable($to.' 00:00:00',$zone);if($from>$to||$last->getTimestamp()-$begin->getTimestamp()>366*86400)throw new HttpError(400,'Khoảng báo cáo tối đa 367 ngày.');$end=$last->modify('+1 day');$args=[$begin->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),$end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
        $zero=['orders'=>0,'cancelled'=>0,'bookingValue'=>0,'collected'=>0,'cash'=>0,'bank'=>0,'unpaidBookingValue'=>0];$daily=[];for($day=$begin;$day<=$last;$day=$day->modify('+1 day')){$date=$day->format('Y-m-d');$daily[$date]=['date'=>$date]+$zero;}$totals=$zero;$dateOf=static fn(string $date):string=>(new DateTimeImmutable($date,new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d');
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();try{
            $orders=$this->query("SELECT booking_status,payment_status,created_at,JSON_EXTRACT(snapshot,'$.total') AS total FROM jingo_orders WHERE created_at>=? AND created_at<? ORDER BY created_at,id",$args);while($order=$orders->fetch(PDO::FETCH_ASSOC)){$date=$dateOf($order['created_at']);if($order['booking_status']==='CANCELLED'){$daily[$date]['cancelled']++;$totals['cancelled']++;continue;}$amount=self::amount(json_decode((string)$order['total'],true,512,JSON_THROW_ON_ERROR));foreach(['orders'=>1,'bookingValue'=>$amount,'unpaidBookingValue'=>$order['payment_status']==='UNPAID'?$amount:0] as $key=>$value){$daily[$date][$key]=self::add($daily[$date][$key],$value);$totals[$key]=self::add($totals[$key],$value);}}
            $payments=$this->query('SELECT amount,method,created_at FROM jingo_order_payments WHERE created_at>=? AND created_at<? ORDER BY created_at,id',$args);while($payment=$payments->fetch(PDO::FETCH_ASSOC)){if(!in_array($payment['method'],['CASH','BANK'],true))throw new HttpError(409,'Có phương thức thu chưa được hỗ trợ trong báo cáo.');$amount=self::amount($payment['amount']);$date=$dateOf($payment['created_at']);foreach(['collected',$payment['method']==='CASH'?'cash':'bank'] as $key){$daily[$date][$key]=self::add($daily[$date][$key],$amount);$totals[$key]=self::add($totals[$key],$amount);}}
            $this->db->commit();return ['from'=>$from,'to'=>$to,'channel'=>$channel,'supportedChannels'=>['WEB'],'generatedAt'=>gmdate('Y-m-d\TH:i:s\Z'),'totals'=>$totals,'daily'=>array_values($daily),'channels'=>[['channel'=>'WEB','counterId'=>null,'name'=>'Website','orders'=>$totals['orders'],'bookingValue'=>$totals['bookingValue'],'collected'=>$totals['collected']]]];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public static function csv(array $report):string
    {
        $rows=[['Loại dòng','Từ ngày (GMT+7)','Đến ngày (GMT+7)','Ngày (GMT+7)','Kênh','Số đơn chưa hủy','Đơn hủy','Giá trị đơn (VND)','Đã thu (VND)','Tiền mặt (VND)','Chuyển khoản (VND)','Giá trị đơn chưa thu (VND)']];$row=static fn($kind,$date,$v)=>[$kind,$report['from'],$report['to'],$date,'WEB',$v['orders'],$v['cancelled'],$v['bookingValue'],$v['collected'],$v['cash'],$v['bank'],$v['unpaidBookingValue']];$rows[]=$row('TỔNG','',$report['totals']);foreach($report['daily'] as $day)$rows[]=$row('NGÀY',$day['date'],$day);$cell=static function($value){$value=(string)$value;if(preg_match('/^[\s\x{FEFF}]*[=+@-]|^[\t\r\n]/u',$value))$value="'".$value;return '"'.str_replace('"','""',$value).'"';};return "\xEF\xBB\xBF".implode("\r\n",array_map(fn($row)=>implode(',',array_map($cell,$row)),$rows))."\r\n";
    }
}
