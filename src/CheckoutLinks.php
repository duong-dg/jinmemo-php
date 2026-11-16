<?php
declare(strict_types=1);
final class CheckoutLinks
{
    public static function guard(PDO $db,string $kind,string $id):void
    {
        $q=$db->prepare('SELECT b.code FROM jingo_checkout_members m JOIN jingo_checkout_bundles b ON b.id=m.bundle_id WHERE m.kind=? AND m.entity_id=?');$q->execute([$kind,$id]);$code=$q->fetchColumn();if($code!==false)throw new HttpError(409,'Đơn thuộc thanh toán chung '.$code.'. Thu/hoàn theo mã JB để tránh ghi trùng.');
    }
    public static function cart(PDO $db,string $id,bool $private=false):?array
    {
        $q=$db->prepare("SELECT b.code,b.total,b.data FROM jingo_checkout_members m JOIN jingo_checkout_bundles b ON b.id=m.bundle_id WHERE m.kind='cartId' AND m.entity_id=?");$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)return null;$out=['code'=>$r['code'],'total'=>(int)$r['total']];if($private){$d=json_decode($r['data'],true,512,JSON_THROW_ON_ERROR);$out['accessKey']=(new SecretCipher(OrderMail::directory()))->decrypt($d['secret']);}return $out;
    }
}
