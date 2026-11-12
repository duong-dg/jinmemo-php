<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
try {
    $email = strtolower(trim($argv[1] ?? ''));
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254) throw new RuntimeException('Usage: php bin/create-admin.php admin@example.com (password from stdin)');
    fwrite(STDERR,"Password (12-1024 characters; stdin may echo in your terminal): ");
    $password = rtrim((string)fgets(STDIN,1027),"\r\n");
    if (strlen($password)<12 || strlen($password)>1024) throw new RuntimeException('Password must contain 12-1024 characters.');
    $db = jingoDatabase(jingoConfig());
    $hash = password_hash($password,PASSWORD_BCRYPT,['cost'=>12]);
    if (strlen($password)>72) throw new RuntimeException('This bcrypt installation supports passwords up to 72 bytes.');
    $statement = $db->prepare('INSERT INTO jingo_admins(id,email,password_hash) VALUES (?,?,?)');
    $statement->execute([bin2hex(random_bytes(16)),$email,$hash]);
    echo "Admin created. Existing accounts are never overwritten.\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
