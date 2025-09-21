<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || !is_admin()) {
    redirect('auth/login.php');
}

$user_id = (int)($_GET['user_id'] ?? 0);
$token = $_GET['t'] ?? '';

if (!$user_id || !verify_action_token($token, 'login_as', (string)$user_id)) {
    redirect('admin/admin.php');
}

$conn = connect_db();
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 1) {
    $user = $result->fetch_assoc();
    if (!isset($_SESSION['admin_user_id'])) {
        $_SESSION['admin_user_id'] = $_SESSION['user_id'];
    }
    pp_session_regenerate();
    $_SESSION['user_id'] = $user_id;
    $_SESSION['role'] = $user['role'];
    $conn->close();
    redirect('client/client.php');
}
$conn->close();

redirect('admin/admin.php');
?>