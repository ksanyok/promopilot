<?php
require_once __DIR__ . '/../includes/init.php';

if (isset($_GET['lang'])) {
    $lang = strtolower(preg_replace('/[^a-z]/', '', $_GET['lang']));
    if (in_array($lang, ['ru','en'], true)) {
        $_SESSION['lang'] = $lang;
    }
}

$referer = $_SERVER['HTTP_REFERER'] ?? '';
if ($referer && strpos($referer, $_SERVER['HTTP_HOST']) !== false) {
    header('Location: ' . $referer);
} else {
    redirect('public/');
}
exit;
?>