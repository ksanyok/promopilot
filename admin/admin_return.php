<?php
require_once __DIR__ . '/../includes/init.php';

if (!isset($_SESSION['admin_user_id'])) {
    redirect('client/client.php');
}

$t = $_GET['t'] ?? '';
$adminId = (string)$_SESSION['admin_user_id'];
if (!verify_action_token($t, 'admin_return', $adminId)) {
    redirect('client/client.php');
}

$_SESSION['user_id'] = $_SESSION['admin_user_id'];
$_SESSION['role'] = 'admin';
unset($_SESSION['admin_user_id']);
pp_session_regenerate();
redirect('admin/admin.php');
?>