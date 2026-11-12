<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/AdminAuth.php';
require dirname(__DIR__) . '/src/ProductTypes.php';
require dirname(__DIR__) . '/src/Destinations.php';
require dirname(__DIR__) . '/src/Media.php';
require dirname(__DIR__) . '/src/Suppliers.php';
require dirname(__DIR__) . '/src/ContentHtml.php';
require dirname(__DIR__) . '/src/Pages.php';
require dirname(__DIR__) . '/src/ContactRequests.php';

try {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($path === '/api/v1/health') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Allow: GET');
            jingoJson(['message' => 'Phương thức không được hỗ trợ.'], 405);
        }
        jingoJson(['status' => 'ok', 'application' => jingoConfig()['APP_NAME'] ?? 'JinMemo', 'stage' => 'migration']);
    }
    if ($path==='/api/v1/product-types' || $path==='/api/v1/admin/product-types' || preg_match('#^/api/v1/product-types/([^/]+)$#D',(string)$path,$match)) {
        $admin=$path==='/api/v1/admin/product-types'; $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method,$admin?['GET','POST']:['GET'],true)) { header('Allow: '.($admin?'GET, POST':'GET')); throw new HttpError(405,'Phương thức không được hỗ trợ.'); }
        $db=jingoDatabase(jingoConfig()); $types=new ProductTypes($db);
        if ($admin) {
            $auth=new AdminAuth($db,jingoConfig()); if ($method==='POST') $auth->mutation();
            $actor=$auth->me();
            jingoJson(['data'=>$method==='POST'?$types->save(jingoRequestBody(),$actor['id']):$types->list(true)]);
        }
        $lang=($_GET['lang'] ?? '')==='en'?'en':'vi';
        jingoJson(['data'=>isset($match[1])?$types->find(rawurldecode($match[1]),$lang):$types->list(false,$lang)]);
    }
    if ($path==='/api/v1/destinations' || $path==='/api/v1/admin/destinations' || preg_match('#^/api/v1/destinations/([^/]+)$#D',(string)$path,$destinationMatch)) {
        $admin=$path==='/api/v1/admin/destinations'; $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
        if(!in_array($method,$admin?['GET','POST']:['GET'],true)) { header('Allow: '.($admin?'GET, POST':'GET')); throw new HttpError(405,'Phương thức không được hỗ trợ.'); }
        $db=jingoDatabase(jingoConfig()); $destinations=new Destinations($db);
        if($admin) {
            $auth=new AdminAuth($db,jingoConfig()); if($method==='POST') $auth->mutation(); $actor=$auth->me();
            jingoJson(['data'=>$method==='POST'?$destinations->save(jingoRequestBody(),$actor['id']):$destinations->list(true)]);
        }
        $lang=($_GET['lang'] ?? '')==='en'?'en':'vi';
        jingoJson(['data'=>isset($destinationMatch[1])?$destinations->find(rawurldecode($destinationMatch[1]),$lang):array_map(fn($node)=>Destinations::publicView($node,$lang),$destinations->list())]);
    }
    if($path==='/api/v1/admin/media' || preg_match('#^/api/v1/media/([^/]+)(/share\.jpg)?$#D',(string)$path,$mediaMatch)) {
        $admin=$path==='/api/v1/admin/media'; $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
        if(!in_array($method,$admin?['GET','POST']:['GET'],true)) { header('Allow: '.($admin?'GET, POST':'GET')); throw new HttpError(405,'Phương thức không được hỗ trợ.'); }
        $config=jingoConfig(); $db=jingoDatabase($config); $media=new Media($db,$config['MEDIA_DIR'] ?? dirname(__DIR__).'/storage/media');
        if($admin) {
            $auth=new AdminAuth($db,$config); if($method==='POST') $auth->mutation(); $actor=$auth->me();
            if($method==='GET') jingoJson(['data'=>$media->list()]);
            if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''),'multipart/form-data;')) throw new HttpError(415,'Yêu cầu multipart/form-data.');
            if(array_diff(array_keys($_POST),['alt']) || array_diff(array_keys($_FILES),['file'])) throw new HttpError(400,'Chỉ nhận một ảnh và mô tả alt.');
            $file=$_FILES['file'] ?? null;
            if(!is_array($file) || !is_int($file['error'] ?? null)) throw new HttpError(400,'Chọn một file ảnh tối đa 5 MB.');
            if(in_array($file['error'],[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true)) throw new HttpError(413,'Ảnh vượt giới hạn upload hosting.');
            if($file['error']!==UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name']) || !is_string($file['name'] ?? null)) throw new HttpError(400,'File upload không hợp lệ.');
            jingoJson(['data'=>$media->upload($file['tmp_name'],$file['name'],$_POST['alt'] ?? '',$actor['id'])]);
        }
        $share=!empty($mediaMatch[2]); $id=rawurldecode($mediaMatch[1]); $file=$share?$media->shareFile($id):$media->file($id);
        $stream=fopen($file,'rb'); if(!$stream) throw new HttpError(404,'Không tìm thấy file ảnh.');
        header('Content-Type: '.($share?'image/jpeg':'image/webp')); header('X-Content-Type-Options: nosniff'); header('Cache-Control: public, max-age=31536000, immutable'); header('Content-Length: '.filesize($file));
        fpassthru($stream); fclose($stream); exit;
    }
    if($path==='/api/v1/admin/suppliers') {
        $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
        if(!in_array($method,['GET','POST'],true)) { header('Allow: GET, POST'); throw new HttpError(405,'Phương thức không được hỗ trợ.'); }
        $config=jingoConfig(); $db=jingoDatabase($config); $auth=new AdminAuth($db,$config);
        if($method==='POST') $auth->mutation(); $actor=$auth->me(); $suppliers=new Suppliers($db);
        jingoJson(['data'=>$method==='POST'?$suppliers->save(jingoRequestBody(),$actor['id']):$suppliers->list()]);
    }
    if($path==='/api/v1/admin/pages' || in_array($path,['/api/v1/site/pages','/api/v1/site/navigation','/api/v1/site/contact'],true) || preg_match('#^/api/v1/site/pages/([^/]+)$#D',(string)$path,$pageMatch)) {
        $admin=$path==='/api/v1/admin/pages'; $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
        if(!in_array($method,$admin?['GET','POST']:['GET'],true)) { header('Allow: '.($admin?'GET, POST':'GET')); throw new HttpError(405,'Phương thức không được hỗ trợ.'); }
        $config=jingoConfig(); $db=jingoDatabase($config); $pages=new Pages($db);
        if($admin) { $auth=new AdminAuth($db,$config); if($method==='POST') $auth->mutation(); $actor=$auth->me(); jingoJson(['data'=>$method==='POST'?$pages->save(jingoRequestBody(1048576),$actor['id']):$pages->list()]); }
        $lang=($_GET['lang'] ?? '')==='en'?'en':'vi';
        if($path==='/api/v1/site/navigation') jingoJson(['data'=>$pages->navigation($lang)]);
        if($path==='/api/v1/site/contact') jingoJson(['data'=>$pages->find('',$lang,true)]);
        jingoJson(['data'=>isset($pageMatch[1])?$pages->find(rawurldecode($pageMatch[1]),$lang):$pages->published($lang)]);
    }
    if($path==='/api/v1/site/contact/requests' || $path==='/api/v1/admin/contact-requests' || preg_match('#^/api/v1/admin/contact-requests/([^/]+)$#D',(string)$path,$contactMatch)) {
        $public=$path==='/api/v1/site/contact/requests'; $listing=$path==='/api/v1/admin/contact-requests'; $method=$_SERVER['REQUEST_METHOD'] ?? 'GET'; $expected=$listing?'GET':'POST';
        if($method!==$expected) { header('Allow: '.$expected); throw new HttpError(405,'Phương thức không được hỗ trợ.'); }
        $config=jingoConfig(); $db=jingoDatabase($config); $contacts=new ContactRequests($db,$config);
        if($public) jingoJson($contacts->submit(jingoRequestBody()));
        $auth=new AdminAuth($db,$config); if(!$listing) $auth->mutation(); $actor=$auth->me();
        jingoJson($listing?$contacts->list($_GET['status'] ?? 'NEW'):$contacts->update(rawurldecode($contactMatch[1]),jingoRequestBody(),$actor['id']));
    }
    $routes = ['login'=>'POST','me'=>'GET','logout'=>'POST','login-policy'=>'GET'];
    $action = str_starts_with((string)$path, '/api/v1/admin/') ? substr($path, strlen('/api/v1/admin/')) : '';
    if (str_starts_with($action,'login-entry/')) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { header('Allow: GET'); throw new HttpError(405,'Phương thức không được hỗ trợ.'); }
        $auth = new AdminAuth(jingoDatabase(jingoConfig()),jingoConfig());
        $policy = $auth->policy(); $slug = substr($action,strlen('login-entry/'));
        if (empty($policy['entrySlug']) || !hash_equals($policy['entrySlug'],$slug)) throw new HttpError(404,'Không tìm thấy.');
        jingoJson(['valid'=>true]);
    }
    if (isset($routes[$action])) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $routes[$action]) {
            header('Allow: '.$routes[$action]);
            jingoJson(['message'=>'Phương thức không được hỗ trợ.'],405);
        }
        $auth = new AdminAuth(jingoDatabase(jingoConfig()),jingoConfig());
        if ($action === 'login') {
            jingoJson($auth->login(jingoRequestBody()));
        }
        if ($action === 'me') jingoJson($auth->me());
        if ($action === 'logout') jingoJson($auth->logout());
        $policy = $auth->policy();
        jingoJson(['privateEntryEnabled'=>(bool)($policy['privateEntryEnabled'] ?? false),'otpEnabled'=>(bool)($policy['otpEnabled'] ?? false)]);
    }
    jingoJson(['message' => 'Chức năng chưa có trong bản PHP.'], 404);
} catch (HttpError $error) {
    jingoJson(['message'=>$error->getMessage()],$error->status);
} catch (Throwable $error) {
    error_log('JinMemo PHP: ' . $error->getMessage());
    jingoJson(['message' => 'Không xử lý được yêu cầu.'], 500);
}
