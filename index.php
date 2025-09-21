<?php
session_start();
include 'includes/functions.php';

if (!is_logged_in()) {
    redirect('public/login.php');
} else {
    if (is_admin()) {
        redirect('public/admin.php');
    } else {
        redirect('public/client.php');
    }
}
?>