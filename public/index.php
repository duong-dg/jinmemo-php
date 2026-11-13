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
require dirname(__DIR__) . '/src/SiteSettings.php';
require dirname(__DIR__) . '/src/Pricing.php';
require dirname(__DIR__) . '/src/PublicSite.php';
require dirname(__DIR__) . '/src/ProductSchema.php';
require dirname(__DIR__) . '/src/Products.php';
require dirname(__DIR__) . '/src/Inventory.php';
require dirname(__DIR__) . '/src/Quotes.php';
require dirname(__DIR__) . '/src/Vouchers.php';
require dirname(__DIR__) . '/src/Orders.php';
require dirname(__DIR__) . '/src/TicketFiles.php';
require dirname(__DIR__) . '/src/Posts.php';
require dirname(__DIR__) . '/src/PostCategories.php';
require dirname(__DIR__) . '/src/Translations.php';
require dirname(__DIR__) . '/src/PostReview.php';
require dirname(__DIR__) . '/src/PostInsights.php';
require dirname(__DIR__) . '/src/Overview.php';
require_once dirname(__DIR__) . '/src/SupplyLots.php';
require dirname(__DIR__) . '/src/SupplierPayments.php';
require dirname(__DIR__) . '/src/SupplyProfit.php';
require dirname(__DIR__) . '/src/SupplierStatements.php';
require dirname(__DIR__) . '/src/SupplyAlerts.php';
require dirname(__DIR__) . '/src/SourceHistory.php';
require dirname(__DIR__) . '/src/TicketStock.php';

try {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if(PHP_SAPI==='cli-server' && in_array($path,['/assets/admin.css','/assets/admin.js','/assets/products-admin.js','/assets/inventory-admin.js','/assets/vouchers-admin.js','/assets/site.css','/assets/content-page.css','/assets/contact.js','/assets/quote.js','/assets/booking.js','/assets/orders-admin.js','/assets/posts-admin.js','/assets/post-categories-admin.js','/assets/translations-admin.js','/assets/post-reader.js','/assets/post-preview.css','/assets/overview-admin.js','/assets/supply-admin.js','/assets/supplier-payments-admin.js','/assets/supplier-statements-admin.js','/assets/source-history-admin.js','/assets/ticket-stock-admin.js'],true)) return false;
    if(in_array($path,['/cam-nang','/cam-nang/','/en/blog','/en/blog/','/tra-cuu','/tra-cuu/','/en/orders','/en/orders/','/','/en','/en/','/lien-he','/lien-he/','/en/contact','/en/contact/'],true) || preg_match('#^/(?:cam-nang|en/blog|trang|en/pages|ve|en/ve)/[^/]+/?$#D',(string)$path)) {
        $publicRequest=true;
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET'){header('Allow: GET');throw new HttpError(405,'Phương thức không được hỗ trợ.');}
        $config=jingoConfig();(new PublicSite(jingoDatabase($config),$config))->render($path);
    }
    if($path==='/admin' || $path==='/admin/' || preg_match('#^/(jingo-[a-f0-9]{24})/?$#D',(string)$path,$entryMatch)) {
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET') { header('Allow: GET'); throw new HttpError(405,'Phương thức không được hỗ trợ.'); }
        $config=jingoConfig(); $entry=$entryMatch[1] ?? '';
        $branding=null;
        try { $branding=(new SiteSettings(jingoDatabase($config),$config))->get('brand',true); $config['APP_NAME']=$branding['name']; }
        catch(Throwable $error) { error_log('JinMemo branding unavailable: '.$error->getMessage()); }
        if($entry!=='') {
            $policy=(new AdminAuth(jingoDatabase($config),$config))->policy();
            if(empty($policy['entrySlug']) || !hash_equals($policy['entrySlug'],$entry)) throw new HttpError(404,'Không tìm thấy.');
        }
        header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY'); header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
        require dirname(__DIR__).'/views/admin.php'; exit;
    }
    if($path==='/api/v1/admin/orders/export'){
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET'){header('Allow: GET');throw new HttpError(405,'Phương thức không được hỗ trợ.');}$config=jingoConfig();$db=jingoDatabase($config);(new AdminAuth($db,$config))->me();$report=(new Orders($db))->export($_GET);$csv=Orders::csv($report);header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="jinmemo-orders.csv"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('X-JinMemo-Export-Count: '.count($report['rows']));echo $csv;exit;
    }
    if(preg_match('#^/api/v1/admin/orders/([a-f0-9-]{36})/files/([a-f0-9-]{36})/revoke$#D',(string)$path,$revokeFile)){
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='POST'){header('Allow: POST');throw new HttpError(405,'Phương thức không được hỗ trợ.');}$config=jingoConfig();$db=jingoDatabase($config);$auth=new AdminAuth($db,$config);$auth->mutation();$actor=$auth->me();$files=new TicketFiles($db,($config['MEDIA_DIR'] ?? dirname(__DIR__).'/storage/media').'/.tickets');jingoJson($files->revoke($revokeFile[1],$revokeFile[2],jingoRequestBody(),$actor['id']));
    }
    if(preg_match('#^/api/v1/admin/orders/([a-f0-9-]{36})/files(?:/([a-f0-9-]{36}))?$#D',(string)$path,$ticketMatch)){
        $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';$download=isset($ticketMatch[2]);if(!in_array($method,$download?['GET']:['GET','POST'],true)){header('Allow: '.($download?'GET':'GET, POST'));throw new HttpError(405,'Phương thức không được hỗ trợ.');}
        $config=jingoConfig();$db=jingoDatabase($config);$auth=new AdminAuth($db,$config);if($method==='POST')$auth->mutation();$actor=$auth->me();$files=new TicketFiles($db,($config['MEDIA_DIR'] ?? dirname(__DIR__).'/storage/media').'/.tickets');
        if($download)jingoTicketDownload($files->download($ticketMatch[1],$ticketMatch[2]));if($method==='GET')jingoJson(['data'=>$files->list($ticketMatch[1])]);
        if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''),'multipart/form-data;'))throw new HttpError(415,'Yêu cầu multipart/form-data.');if(array_diff(array_keys($_FILES),['file']))throw new HttpError(400,'Chỉ nhận một file vé.');$upload=$_FILES['file'] ?? null;if(!is_array($upload)||($upload['error'] ?? null)!==UPLOAD_ERR_OK||!is_string($upload['tmp_name'] ?? null)||!is_uploaded_file($upload['tmp_name']))throw new HttpError(400,'Chọn file tối đa 10 MB và kiểm tra giới hạn upload hosting.');
        jingoJson($files->upload($ticketMatch[1],$_POST,$upload['tmp_name'],(string)$upload['name'],$actor['id']));
    }
    if($path==='/api/v1/admin/orders'||preg_match('#^/api/v1/admin/orders/([a-f0-9-]{36})/actions$#D',(string)$path,$orderActionMatch)){
        $list=$path==='/api/v1/admin/orders';$expected=$list?'GET':'POST';if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!==$expected){header('Allow: '.$expected);throw new HttpError(405,'Phương thức không được hỗ trợ.');}
        $config=jingoConfig();$db=jingoDatabase($config);$auth=new AdminAuth($db,$config);if(!$list)$auth->mutation();$actor=$auth->me();$orders=new Orders($db);
        jingoJson($list?$orders->list($_GET):['data'=>$orders->act($orderActionMatch[1],jingoRequestBody(),$actor['id'])]);
    }
    if(in_array($path,['/api/v1/orders','/api/v1/orders/lookup','/api/v1/orders/lookup/files'],true)||preg_match('#^/api/v1/orders/lookup/files/([a-f0-9-]{36})$#D',(string)$path,$customerFileMatch)||preg_match('#^/api/v1/admin/orders/([a-f0-9-]{36})$#D',(string)$path,$orderMatch)) {
        $admin=isset($orderMatch[1]);$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
        if($method!==($admin?'GET':'POST')){header('Allow: '.($admin?'GET':'POST'));throw new HttpError(405,'Phương thức không được hỗ trợ.');}
        $config=jingoConfig();$db=jingoDatabase($config);$orders=new Orders($db);
        if($admin){(new AdminAuth($db,$config))->me();jingoJson(['data'=>$orders->get($orderMatch[1])]);}
        $parts=parse_url($config['PUBLIC_BASE_URL'] ?? '');$origin=$parts&&isset($parts['scheme'],$parts['host'])?$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:''):'';
        if($origin==='')throw new HttpError(503,'Chưa cấu hình URL website.');
        if(($_SERVER['HTTP_ORIGIN'] ?? '')!==$origin||($_SERVER['HTTP_X_JINGO_ORDER'] ?? '')!=='1')throw new HttpError(403,'Nguồn yêu cầu không hợp lệ.');
        $lookup=$path!=='/api/v1/orders';$key=hash('sha256',($lookup?'lookup:':'create:').($_SERVER['REMOTE_ADDR'] ?? 'unknown'));$max=$lookup?240:30;
        $s=$db->prepare('INSERT INTO jingo_order_limits(id,attempts,until_at) VALUES (?,1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND)) ON DUPLICATE KEY UPDATE attempts=IF(until_at<=UTC_TIMESTAMP(),1,attempts+1),until_at=IF(until_at<=UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND),until_at)');$s->execute([$key]);
        $s=$db->prepare('SELECT attempts FROM jingo_order_limits WHERE id=?');$s->execute([$key]);if((int)$s->fetchColumn()>$max){header('Retry-After: 60');throw new HttpError(429,'Quá nhiều yêu cầu. Thử lại sau một phút.');}
        if(random_int(1,100)===1)$db->exec('DELETE FROM jingo_order_limits WHERE until_at<UTC_TIMESTAMP() LIMIT 1000');
        if(str_starts_with($path,'/api/v1/orders/lookup/files')){$files=new TicketFiles($db,($config['MEDIA_DIR'] ?? dirname(__DIR__).'/storage/media').'/.tickets');$body=jingoRequestBody();if(isset($customerFileMatch[1]))jingoTicketDownload($files->download($files->customer($body)['id'],$customerFileMatch[1],true));jingoJson(['data'=>$files->customerList($body)]);}
        jingoJson($lookup?$orders->lookup(jingoRequestBody()):$orders->create(jingoRequestBody()));
    }
    if(preg_match('#^/api/v1/posts/([a-f0-9-]{36})/view$#D',(string)$path,$postView)){
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='POST'){header('Allow: POST');throw new HttpError(405,'Phương thức không được hỗ trợ.');}$config=jingoConfig();$insights=new PostInsights(jingoDatabase($config),$config);header('Cache-Control: private, no-store');jingoJson(['data'=>$insights->record($postView[1],$_GET,jingoRequestBody(),$_SERVER,$_COOKIE)]);
    }
    if($path==='/api/v1/admin/ticket-stock'||preg_match('#^/api/v1/admin/ticket-stock/([a-f0-9-]{36})/manage$#D',(string)$path,$stockManage)||preg_match('#^/api/v1/admin/ticket-stock/orders/([a-f0-9-]{36})(/deliver)?$#D',(string)$path,$stockOrder)){
        $list=$path==='/api/v1/admin/ticket-stock';$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';$write=isset($stockManage[1])||isset($stockOrder[2]);if(!in_array($method,$list?['GET','POST']:[$write?'POST':'GET'],true)){header('Allow: '.($list?'GET, POST':($write?'POST':'GET')));throw new HttpError(405,'Phương thức không được hỗ trợ.');}$config=jingoConfig();$db=jingoDatabase($config);$auth=new AdminAuth($db,$config);if($method==='POST')$auth->mutation();$actor=$auth->me();$stock=new TicketStock($db);if($list)jingoJson($method==='GET'?$stock->list($_GET):$stock->receive(jingoRequestBody(4194304),$actor['id']));if(isset($stockManage[1]))jingoJson($stock->manage($stockManage[1],jingoRequestBody(),$actor['id']));jingoJson($write?$stock->deliver($stockOrder[1],jingoRequestBody(),$actor['id']):$stock->choices($stockOrder[1]));
    }
    if(preg_match('#^/api/v1/admin/supply/lots/([a-f0-9-]{36})/history(/export)?$#D',(string)$path,$sourceHistory)){
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET'){header('Allow: GET');throw new HttpError(405,'Phương thức không được hỗ trợ.');}$config=jingoConfig();$db=jingoDatabase($config);(new AdminAuth($db,$config))->me();$export=isset($sourceHistory[2]);$report=(new SourceHistory($db))->get($sourceHistory[1],$_GET,$export);if(!$export)jingoJson($report);header('Cache-Control: private, no-store');header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="jinmemo-nguon-'.$report['source']['id'].'.csv"');header('X-Content-Type-Options: nosniff');header('X-JinMemo-Export-Count: '.$report['total']);echo SourceHistory::csv($report);exit;
    }
    if($path==='/api/v1/admin/supply/alerts'){
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET'){header('Allow: GET');throw new HttpError(405,'Phương thức không được hỗ trợ.');}$config=jingoConfig();$db=jingoDatabase($config);(new AdminAuth($db,$config))->me();jingoJson((new SupplyAlerts($db))->get($_GET));
    }
    if(preg_match('#^/api/v1/admin/supply/statements/([a-f0-9-]{36})(/export)?$#D',(string)$path,$supplierStatement)){
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET'){header('Allow: GET');throw new HttpError(405,'Phương thức không được hỗ trợ.');}$config=jingoConfig();$db=jingoDatabase($config);(new AdminAuth($db,$config))->me();$export=isset($supplierStatement[2]);$report=(new SupplierStatements($db))->build($supplierStatement[1],$_GET,$export);header('Cache-Control: private, no-store');if(!$export)jingoJson($report);header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="jinmemo-ncc-'.$report['supplier']['id'].'-'.$report['from'].'-'.$report['to'].'.csv"');header('X-Content-Type-Options: nosniff');header('X-JinMemo-Export-Count: '.$report['total']);echo SupplierStatements::csv($report);exit;
    }
    if($path==='/api/v1/admin/supply/profit'){
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET'){header('Allow: GET');throw new HttpError(405,'Phương thức không được hỗ trợ.');}$config=jingoConfig();$db=jingoDatabase($config);(new AdminAuth($db,$config))->me();header('Cache-Control: private, no-store');jingoJson((new SupplyProfit($db))->get($_GET));
    }
    if(in_array($path,['/api/v1/admin/supply/balances','/api/v1/admin/supply/payments'],true)){
        $write=$path==='/api/v1/admin/supply/payments';if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!==($write?'POST':'GET')){header('Allow: '.($write?'POST':'GET'));throw new HttpError(405,'Phương thức không được hỗ trợ.');}$config=jingoConfig();$db=jingoDatabase($config);$auth=new AdminAuth($db,$config);if($write)$auth->mutation();$actor=$auth->me();header('Cache-Control: private, no-store');$payments=new SupplierPayments($db);jingoJson($write?$payments->pay(jingoRequestBody(),$actor['id']):['data'=>$payments->balances()]);
    }
    if(preg_match('#^/api/v1/admin/supply/orders/([a-f0-9-]{36})(/allocate)?$#D',(string)$path,$supplyOrder)){
        $write=isset($supplyOrder[2]);if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!==($write?'POST':'GET')){header('Allow: '.($write?'POST':'GET'));throw new HttpError(405,'Phương thức không được hỗ trợ.');}$config=jingoConfig();$db=jingoDatabase($config);$auth=new AdminAuth($db,$config);if($write)$auth->mutation();$actor=$auth->me();header('Cache-Control: private, no-store');$supply=new SupplyAllocation($db);jingoJson($write?$supply->allocate($supplyOrder[1],jingoRequestBody(),$actor['id']):$supply->get($supplyOrder[1]));
    }
    if(in_array($path,['/api/v1/admin/supply/lots','/api/v1/admin/supply/lots/batch'],true)||preg_match('#^/api/v1/admin/supply/lots/([a-f0-9-]{36})/(manage|config)$#D',(string)$path,$supplyLot)){
        $list=$path==='/api/v1/admin/supply/lots';$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';if(!in_array($method,$list?['GET','POST']:['POST'],true)){header('Allow: '.($list?'GET, POST':'POST'));throw new HttpError(405,'Phương thức không được hỗ trợ.');}$config=jingoConfig();$db=jingoDatabase($config);$auth=new AdminAuth($db,$config);if($method==='POST')$auth->mutation();$actor=$auth->me();$supply=new SupplyLots($db);if($method==='GET')jingoJson(['data'=>$supply->list()]);$body=jingoRequestBody();jingoJson(isset($supplyLot[1])?$supply->manage($supplyLot[1],$body,$actor['id'],$supplyLot[2]==='config'):$supply->receive($body,$actor['id'],!$list));
    }
    if($path==='/api/v1/admin/overview'){
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET'){header('Allow: GET');throw new HttpError(405,'Phương thức không được hỗ trợ.');}$config=jingoConfig();$db=jingoDatabase($config);(new AdminAuth($db,$config))->me();header('Cache-Control: private, no-store');jingoJson((new Overview($db))->get($_GET));
    }
    if($path==='/api/v1/admin/translations'||preg_match('#^/api/v1/admin/posts/([a-f0-9-]{36})/(preview|compare)$#D',(string)$path,$reviewRoute)){
        $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';$translation=$path==='/api/v1/admin/translations';if(!in_array($method,$translation?['GET','POST']:['GET'],true)){header('Allow: '.($translation?'GET, POST':'GET'));throw new HttpError(405,'Phương thức không được hỗ trợ.');}$config=jingoConfig();$db=jingoDatabase($config);$auth=new AdminAuth($db,$config);if($method==='POST')$auth->mutation();$actor=$auth->me();header('Cache-Control: private, no-store');header('X-Robots-Tag: noindex, nofollow');if($translation){$service=new Translations($db);jingoJson(['data'=>$method==='POST'?$service->save(jingoRequestBody(1048576),$actor['id']):$service->list()]);}$review=new PostReview($db);jingoJson(['data'=>$reviewRoute[2]==='preview'?$review->preview($reviewRoute[1],$_GET):$review->compare($reviewRoute[1],$_GET)]);
    }
    if(in_array($path,['/api/v1/admin/posts/categories','/api/v1/admin/posts/categories/actions','/api/v1/admin/posts/categories/translation'],true)||preg_match('#^/api/v1/admin/posts/([a-f0-9-]{36})/restore$#D',(string)$path,$postRestore)){
        $listing=$path==='/api/v1/admin/posts/categories';$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';if(!in_array($method,$listing?['GET','POST']:['POST'],true)){header('Allow: '.($listing?'GET, POST':'POST'));throw new HttpError(405,'Phương thức không được hỗ trợ.');}$config=jingoConfig();$db=jingoDatabase($config);$auth=new AdminAuth($db,$config);if($method==='POST')$auth->mutation();$actor=$auth->me();if(isset($postRestore[1]))jingoJson(['data'=>(new Posts($db))->restore($postRestore[1],jingoRequestBody(),$actor['id'])]);$categories=new PostCategories($db);if($method==='GET')jingoJson(['data'=>$categories->list()]);$body=jingoRequestBody();$operation=$listing?'CREATE':($path==='/api/v1/admin/posts/categories/translation'?'TRANSLATE':($body['action'] ?? ''));if(!$listing&&$path!=='/api/v1/admin/posts/categories/translation'){if(!in_array($operation,['RENAME','DELETE'],true))throw new HttpError(400,'Thao tác chuyên mục không hợp lệ.');unset($body['action']);}jingoJson(['data'=>$categories->act($operation,$body,$actor['id'])]);
    }
    if($path==='/api/v1/posts'||preg_match('#^/api/v1/posts/([^/]+)$#D',(string)$path,$postSlug)||$path==='/api/v1/admin/posts'||preg_match('#^/api/v1/admin/posts/([a-f0-9-]{36})/revisions$#D',(string)$path,$postRevisions)){
        $admin=str_starts_with($path,'/api/v1/admin/');$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';$write=$path==='/api/v1/admin/posts';if(!in_array($method,$write?['GET','POST']:['GET'],true)){header('Allow: '.($write?'GET, POST':'GET'));throw new HttpError(405,'Phương thức không được hỗ trợ.');}$config=jingoConfig();$db=jingoDatabase($config);$posts=new Posts($db);if($admin){$auth=new AdminAuth($db,$config);if($method==='POST')$auth->mutation();$actor=$auth->me();if(isset($postRevisions[1]))jingoJson(['data'=>$posts->revisions($postRevisions[1])]);jingoJson(['data'=>$method==='POST'?$posts->save(jingoRequestBody(1048576),$actor['id']):$posts->list(true)]);}jingoJson(['data'=>isset($postSlug[1])?$posts->find(rawurldecode($postSlug[1]),($_GET['lang'] ?? '')==='en'?'en':'vi'):$posts->list(false,($_GET['lang'] ?? '')==='en'?'en':'vi')]);
    }
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
    if(in_array($path,['/api/v1/site/seo','/api/v1/site/brand','/api/v1/admin/site-seo','/api/v1/admin/brand'],true)) {
        $admin=str_starts_with($path,'/api/v1/admin/');$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
        if(!in_array($method,$admin?['GET','POST']:['GET'],true)){header('Allow: '.($admin?'GET, POST':'GET'));throw new HttpError(405,'Phương thức không được hỗ trợ.');}
        $config=jingoConfig();$db=jingoDatabase($config);$settings=new SiteSettings($db,$config);$key=str_ends_with($path,'brand')?'brand':'site-seo';
        if(!$admin)jingoJson(['data'=>$settings->get($key,true)]);
        $auth=new AdminAuth($db,$config);if($method==='POST')$auth->mutation();$actor=$auth->me();
        jingoJson($method==='POST'?$settings->save($key,jingoRequestBody(),$actor['id']):$settings->get($key));
    }
    if($path==='/api/v1/admin/pricing-preview') {
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='POST'){header('Allow: POST');throw new HttpError(405,'Phương thức không được hỗ trợ.');}
        $config=jingoConfig();$db=jingoDatabase($config);$auth=new AdminAuth($db,$config);$auth->mutation();$auth->me();$input=jingoRequestBody();
        if(array_diff(array_keys($input),['productId','variantId','date','quantities','slotId']) || !is_string($input['productId'] ?? null) || strlen($input['productId'])>80 || !is_string($input['variantId'] ?? null) || strlen($input['variantId'])>80 || !is_string($input['date'] ?? null) || !is_array($input['quantities'] ?? null) || (isset($input['slotId']) && !is_string($input['slotId'])))throw new HttpError(400,'Dữ liệu xem trước giá không hợp lệ.');
        $statement=$db->prepare('SELECT data FROM jingo_products WHERE id=?');$statement->execute([$input['productId']]);$raw=$statement->fetchColumn();if($raw===false)throw new HttpError(404,'Không tìm thấy sản phẩm.');$product=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        $quote=Pricing::online($product,$input['variantId'],$input['date'],$input['quantities'],$product['rules'] ?? [],$product['closures'] ?? [],$input['slotId'] ?? null);
        jingoJson(array_merge($quote,['preview'=>true,'inventoryChecked'=>false,'voucherChecked'=>false]));
    }
    if(preg_match('#^/api/v1/admin/products/([^/]+)/archive$#D',(string)$path,$archiveMatch)){
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='POST'){header('Allow: POST');throw new HttpError(405,'Phương thức không được hỗ trợ.');}$config=jingoConfig();$db=jingoDatabase($config);$auth=new AdminAuth($db,$config);$auth->mutation();$actor=$auth->me();jingoJson(['data'=>(new Products($db))->archive(rawurldecode($archiveMatch[1]),jingoRequestBody(),$actor['id'])]);
    }
    if($path==='/api/v1/admin/products'){
        $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');throw new HttpError(405,'Phương thức không được hỗ trợ.');}
        $config=jingoConfig();$db=jingoDatabase($config);$auth=new AdminAuth($db,$config);if($method==='POST')$auth->mutation();$actor=$auth->me();$products=new Products($db);jingoJson(['data'=>$method==='POST'?$products->save(jingoRequestBody(1048576),$actor['id']):$products->list(true)]);
    }
    if($path==='/api/v1/products'||preg_match('#^/api/v1/products/([^/]+)(/sale-calendar)?$#D',(string)$path,$productMatch)){
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET'){header('Allow: GET');throw new HttpError(405,'Phương thức không được hỗ trợ.');}
        $db=jingoDatabase(jingoConfig());$products=new Products($db);$lang=($_GET['lang'] ?? '')==='en'?'en':'vi';
        if(!empty($productMatch[2])){$p=$products->find(rawurldecode($productMatch[1]));$view=$products->publicView($p,$lang);jingoJson(['data'=>['variants'=>array_map(fn($v)=>['id'=>$v['id'],'timeSlots'=>$v['timeSlots'] ?? []],$p['variants']),'saleClosures'=>$view['saleClosures']]]);}
        if(isset($productMatch[1]))jingoJson(['data'=>$products->publicView($products->find(rawurldecode($productMatch[1]),true,false,$lang),$lang)]);
        $items=$products->list(false,$lang);foreach(['type','destination','q'] as $filter)if(isset($_GET[$filter])&&!is_string($_GET[$filter]))throw new HttpError(400,'Bộ lọc không hợp lệ.');
        if(!empty($_GET['type']))$items=array_values(array_filter($items,fn($p)=>$p['type']===$_GET['type']));
        if(!empty($_GET['destination'])){$ids=[$_GET['destination']=>true];$nodes=(new Destinations($db))->list();do{$changed=false;foreach($nodes as $node)if($node['parentId']&&isset($ids[$node['parentId']])&&!isset($ids[$node['id']])){$ids[$node['id']]=true;$changed=true;}}while($changed);$items=array_values(array_filter($items,fn($p)=>isset($ids[$p['destinationId'] ?? ''])));}
        if(!empty($_GET['q'])){$fold=static fn($s)=>strtolower(iconv('UTF-8','ASCII//TRANSLIT//IGNORE',str_replace(['đ','Đ'],['d','D'],$s)) ?: '');$needle=$fold(mb_substr($_GET['q'],0,200));$items=array_values(array_filter($items,fn($p)=>str_contains($fold($p['name'].' '.$p['destination']),$needle)));}jingoJson(['data'=>$items]);
    }
    if($path==='/api/v1/admin/inventory'||in_array($path,['/api/v1/admin/inventory/config','/api/v1/admin/inventory/capacity'],true)){
        $read=$path==='/api/v1/admin/inventory';$expected=$read?'GET':'POST';if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!==$expected){header('Allow: '.$expected);throw new HttpError(405,'Phương thức không được hỗ trợ.');}$config=jingoConfig();$db=jingoDatabase($config);$auth=new AdminAuth($db,$config);if(!$read)$auth->mutation();$actor=$auth->me();$inventory=new Inventory($db);
        if($read){foreach(['productId','from','to'] as $key)if(!is_string($_GET[$key] ?? null))throw new HttpError(400,'Chọn sản phẩm và khoảng ngày.');jingoJson($inventory->read($_GET['productId'],$_GET['from'],$_GET['to']));}
        jingoJson(str_ends_with($path,'config')?$inventory->configure(jingoRequestBody(),$actor['id']):$inventory->capacity(jingoRequestBody(),$actor['id']));
    }
    if($path==='/api/v1/quotes'){
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='POST'){header('Allow: POST');throw new HttpError(405,'Phương thức không được hỗ trợ.');}jingoJson((new Quotes(jingoDatabase(jingoConfig())))->quote(jingoRequestBody(),($_GET['lang'] ?? '')==='en'?'en':'vi'));
    }
    if(preg_match('#^/api/v1/products/([^/]+)/prices$#D',(string)$path,$priceMatch)){
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET'){header('Allow: GET');throw new HttpError(405,'Phương thức không được hỗ trợ.');}foreach(['variantId','date'] as $key)if(!is_string($_GET[$key] ?? null))throw new HttpError(400,'Chọn gói và ngày.');if(isset($_GET['slotId'])&&!is_string($_GET['slotId']))throw new HttpError(400,'Suất giờ không hợp lệ.');jingoJson((new Quotes(jingoDatabase(jingoConfig())))->prices(rawurldecode($priceMatch[1]),$_GET['variantId'],$_GET['date'],$_GET['slotId'] ?? null,($_GET['lang'] ?? '')==='en'?'en':'vi'));
    }
    if($path==='/api/v1/admin/vouchers'){
        $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');throw new HttpError(405,'Phương thức không được hỗ trợ.');}$config=jingoConfig();$db=jingoDatabase($config);$auth=new AdminAuth($db,$config);if($method==='POST')$auth->mutation();$actor=$auth->me();$vouchers=new Vouchers($db);jingoJson($method==='POST'?['data'=>$vouchers->save(jingoRequestBody(),$actor['id'])]:['data'=>$vouchers->list(),'checkoutEnabled'=>true,'usage'=>(object)$vouchers->usage()]);
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
    if($publicRequest ?? false)PublicSite::error($error->status);
    jingoJson(['message'=>$error->getMessage()],$error->status);
} catch (Throwable $error) {
    error_log('JinMemo PHP: ' . $error->getMessage());
    if($publicRequest ?? false)PublicSite::error(500);
    jingoJson(['message' => 'Không xử lý được yêu cầu.'], 500);
}
