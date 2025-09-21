<?php
session_start();

// Удаляем данные пользователя из сессии
unset($_SESSION['access_token']);
unset($_SESSION['user_id']);

// Разрушаем сессию
session_destroy();

// Перенаправляем на главную страницу
header('Location: /');
exit;
?>
