<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || !is_admin()) {
    redirect('auth/login.php');
}

$user_id = (int)($_GET['user_id'] ?? 0);
$token = $_GET['t'] ?? '';
$redirectRaw = (string)($_GET['r'] ?? '');
$redirectSafe = '';

if ($redirectRaw !== '') {
    $parsed = @parse_url($redirectRaw);
    if (is_array($parsed) && !isset($parsed['scheme']) && !isset($parsed['host'])) {
        $path = ltrim((string)($parsed['path'] ?? ''), '/');
        $hasTraversal = strpos($path, '..') !== false || strpos($path, '\\') !== false;
        if ($path !== '' && !$hasTraversal) {
            $redirectSafe = $path;
            if (!empty($parsed['query'])) {
                $redirectSafe .= '?' . $parsed['query'];
            }
            if (!empty($parsed['fragment'])) {
                $redirectSafe .= '#' . $parsed['fragment'];
            }
        }
    }
}

if (!$user_id || !verify_action_token($token, 'login_as', (string)$user_id)) {
    redirect('admin/admin.php');
}

$conn = connect_db();
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($role);
    if ($stmt->fetch()) {
        if (!isset($_SESSION['admin_user_id'])) {
            $_SESSION['admin_user_id'] = $_SESSION['user_id'];
        }
        $stmt->close();
        $conn->close();
        pp_session_regenerate();
        $_SESSION['user_id'] = $user_id;
        $_SESSION['role'] = $role;
        session_write_close();
        $target = $role === 'admin' ? 'admin/admin.php' : 'client/client.php';
        if ($redirectSafe !== '') {
            $redirectLower = strtolower($redirectSafe);
            $canRedirect = false;
            if ($role === 'admin') {
                $canRedirect = (
                    strpos($redirectLower, 'admin/') === 0 ||
                    strpos($redirectLower, 'client/') === 0 ||
                    strpos($redirectLower, 'public/') === 0
                );
            } else {
                $canRedirect = strpos($redirectLower, 'client/') === 0;
            }
            if ($canRedirect) {
                $target = $redirectSafe;
            }
        }
        redirect($target);
    }
    $stmt->close();
}
$conn->close();

redirect('admin/admin.php');
?>