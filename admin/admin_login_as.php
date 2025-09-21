<?php
session_start();
include '../includes/functions.php';

if (!is_logged_in() || !is_admin()) {
    redirect('login.php');
}

$user_id = $_GET['user_id'] ?? 0;

if ($user_id) {
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
        $_SESSION['user_id'] = $user_id;
        $_SESSION['role'] = $user['role'];
        $conn->close();
        redirect('client.php');
    }
    $conn->close();
}

redirect('admin.php');
?>