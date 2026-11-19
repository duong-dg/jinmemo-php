<?php
declare(strict_types=1);

/** Original audit records are readable, but never executable PHP action payloads. */
final class SourceOrderHistory
{
    public static function guard(PDO $db,string $requestId):void
    {
        if(!$db->inTransaction())throw new LogicException('Order history guard requires a transaction.');
        $q=$db->prepare('SELECT id FROM jingo_source_order_actions WHERE id=? FOR UPDATE');$q->execute([$requestId]);
        if($q->fetchColumn()!==false)throw new HttpError(409,'Mã thao tác thuộc lịch sử đã chuyển. Hãy tải lại đơn và tạo thao tác mới.');
    }
    public static function read(PDO $db,string $orderId):array
    {
        $q=$db->prepare('SELECT id,admin_id,action,note,created_at FROM jingo_source_order_actions WHERE order_id=? ORDER BY created_at,id');$q->execute([$orderId]);$result=[];
        while($row=$q->fetch(PDO::FETCH_ASSOC))$result[]=['id'=>$row['id'],'adminId'=>$row['admin_id'],'action'=>$row['action'],'note'=>$row['note'],'createdAt'=>str_replace(' ','T',$row['created_at']).'Z'];
        return $result;
    }
}
