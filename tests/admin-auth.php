<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/AdminAuth.php';
if (PHP_SAPI !== 'cli') exit;
// Dedicated disposable database required. Never points at the application database.
$dsn = getenv('JINGO_TEST_DSN');
if (!$dsn) { fwrite(STDERR,"Set JINGO_TEST_DSN to a disposable MySQL server (without dbname).\n"); exit(1); }
$server = new PDO($dsn,getenv('JINGO_TEST_USER') ?: 'root',getenv('JINGO_TEST_PASSWORD') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name = 'jingo_auth_test_'.bin2hex(random_bytes(6));
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
$db = null;
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function expectStatus(int $status, callable $work): void {
    try { $work(); } catch (HttpError $e) { check($e->status===$status,'Expected '.$status.', received '.$e->status); return; }
    throw new RuntimeException('Expected HTTP '.$status);
}
try {
    $db = new PDO($dsn.';dbname='.$name,getenv('JINGO_TEST_USER') ?: 'root',getenv('JINGO_TEST_PASSWORD') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec("SET time_zone='+00:00'");
    $schema=file_get_contents(dirname(__DIR__).'/database/001-admin.sql');
    foreach(explode(';',$schema) as $sql) if(trim($sql)!=='') $db->exec($sql);
    // Installing again must preserve policy and data.
    foreach(explode(';',$schema) as $sql) if(trim($sql)!=='') $db->exec($sql);
    $password=bin2hex(random_bytes(12));
    $db->prepare('INSERT INTO jingo_admins VALUES (?,?,?)')->execute(['test-admin','admin@example.com',password_hash($password,PASSWORD_BCRYPT)]);
    $_SERVER['HTTP_X_JINGO_ADMIN']='1'; $_SERVER['REMOTE_ADDR']='127.0.0.1'; $_SERVER['HTTP_ORIGIN']='https://example.com';
    $auth=new AdminAuth($db,['PUBLIC_BASE_URL'=>'https://example.com','APP_ENV'=>'production']);
    expectStatus(401,fn()=>$auth->me());
    $_SERVER['HTTP_ORIGIN']='https://attacker.example'; expectStatus(403,fn()=>$auth->login(['email'=>'admin@example.com','password'=>$password])); $_SERVER['HTTP_ORIGIN']='https://example.com';
    expectStatus(400,fn()=>$auth->login(['email'=>[],'password'=>'x']));
    for($i=0;$i<5;$i++) expectStatus(401,fn()=>$auth->login(['email'=>'admin@example.com','password'=>'bad']));
    expectStatus(429,fn()=>$auth->login(['email'=>'admin@example.com','password'=>$password]));
    $db->exec("UPDATE jingo_admin_login_limits SET until_at='2000-01-01 00:00:00'");
    check($auth->login(['email'=>'admin@example.com','password'=>$password])['email']==='admin@example.com','Login failed');
    check((int)$db->query('SELECT COUNT(*) FROM jingo_sessions')->fetchColumn()===1,'Missing session');
    // Tokens are hashed; inject an explicit fixture to verify expiry/revocation.
    $token=bin2hex(random_bytes(32)); $_COOKIE['jingo_admin']=$token;
    $db->prepare('INSERT INTO jingo_sessions VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))')->execute([hash('sha256',$token),'test-admin']);
    check($auth->me()['id']==='test-admin','Session lookup failed');
    $auth->logout(); expectStatus(401,fn()=>$auth->me());
    $db->prepare('INSERT INTO jingo_sessions VALUES (?,?,?)')->execute([hash('sha256',$token),'test-admin','2000-01-01 00:00:00']);
    expectStatus(401,fn()=>$auth->me());
    $db->exec("UPDATE jingo_admin_security SET data='{\"otpEnabled\":true,\"privateEntryEnabled\":false}' WHERE id='policy'");
    $before=(int)$db->query('SELECT COUNT(*) FROM jingo_sessions')->fetchColumn();
    expectStatus(503,fn()=>$auth->login(['email'=>'admin@example.com','password'=>$password]));
    check((int)$db->query('SELECT COUNT(*) FROM jingo_sessions')->fetchColumn()===$before,'OTP bypass created a session');
    $db->exec("UPDATE jingo_admin_security SET data='{\"privateEntryEnabled\":true,\"entrySlug\":\"private-test\"}' WHERE id='policy'");
    expectStatus(404,fn()=>$auth->login(['email'=>'admin@example.com','password'=>$password]));
    $_SERVER['HTTP_X_JINGO_ADMIN_ENTRY']='private-test';
    $auth->login(['email'=>'admin@example.com','password'=>$password]);
    check((int)$db->query("SELECT COUNT(*) FROM jingo_admin_login_log WHERE event='SUCCESS'")->fetchColumn()===2,'Missing login audit');
    echo "PASS: validation, origin, throttling/reset, login, session, expiry, logout, OTP blocking, private entry, audit, repeatable schema.\n";
} finally {
    $db=null; $server->exec("DROP DATABASE `$name`");
}
