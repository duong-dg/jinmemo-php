<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/src/AdminAuth.php'; require dirname(__DIR__).'/src/Media.php'; require dirname(__DIR__).'/src/ContentHtml.php'; require dirname(__DIR__).'/src/Pages.php';
$dsn=getenv('JINGO_TEST_DSN'); if(!$dsn) { fwrite(STDERR,"Set JINGO_TEST_DSN without dbname.\n"); exit(1); }
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]; $server=new PDO($dsn,getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',$opts);
$name='jingo_pages_test_'.bin2hex(random_bytes(6)); $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4"); $db=null;
function pageCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function pageStatus(int $status,callable $fn): void { try{$fn();}catch(HttpError $e){pageCheck($e->status===$status,'Unexpected HTTP '.$e->status); return;} throw new RuntimeException('Expected HTTP '.$status); }
function payload(array $p): array { unset($p['updatedAt']); return $p; }
try {
    $db=new PDO($dsn.';dbname='.$name,getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',$opts); $db->exec("SET time_zone='+00:00'");
    foreach(glob(dirname(__DIR__).'/database/*.sql') as $f) foreach(explode(';',file_get_contents($f)) as $sql) if(trim($sql)!=='') $db->exec($sql);
    $service=new Pages($db);
    $input=['id'=>'00000000-0000-4000-8000-000000000010','slug'=>'chinh-sach','kind'=>'PAGE','title'=>'Chính sách','contentHtml'=>'<h2>Thông tin</h2><p onclick="bad()">An toàn.</p><script>bad()</script><a href="jav&#x61;script:alert(1)">Link</a><img src="https://bad.test/a.png"><svg onload="bad()"></svg>','status'=>'DRAFT','version'=>0,'showInMenu'=>true,'showInFooter'=>true,'sortOrder'=>10,'english'=>['title'=>'Policy','slug'=>'policy','contentHtml'=>'<p>Information.</p>','published'=>true]];
    $p=$service->save($input,'admin'); pageCheck(!preg_match('/script|onclick|svg|bad\.test/',$p['contentHtml']),'Unsafe HTML');
    pageStatus(404,fn()=>$service->find('chinh-sach')); pageCheck($service->navigation()===[],'Draft navigation');
    $p=$service->save(array_merge(payload($p),['status'=>'PUBLISHED']),'admin');
    pageCheck($service->find('policy','en')['title']==='Policy','English publish');
    pageCheck($service->navigation('en')[0]['href']==='/en/pages/policy','English menu');
    pageStatus(409,fn()=>$service->save($input,'admin'));
    $p=$service->save(array_merge(payload($p),['slug'=>'chinh-sach-moi','english'=>array_merge($p['english'],['slug'=>'policy-new'])]),'admin');
    pageCheck($service->find('chinh-sach')['slug']==='chinh-sach-moi','Alias VI'); pageCheck($service->find('policy','en')['slug']==='policy-new','Alias EN');
    $other=$input; $other['id']='00000000-0000-4000-8000-000000000011'; $other['english']=null; pageStatus(409,fn()=>$service->save($other,'admin'));
    $p=$service->save(array_merge(payload($p),['english'=>array_merge($p['english'],['published'=>false])]),'admin'); pageStatus(404,fn()=>$service->find('policy-new','en'));
    $p=$service->save(array_merge(payload($p),['status'=>'TRASH']),'admin'); pageCheck($service->published()===[],'Trash leak');
    $p=$service->save(array_merge(payload($p),['status'=>'DRAFT']),'admin'); pageStatus(404,fn()=>$service->find('chinh-sach-moi'));
    pageStatus(400,fn()=>$service->save(array_merge(payload($p),['status'=>'PUBLISHED','contentHtml'=>'<script>bad()</script>']),'admin'));
    $contact=array_merge($input,['id'=>Pages::CONTACT_ID,'kind'=>'CONTACT','slug'=>'lien-he','title'=>'Liên hệ','english'=>null,'status'=>'PUBLISHED','contentHtml'=>'<p>Liên hệ JinMemo.</p>']);
    $contact=$service->save($contact,'admin'); pageCheck($service->find('','vi',true)['localePaths']['vi']==='/lien-he','Contact route');
    pageStatus(400,fn()=>$service->save(array_merge(payload($contact),['status'=>'TRASH']),'admin'));
    $bad=['phone'=>'','email'=>'','address'=>'','hours'=>'','mapUrl'=>'javascript:alert(1)']; pageStatus(400,fn()=>$service->save(array_merge(payload($contact),['contact'=>$bad]),'admin'));
    pageStatus(400,fn()=>$service->save(array_merge(payload($p),['cover'=>['id'=>'00000000-0000-4000-8000-000000000099','alt'=>'Ảnh']]),'admin'));
    foreach(['<a href="java&#10;script:alert(1)" onmouseover="x()">x</a>','<math><mtext><img src=x onerror=alert(1)></mtext></math>','<iframe srcdoc="<script>x()</script>"></iframe>'] as $html) pageCheck(!preg_match('/javascript|onerror|onmouseover|iframe|<script/i',ContentHtml::clean($html)),'Sanitizer regression');
    echo "PASS: pages sanitization, publication, independent English, menus, aliases, versions, trash/restore, contact restrictions, media references.\n";
} finally { $db=null; $server->exec("DROP DATABASE `$name`"); }
