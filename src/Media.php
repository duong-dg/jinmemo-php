<?php
declare(strict_types=1);

final class Media
{
    public function __construct(private PDO $db,private string $directory) {}
    private function query(string $sql,array $args=[]): PDOStatement { $s=$this->db->prepare($sql); $s->execute($args); return $s; }
    private static function uuid(string $id): bool { return (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$id); }
    private function folder(): void
    {
        if(!is_dir($this->directory) && !mkdir($this->directory,0700,true) && !is_dir($this->directory)) throw new RuntimeException('Cannot create media directory.');
    }
    private static function gd(): void
    {
        if(!extension_loaded('gd') || !function_exists('imagewebp') || !function_exists('imagecreatefromwebp') || !function_exists('imagejpeg')) throw new HttpError(503,'Hosting cần GD hỗ trợ JPEG, PNG và WebP.');
    }
    private function metadata(array $row): array
    {
        unset($row['created_by']); foreach(['width','height','bytes'] as $key) $row[$key]=(int)$row[$key];
        $row['url']='/api/v1/media/'.$row['id']; return $row;
    }
    public function list(): array { return array_map(fn($r)=>$this->metadata($r),$this->query('SELECT * FROM jingo_media ORDER BY created_at DESC,id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC)); }
    public function validateIds(array $ids): void
    {
        foreach(array_unique($ids) as $id) if(!is_string($id) || !self::uuid($id) || !$this->query('SELECT id FROM jingo_media WHERE id=?',[$id])->fetchColumn()) throw new HttpError(400,'Ảnh tham chiếu không tồn tại trong thư viện.');
    }
    public function file(string $id): string
    {
        if(!self::uuid($id) || !$this->query('SELECT id FROM jingo_media WHERE id=?',[$id])->fetchColumn()) throw new HttpError(404,'Không tìm thấy ảnh.');
        $path=$this->directory.'/'.$id.'.webp'; if(!is_file($path)) throw new HttpError(404,'Không tìm thấy file ảnh.'); return $path;
    }
    public function upload(string $source,string $filename,mixed $alt,string $actor): array
    {
        self::gd();
        if(!is_string($alt)) throw new HttpError(400,'Mô tả ảnh không hợp lệ.');
        $alt=trim($alt);
        if(strlen(mb_convert_encoding($alt,'UTF-16LE','UTF-8'))/2>300) throw new HttpError(400,'Mô tả ảnh tối đa 300 ký tự.');
        $size=is_file($source)?filesize($source):false;
        if(!$size || $size>5*1024*1024) throw new HttpError(400,'Chọn ảnh JPG, PNG hoặc WebP tối đa 5 MB.');
        $info=@getimagesize($source);
        if(!$info || !in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true) || $info[0]*$info[1]>25000000) throw new HttpError(400,'Ảnh không hợp lệ hoặc vượt 25 triệu điểm ảnh.');
        $limit=ini_get('memory_limit'); $unit=strtolower(substr($limit,-1)); $bytes=(int)$limit*(['g'=>1073741824,'m'=>1048576,'k'=>1024][$unit] ?? 1);
        if($bytes>0 && memory_get_usage(true)+$info[0]*$info[1]*10+32*1024*1024>$bytes) throw new HttpError(413,'Ảnh vượt bộ nhớ xử lý của hosting. Hãy giảm kích thước ảnh.');
        $this->folder(); $lock=fopen($this->directory.'/.processing.lock','c');
        if(!$lock) throw new RuntimeException('Cannot open media lock.');
        if(!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock); throw new HttpError(429,'Đang tối ưu ảnh khác. Thử lại sau.'); }
        $image=null; $output=null; $path=null;
        try {
            $image=match($info[2]) { IMAGETYPE_JPEG=>@imagecreatefromjpeg($source), IMAGETYPE_PNG=>@imagecreatefrompng($source), IMAGETYPE_WEBP=>@imagecreatefromwebp($source) };
            if(!$image) throw new HttpError(400,'Không giải mã được ảnh.');
            if($info[2]===IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $exif=@exif_read_data($source); $orientation=(int)($exif['Orientation'] ?? 1);
                if(in_array($orientation,[2,4,5,7],true)) imageflip($image,IMG_FLIP_HORIZONTAL);
                $angle=match($orientation) {3,4=>180,5,6=>-90,7,8=>90,default=>0};
                if($angle) { $rotated=imagerotate($image,$angle,0); if(!$rotated) throw new HttpError(400,'Không xoay được ảnh.'); imagedestroy($image); $image=$rotated; }
            }
            $w=imagesx($image); $h=imagesy($image); $ratio=min(1,1920/$w,1920/$h); $width=max(1,(int)round($w*$ratio)); $height=max(1,(int)round($h*$ratio));
            $output=imagecreatetruecolor($width,$height); imagealphablending($output,false); imagesavealpha($output,true);
            imagecopyresampled($output,$image,0,0,0,0,$width,$height,$w,$h);
            $raw=random_bytes(16); $raw[6]=chr((ord($raw[6])&15)|64); $raw[8]=chr((ord($raw[8])&63)|128); $hex=bin2hex($raw);
            $id=substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
            $path=$this->directory.'/'.$id.'.webp';
            if(!imagewebp($output,$path,82) || !is_file($path) || filesize($path)===0) throw new RuntimeException('Cannot write optimized image.');
            $filename=mb_substr(str_replace(['\\','/'], '_',$filename),0,200,'UTF-8');
            $this->query('INSERT INTO jingo_media(id,filename,alt,width,height,bytes,created_by) VALUES (?,?,?,?,?,?,?)',[$id,$filename,$alt,$width,$height,filesize($path),$actor]);
            $row=$this->query('SELECT * FROM jingo_media WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
            $path=null; return $this->metadata($row);
        } finally {
            if($image) imagedestroy($image); if($output) imagedestroy($output); if($path && is_file($path)) unlink($path);
            flock($lock,LOCK_UN); fclose($lock);
        }
    }
    public function shareFile(string $id): string
    {
        $source=$this->file($id); $target=$this->directory.'/'.$id.'.share-v1.jpg'; if(is_file($target)) return $target;
        self::gd(); $lock=fopen($this->directory.'/.share.lock','c');
        if(!$lock) throw new RuntimeException('Cannot open share lock.');
        if(!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock); throw new HttpError(429,'Đang tạo ảnh chia sẻ. Thử lại sau.'); }
        $image=null; $canvas=null; $temp=$target.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            if(is_file($target)) return $target;
            $image=@imagecreatefromwebp($source); if(!$image) throw new HttpError(404,'Không đọc được ảnh.');
            $canvas=imagecreatetruecolor(1200,630); imagefill($canvas,0,0,imagecolorallocate($canvas,245,244,238));
            $w=imagesx($image); $h=imagesy($image); $ratio=min(1200/$w,630/$h); $nw=max(1,(int)round($w*$ratio)); $nh=max(1,(int)round($h*$ratio));
            imagecopyresampled($canvas,$image,(int)((1200-$nw)/2),(int)((630-$nh)/2),0,0,$nw,$nh,$w,$h);
            if(!imagejpeg($canvas,$temp,86) || !rename($temp,$target)) throw new RuntimeException('Cannot write share image.'); return $target;
        } finally { if($image) imagedestroy($image); if($canvas) imagedestroy($canvas); if(is_file($temp)) unlink($temp); flock($lock,LOCK_UN); fclose($lock); }
    }
}
