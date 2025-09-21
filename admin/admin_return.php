<?php
session_start();
include '../includes/functions.php';

if (isset($_SESSION['admin_user_id'])) {
    $_SESSION['user_id'] = $_SESSION['admin_user_id'];
    $_SESSION['role'] = 'admin';
    unset($_SESSION['admin_user_id']);
    redirect('admin.php');
} else {
    redirect('client.php');
}
?>