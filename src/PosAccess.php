<?php
declare(strict_types=1);

final class PosAccess
{
    public static function live(PDO $db,string $staff,string $counter,string $permission):void
    {
        $s=$db->prepare('SELECT data FROM jingo_pos_users WHERE id=? LOCK IN SHARE MODE');$s->execute([$staff]);$raw=$s->fetchColumn();$user=$raw===false?null:json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(!$user||($user['status'] ?? '')!=='ACTIVE'||!in_array($counter,$user['counterIds'] ?? [],true)||!in_array($permission,$user['permissions'] ?? [],true))throw new HttpError(403,'Quầy hoặc quyền nhân viên đã thay đổi. Đăng nhập lại để tiếp tục.');
    }
}
