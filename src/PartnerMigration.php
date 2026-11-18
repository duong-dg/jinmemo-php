<?php
declare(strict_types=1);
final class PartnerMigration
{
    public static function requireReady(PDO $db,string $id):void
    {
        $s=$db->prepare('SELECT transfer_pending FROM jingo_partners WHERE id=?'.($db->inTransaction()?' LOCK IN SHARE MODE':''));$s->execute([$id]);$pending=$s->fetchColumn();
        if($pending!==false&&(int)$pending!==0)throw new HttpError(409,'CTV đang chờ nhập và đối chiếu đơn/công nợ. Chưa được giao dịch.');
    }
}
