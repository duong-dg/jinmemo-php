<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    if (!extension_loaded('pdo_mysql')) throw new RuntimeException('Missing pdo_mysql extension.');
    if (!extension_loaded('fileinfo')) throw new RuntimeException('Missing fileinfo extension for original ticket files.');
    $db = jingoDatabase(jingoConfig());
    $db->query('SELECT 1')->fetchColumn();
    echo "MySQL connection OK.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "MySQL check failed: " . $error->getMessage() . "\n");
    exit(1);
}
