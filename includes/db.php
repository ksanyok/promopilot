<?php
$envPath = dirname(__DIR__) . '/.env';
$env = [];
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) > 1 && $v[0] === '"' && substr($v, -1) === '"') {
            $v = stripcslashes(substr($v, 1, -1));
        }
        $env[$k] = $v;
    }
}

$host = $env['DB_HOST'] ?? 'topbit.mysql.tools';
$db   = $env['DB_NAME'] ?? 'topbit_web2';
$user = $env['DB_USER'] ?? 'topbit_web2';
$pass = $env['DB_PASS'] ?? 'v7L)u4L@v5';
$charset = $env['DB_CHARSET'] ?? 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
?>
