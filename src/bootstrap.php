<?php
declare(strict_types=1);

function jingoConfig(): array
{
    $values = [];
    $path = dirname(__DIR__) . '/.env';
    if (is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            $pair = explode('=', $line, 2);
            if (count($pair) !== 2) throw new RuntimeException('Invalid configuration line.');
            $values[trim($pair[0])] = trim(trim($pair[1]), "\"'");
        }
    }
    foreach (['APP_ENV', 'APP_NAME', 'MEDIA_DIR', 'PUBLIC_BASE_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
        $value = getenv($key);
        if ($value !== false) $values[$key] = $value;
    }
    return $values;
}

function jingoRequestBody(int $limit=65536): array
{
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') throw new HttpError(415,'Yêu cầu JSON.');
    $stream=fopen('php://input','rb'); $raw=stream_get_contents($stream,$limit+1); fclose($stream);
    if (strlen($raw)>$limit) throw new HttpError(413,'Yêu cầu quá lớn.');
    try { $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR); } catch (JsonException) { throw new HttpError(400,'JSON không hợp lệ.'); }
    if (!is_array($input) || !str_starts_with(ltrim($raw),'{')) throw new HttpError(400,'Yêu cầu đối tượng JSON.');
    return $input;
}

function jingoDatabase(array $config): PDO
{
    foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME'] as $key) {
        if (empty($config[$key])) throw new RuntimeException('Database configuration is incomplete.');
    }
    $port = filter_var($config['DB_PORT'] ?? '3306', FILTER_VALIDATE_INT);
    if ($port === false || $port < 1 || $port > 65535) throw new RuntimeException('Invalid database port.');
    foreach (['DB_HOST', 'DB_DATABASE'] as $key) {
        if (preg_match('/[;\r\n]/', $config[$key])) throw new RuntimeException('Invalid database configuration.');
    }
    $db = new PDO(
        'mysql:host=' . $config['DB_HOST'] . ';port=' . $port . ';dbname=' . $config['DB_DATABASE'] . ';charset=utf8mb4',
        $config['DB_USERNAME'], $config['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $db->exec("SET time_zone = '+00:00'");
    return $db;
}

function jingoJson(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
