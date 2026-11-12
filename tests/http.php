<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
$dsn=getenv('JINGO_TEST_DSN');
if(!$dsn) { fwrite(STDERR,"Set JINGO_TEST_DSN to a disposable MySQL server without dbname.\n"); exit(1); }
if(!preg_match('/host=([^;]+);port=(\d+)/',$dsn,$connection)) throw new RuntimeException('Test DSN needs host and port.');
$server=new PDO($dsn,getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='jingo_http_test_'.bin2hex(random_bytes(6)); $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
$db=null; $process=null; $pipes=[]; $mediaDir=sys_get_temp_dir().'/'.$name; mkdir($mediaDir,0700); $fixture=$mediaDir.'/fixture.png';
function request(string $base,string $path,int $expected,string $method='GET',array $headers=[],?array $body=null): array {
    $headers[]='Connection: close';
    if($body!==null) $headers[]='Content-Type: application/json';
    $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$body===null?'':json_encode($body),'ignore_errors'=>true,'timeout'=>5]]);
    $raw=file_get_contents($base.$path,false,$ctx);
    if($raw===false || !isset($http_response_header[0])) throw new RuntimeException('No HTTP response: '.$path);
    if(!str_contains($http_response_header[0],' '.$expected.' ')) throw new RuntimeException($path.': '.$http_response_header[0].' '.$raw);
    return json_decode($raw,true,512,JSON_THROW_ON_ERROR);
}
try {
    $db=new PDO($dsn.';dbname='.$name,getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    foreach(glob(dirname(__DIR__).'/database/*.sql') as $file) foreach(explode(';',file_get_contents($file)) as $sql) if(trim($sql)!=='') $db->exec($sql);
    $db->prepare('INSERT INTO jingo_admins VALUES (?,?,?)')->execute(['admin','admin@example.com',password_hash('local-http-test-password',PASSWORD_BCRYPT)]);
    $token=bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO jingo_sessions VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))')->execute([hash('sha256',$token),'admin']);
    $socket=stream_socket_server('tcp://127.0.0.1:0'); $address=stream_socket_get_name($socket,false); fclose($socket);
    $base='http://'.$address; $root=dirname(__DIR__);
    $env=array_merge(getenv(),['APP_ENV'=>'development','APP_NAME'=>'JinMemo Test','MEDIA_DIR'=>$mediaDir,'PUBLIC_BASE_URL'=>$base,'DB_HOST'=>$connection[1],'DB_PORT'=>$connection[2],'DB_DATABASE'=>$name,'DB_USERNAME'=>getenv('JINGO_TEST_USER')?:'root','DB_PASSWORD'=>getenv('JINGO_TEST_PASSWORD')?:'']);
    $command=[PHP_BINARY,'-d','extension=gd','-d','upload_tmp_dir='.$mediaDir,'-d','display_errors=0'];
    array_push($command,'-S',$address,'-t',$root.'/public',$root.'/public/index.php');
    $process=proc_open($command,[['pipe','r'],['file',$mediaDir.'/stdout.log','a'],['file',$mediaDir.'/server.log','a']],$pipes,$root,$env);
    if(!is_resource($process)) throw new RuntimeException('Cannot start PHP server.');
    $ready=false;
    for($i=0;$i<40;$i++) { $s=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($s) { fclose($s); $ready=true; break; } usleep(50000); }
    if(!$ready) throw new RuntimeException('PHP server not ready.');
    if(request($base,'/api/v1/health',200)['application']!=='JinMemo Test') throw new RuntimeException('Brand config');
    $html=file_get_contents($base.'/admin');
    if(!str_contains($html,'id="login-form"') || !str_contains($html,'JinMemo Test') || !str_contains($html,'/assets/admin.js')) throw new RuntimeException('Admin HTML shell');
    foreach(['/assets/admin.js','/assets/admin.css'] as $asset) if(strlen(file_get_contents($base.$asset))<100) throw new RuntimeException('Missing admin asset');
    request($base,'/admin',405,'POST',[],[]);
    request($base,'/.env',404);
    request($base,'/api/v1/admin/login',401,'POST',['X-Jingo-Admin: 1','Origin: '.$base],['email'=>'admin@example.com','password'=>'wrong-password']);
    $login=curl_init($base.'/api/v1/admin/login');
    curl_setopt_array($login,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Jingo-Admin: 1','Origin: '.$base],CURLOPT_POSTFIELDS=>json_encode(['email'=>'admin@example.com','password'=>'local-http-test-password'])]);
    $loginResponse=curl_exec($login);$loginStatus=curl_getinfo($login,CURLINFO_RESPONSE_CODE);unset($login);
    if($loginStatus!==200 || !preg_match('/Set-Cookie: (jingo_admin=[a-f0-9]{64});[^\r\n]*HttpOnly[^\r\n]*SameSite=Strict/i',$loginResponse,$loginCookie)) throw new RuntimeException('Real login cookie');
    request($base,'/api/v1/admin/me',200,'GET',['Cookie: '.$loginCookie[1]]);
    request($base,'/api/v1/admin/logout',200,'POST',['Cookie: '.$loginCookie[1],'X-Jingo-Admin: 1','Origin: '.$base],[]);
    request($base,'/api/v1/admin/me',401,'GET',['Cookie: '.$loginCookie[1]]);
    request($base,'/api/v1/admin/product-types',401);
    $cookie='Cookie: jingo_admin='.$token;
    request($base,'/api/v1/admin/product-types',403,'POST',[$cookie],[]);
    $headers=[$cookie,'X-Jingo-Admin: 1','Origin: '.$base];
    request($base,'/api/v1/admin/product-types',400,'POST',$headers,[]);
    $input=['id'=>'ticket','slug'=>'ve-tham-quan','name'=>'Vé tham quan','bookingKind'=>'TICKET','status'=>'ACTIVE','version'=>0];
    request($base,'/api/v1/admin/product-types',200,'POST',$headers,$input);
    if(count(request($base,'/api/v1/product-types',200)['data'])!==1) throw new RuntimeException('Public route');
    request($base,'/api/v1/product-types/ve-tham-quan',200);
    request($base,'/api/v1/product-types/ve-tham-quan?lang=en',404);
    request($base,'/api/v1/product-types',405,'POST',$headers,$input);
    request($base,'/api/v1/admin/destinations',401);
    $destination=['id'=>'00000000-0000-4000-8000-000000000001','slug'=>'da-nang','englishSlug'=>'danang','name'=>'Đà Nẵng','englishName'=>'Da Nang','parentId'=>null,'status'=>'ACTIVE','version'=>0];
    request($base,'/api/v1/admin/destinations',403,'POST',[$cookie],$destination);
    request($base,'/api/v1/admin/destinations',200,'POST',$headers,$destination);
    $view=request($base,'/api/v1/destinations/danang?lang=en',200)['data'];
    if($view['name']!=='Da Nang' || isset($view['version']) || $view['localePaths']['vi']!=='/diem-den/da-nang') throw new RuntimeException('Destination public response');
    if(count(request($base,'/api/v1/destinations',200)['data'])!==1) throw new RuntimeException('Destination list');
    request($base,'/api/v1/destinations',405,'POST',$headers,$destination);
    request($base,'/api/v1/admin/suppliers',401);
    $supplier=['id'=>'00000000-0000-4000-8000-000000000003','name'=>'Nhà cung cấp thử','status'=>'ACTIVE','version'=>0];
    request($base,'/api/v1/admin/suppliers',403,'POST',[$cookie],$supplier);
    request($base,'/api/v1/admin/suppliers',200,'POST',$headers,$supplier);
    if(count(request($base,'/api/v1/admin/suppliers',200,'GET',[$cookie])['data'])!==1) throw new RuntimeException('Supplier list');
    request($base,'/api/v1/admin/suppliers',409,'POST',$headers,$supplier);
    request($base,'/api/v1/suppliers',404);
    $pricingProduct=['id'=>'pricing-fixture','name'=>'Vé thử giá','status'=>'DRAFT','demo'=>false,'variants'=>[['id'=>'eco','name'=>'Eco','prices'=>['ADULT'=>600000,'LOCAL_CHILD'=>0]]],'onlineDiscount'=>['type'=>'PERCENT','value'=>15],'rules'=>[],'closures'=>[]];
    $db->prepare('INSERT INTO jingo_products(id,slug,data) VALUES (?,?,?)')->execute([$pricingProduct['id'],'pricing-fixture',json_encode($pricingProduct)]);
    $preview=['productId'=>$pricingProduct['id'],'variantId'=>'eco','date'=>'2099-06-01','quantities'=>['ADULT'=>2,'LOCAL_CHILD'=>1]];
    request($base,'/api/v1/admin/pricing-preview',403,'POST',[],$preview);
    request($base,'/api/v1/admin/pricing-preview',401,'POST',['X-Jingo-Admin: 1','Origin: '.$base],$preview);
    $priced=request($base,'/api/v1/admin/pricing-preview',200,'POST',$headers,$preview);
    if($priced['total']!==1020000 || $priced['preview']!==true || $priced['inventoryChecked']!==false || $priced['voucherChecked']!==false)throw new RuntimeException('Pricing preview result');
    request($base,'/api/v1/admin/pricing-preview',400,'POST',$headers,array_merge($preview,['date'=>'2099-02-30']));
    request($base,'/api/v1/admin/pricing-preview',400,'POST',$headers,array_merge($preview,['quantities'=>['ADULT'=>'2']]));
    request($base,'/api/v1/admin/pricing-preview',400,'POST',$headers,array_merge($preview,['total'=>1]));
    request($base,'/api/v1/admin/pricing-preview',404,'POST',$headers,array_merge($preview,['productId'=>'missing']));
    request($base,'/api/v1/admin/pricing-preview',405,'GET',[$cookie]);
    request($base,'/api/v1/quotes',404,'POST',[],$preview);
    request($base,'/api/v1/admin/site-seo',401);
    request($base,'/api/v1/admin/brand',401);
    $seo=request($base,'/api/v1/admin/site-seo',200,'GET',[$cookie]);
    if($seo['version']!==0 || isset(request($base,'/api/v1/site/seo',200)['data']['version'])) throw new RuntimeException('SEO default/public projection');
    $seo['vi']['title']='Vé & trải nghiệm';
    request($base,'/api/v1/admin/site-seo',403,'POST',[$cookie],$seo);
    $savedSeo=request($base,'/api/v1/admin/site-seo',200,'POST',$headers,$seo);
    if($savedSeo['version']!==1 || request($base,'/api/v1/site/seo',200)['data']['vi']['title']!=='Vé & trải nghiệm') throw new RuntimeException('SEO save/public');
    request($base,'/api/v1/admin/site-seo',409,'POST',$headers,$seo);
    request($base,'/api/v1/admin/site-seo',400,'POST',$headers,array_merge($savedSeo,['vi'=>['title'=>'','description'=>'Missing title']]));
    request($base,'/api/v1/admin/site-seo',400,'POST',$headers,array_merge($savedSeo,['extra'=>true]));
    request($base,'/api/v1/admin/site-seo',400,'POST',$headers,array_merge($savedSeo,['image'=>['id'=>'00000000-0000-4000-8000-000000000099','alt'=>'Missing image']]));
    request($base,'/api/v1/site/seo',405,'POST',$headers,$savedSeo);
    $brand=request($base,'/api/v1/admin/brand',200,'GET',[$cookie]);
    $brand['name']='Atlas & <Tours>';$brand['companyName']='Atlas Travel';$brand['email']='info@example.test';
    request($base,'/api/v1/admin/brand',403,'POST',[$cookie],$brand);
    $savedBrand=request($base,'/api/v1/admin/brand',200,'POST',$headers,$brand);
    request($base,'/api/v1/admin/brand',409,'POST',$headers,$brand);
    request($base,'/api/v1/admin/brand',400,'POST',$headers,array_merge($savedBrand,['email'=>'bad']));
    $publicBrand=request($base,'/api/v1/site/brand',200)['data'];
    if(isset($publicBrand['version']) || $publicBrand['name']!==$brand['name']) throw new RuntimeException('Brand projection');
    $brandHtml=file_get_contents($base.'/admin');
    if(!str_contains($brandHtml,'Atlas &amp; &lt;Tours&gt;') || str_contains($brandHtml,'Atlas & <Tours>')) throw new RuntimeException('Brand HTML escaping');
    request($base,'/api/v1/admin/pages',401);
    $page=['id'=>'00000000-0000-4000-8000-000000000020','slug'=>'gioi-thieu','kind'=>'PAGE','title'=>'Giới thiệu','contentHtml'=>'<p>JinMemo</p><script>bad()</script>','status'=>'PUBLISHED','version'=>0,'showInMenu'=>true,'showInFooter'=>false,'sortOrder'=>0];
    request($base,'/api/v1/admin/pages',403,'POST',[$cookie],$page);
    request($base,'/api/v1/admin/pages',200,'POST',$headers,$page);
    $view=request($base,'/api/v1/site/pages/gioi-thieu',200)['data'];
    if(str_contains($view['contentHtml'],'script') || isset($view['version'])) throw new RuntimeException('Public page projection');
    if(request($base,'/api/v1/site/navigation',200)['data'][0]['href']!=='/trang/gioi-thieu') throw new RuntimeException('Page navigation');
    request($base,'/api/v1/site/pages/gioi-thieu?lang=en',404);
    request($base,'/api/v1/site/pages',405,'POST',$headers,$page);
    request($base,'/api/v1/admin/contact-requests',401);
    $contactPage=array_merge($page,['id'=>'00000000-0000-4000-8000-000000000001','kind'=>'CONTACT','slug'=>'lien-he','title'=>'Liên hệ']);
    request($base,'/api/v1/admin/pages',200,'POST',$headers,$contactPage);
    $contact=['id'=>'00000000-0000-4000-8000-000000000030','name'=>'Khách liên hệ','email'=>'guest@example.test','phone'=>'0901234567','subject'=>'Tư vấn vé','message'=>'Cần tư vấn vé cho đoàn 30 người.','locale'=>'vi','website'=>''];
    $contactHeaders=['X-Jingo-Contact: 1','Origin: '.$base];
    request($base,'/api/v1/site/contact/requests',403,'POST',[],$contact);
    request($base,'/api/v1/site/contact/requests',403,'POST',['X-Jingo-Contact: 1','Origin: https://attacker.test'],$contact);
    request($base,'/api/v1/site/contact/requests',400,'POST',$contactHeaders,array_merge($contact,['email'=>'bad']));
    request($base,'/api/v1/site/contact/requests',200,'POST',$contactHeaders,array_merge($contact,['website'=>'spam.test']));
    if(request($base,'/api/v1/admin/contact-requests',200,'GET',[$cookie])['data']!==[]) throw new RuntimeException('Honeypot stored');
    request($base,'/api/v1/site/contact/requests',404,'POST',$contactHeaders,array_merge($contact,['locale'=>'en']));
    request($base,'/api/v1/site/contact/requests',200,'POST',$contactHeaders,$contact);
    request($base,'/api/v1/site/contact/requests',200,'POST',$contactHeaders,$contact);
    request($base,'/api/v1/site/contact/requests',409,'POST',$contactHeaders,array_merge($contact,['message'=>'Nội dung khác nhưng cùng mã.']));
    request($base,'/api/v1/site/contact/requests',409,'POST',$contactHeaders,array_merge($contact,['phone'=>'901234567']));
    if(count(request($base,'/api/v1/admin/contact-requests',200,'GET',[$cookie])['data'])!==1) throw new RuntimeException('Duplicate contact');
    request($base,'/api/v1/admin/contact-requests/'.$contact['id'],403,'POST',[$cookie],['status'=>'DONE']);
    foreach(['DONE','TRASH','NEW','NEW'] as $status) {
        request($base,'/api/v1/admin/contact-requests/'.$contact['id'],200,'POST',$headers,['status'=>$status]);
        $inbox=request($base,'/api/v1/admin/contact-requests?status='.$status,200,'GET',[$cookie])['data'];
        if(count($inbox)!==1 || $inbox[0]['data']['message']!==$contact['message']) throw new RuntimeException('Contact status/inbox');
    }
    request($base,'/api/v1/admin/contact-requests?status=oops',400,'GET',[$cookie]);
    request($base,'/api/v1/admin/contact-requests/00000000-0000-4000-8000-000000000099',404,'POST',$headers,['status'=>'DONE']);
    foreach([31,32,33] as $n) {
        $next=array_merge($contact,['id'=>sprintf('00000000-0000-4000-8000-%012d',$n)]);
        request($base,'/api/v1/site/contact/requests',$n===33?429:200,'POST',$contactHeaders,$next);
    }
    $db->exec("UPDATE jingo_contact_limits SET until_at='2000-01-01 00:00:00'");
    request($base,'/api/v1/site/contact/requests',200,'POST',$contactHeaders,$next);
    // Global quota must survive subsequent PHP requests too.
    $db->prepare('UPDATE jingo_contact_limits SET attempts=100 WHERE id=?')->execute([hash('sha256','all')]);
    request($base,'/api/v1/site/contact/requests',429,'POST',$contactHeaders,array_merge($contact,['id'=>'00000000-0000-4000-8000-000000000034','email'=>'other@example.test']));
    request($base,'/api/v1/site/contact/requests',200,'POST',$contactHeaders,$contact); // replay does not consume quota
    $storedContact=request($base,'/api/v1/admin/pages',200,'GET',[$cookie])['data'];
    foreach($storedContact as $stored) if($stored['kind']==='CONTACT') { unset($stored['updatedAt']); $stored['status']='DRAFT'; request($base,'/api/v1/admin/pages',200,'POST',$headers,$stored); }
    request($base,'/api/v1/site/contact/requests',404,'POST',$contactHeaders,$contact);
    request($base,'/api/v1/site/contact-requests',404);
    request($base,'/api/v1/admin/media',401);
    file_put_contents($fixture,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6lEAAAAAASUVORK5CYII='));
    // Generate a valid fixture through GD in the server-independent test process.
    if(!extension_loaded('gd')) throw new RuntimeException('Run HTTP tests with php -d extension=gd.');
    $image=imagecreatetruecolor(2400,1200); imagefill($image,0,0,imagecolorallocate($image,40,90,150)); imagepng($image,$fixture); imagedestroy($image);
    $curl=curl_init($base.'/api/v1/admin/media');
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>['file'=>new CURLFile($fixture,'image/png','original.png'),'alt'=>'Ảnh thử']]);
    $raw=curl_exec($curl); $code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
    if($code!==200) throw new RuntimeException('Media upload HTTP '.$code.': '.$raw);
    $uploaded=json_decode($raw,true,512,JSON_THROW_ON_ERROR)['data'];
    if($uploaded['width']!==1920 || $uploaded['height']!==960 || isset($uploaded['created_by'])) throw new RuntimeException('Media metadata');
    foreach([$uploaded['url']=>'image/webp',$uploaded['url'].'/share.jpg'=>'image/jpeg'] as $url=>$mime) {
        $curl=curl_init($base.$url); curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]); $binary=curl_exec($curl);
        $type=curl_getinfo($curl,CURLINFO_CONTENT_TYPE); $code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
        if($code!==200 || $type!==$mime || !getimagesizefromstring($binary)) throw new RuntimeException('Media public file');
        if($mime==='image/jpeg') { $size=getimagesizefromstring($binary); if($size[0]!==1200 || $size[1]!==630) throw new RuntimeException('Share dimensions'); }
    }
    $saved=request($base,'/api/v1/admin/destinations',200,'GET',[$cookie])['data'][0];
    $saved['cover']=['id'=>$uploaded['id'],'alt'=>'Ảnh bìa']; request($base,'/api/v1/admin/destinations',200,'POST',$headers,$saved);
    $savedSeo['image']=['id'=>$uploaded['id'],'alt'=>'Ảnh chia sẻ'];request($base,'/api/v1/admin/site-seo',200,'POST',$headers,$savedSeo);
    $savedBrand['logo']=['id'=>$uploaded['id'],'alt'=>'Logo'];request($base,'/api/v1/admin/brand',200,'POST',$headers,$savedBrand);
    if(!str_contains(file_get_contents($base.'/admin'),'class="brand-logo"')) throw new RuntimeException('Brand logo rendering');
    $curl=curl_init($base.'/api/v1/admin/media'); file_put_contents($fixture,'not an image');
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>['file'=>new CURLFile($fixture,'image/png','fake.png')]]);
    curl_exec($curl); $code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl); if($code!==400) throw new RuntimeException('Spoofed image accepted');
    request($base,'/api/v1/media/not-a-uuid',404);
    request($base,'/api/v1/admin/logout',200,'POST',$headers);
    request($base,'/api/v1/admin/product-types',401,'GET',[$cookie]);
    echo "PASS: HTTP modules, SEO/brand defaults/save/version/validation/public projection/escaping/logo, contact quotas, media, session revocation.\n";
} finally {
    // PHP 8 curl_close does not release CurlHandle references; release uploads before unlink on Windows.
    unset($curl);
    if(is_resource($process)) { proc_terminate($process); foreach($pipes as $pipe) fclose($pipe); proc_close($process); }
    $db=null; $server->exec("DROP DATABASE `$name`");
    foreach(glob($mediaDir.'/*') as $file) if(is_file($file)) unlink($file);
    foreach(['.processing.lock','.share.lock'] as $lock) if(is_file($mediaDir.'/'.$lock)) unlink($mediaDir.'/'.$lock);
    for($i=0;$i<20;$i++) { if(@rmdir($mediaDir)) break; usleep(50000); }
    if(is_dir($mediaDir)) throw new RuntimeException('Test temporary directory could not be removed: '.$mediaDir);
}
