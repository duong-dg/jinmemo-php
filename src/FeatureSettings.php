<?php
declare(strict_types=1);
final class FeatureSettings
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    public function read(bool $public=false):array
    {
        $r=$this->query("SELECT data,version FROM jingo_settings WHERE id='features'")->fetch(PDO::FETCH_ASSOC);if($r){$data=json_decode($r['data'],true,512,JSON_THROW_ON_ERROR);$out=['version'=>(int)$r['version'],'giftsEnabled'=>!empty($data['giftsEnabled']),'staysEnabled'=>!empty($data['staysEnabled'])];}else{$gift=$this->query("SELECT data FROM jingo_settings WHERE id='gifts'")->fetchColumn();$data=$gift===false?[]:json_decode($gift,true,512,JSON_THROW_ON_ERROR);$out=['version'=>0,'giftsEnabled'=>!empty($data['enabled']),'staysEnabled'=>false];}if($public)unset($out['version']);return $out;
    }
    public function save(array $in,string $actor):array
    {
        if(count($in)!==3||array_diff(array_keys($in),['version','giftsEnabled','staysEnabled'])||!is_int($in['version'] ?? null)||$in['version']<0||$in['version']>2147483646||!is_bool($in['giftsEnabled'] ?? null)||!is_bool($in['staysEnabled'] ?? null))throw new HttpError(400,'Kiểm tra lựa chọn phân hệ và phiên bản.');
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{
            // Same lock as gift stock, checkout, cancellation and catalog edits.
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' FOR UPDATE")->fetchColumn())throw new HttpError(503,'Chưa cài khóa danh mục.');$old=$this->read();if($old['version']!==$in['version'])throw new HttpError(409,'Thiết lập vừa thay đổi. Tải lại trước khi lưu.');$data=['giftsEnabled'=>$in['giftsEnabled'],'staysEnabled'=>$in['staysEnabled']];$json=json_encode($data,JSON_THROW_ON_ERROR);$exists=$this->query("SELECT id FROM jingo_settings WHERE id='features'")->fetchColumn();if($exists!==false)$this->query("UPDATE jingo_settings SET data=?,version=? WHERE id='features'",[$json,$in['version']+1]);else $this->query("INSERT INTO jingo_settings(id,data,version) VALUES ('features',?,?)",[$json,$in['version']+1]);
            $gift=$this->query("SELECT data,version FROM jingo_settings WHERE id='gifts'")->fetch(PDO::FETCH_ASSOC);if($gift){if((int)$gift['version']>=2147483647)throw new HttpError(409,'Phiên bản kho quà vượt giới hạn.');$g=json_decode($gift['data'],true,512,JSON_THROW_ON_ERROR);$g['enabled']=$data['giftsEnabled'];$this->query("UPDATE jingo_settings SET data=?,version=version+1 WHERE id='gifts'",[json_encode($g,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);}elseif($data['giftsEnabled'])$this->query("INSERT INTO jingo_settings(id,data) VALUES ('gifts',?)",[json_encode(['enabled'=>true,'shippingFee'=>50000,'items'=>[]],JSON_THROW_ON_ERROR)]);
            $this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$actor,'features.save','features']);$this->db->commit();return ['version'=>$in['version']+1]+$data;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
