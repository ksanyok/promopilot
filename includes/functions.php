<?php
$current_lang = $_SESSION['lang'] ?? 'ru';

if ($current_lang != 'ru') {
    include '../lang/' . $current_lang . '.php';
}

// Общие функции для PromoPilot

function connect_db() {
    include '../config/config.php';
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        die("Ошибка подключения к БД: " . $conn->connect_error);
    }
    return $conn;
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function is_admin() {
    return isset($_SESSION['role']) && $_SESSION['role'] == 'admin';
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function check_version() {
    // Простая проверка версии из GitHub
    $url = 'https://api.github.com/repos/ksanyok/promopilot/releases/latest';
    $context = stream_context_create([
        'http' => [
            'header' => 'User-Agent: PHP'
        ]
    ]);
    $response = file_get_contents($url, false, $context);
    if ($response) {
        $data = json_decode($response, true);
        $latest = $data['tag_name'] ?? '1.0.0';
        include '../config/version.php';
        return version_compare($latest, $version, '>');
    }
    return false;
}

// Функция перевода
function __($key) {
    global $current_lang;
    if ($current_lang == 'ru') {
        return $key;
    }
    global $lang;
    return $lang[$key] ?? $key;
}
?>