<?php
session_start();
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['lang'])) {
    $_SESSION['lang'] = $_POST['lang'];
}
header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
exit;
?>