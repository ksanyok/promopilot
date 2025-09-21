<?php
session_start();
require_once __DIR__ . '/includes/db.php'; // Подключение к базе данных
require_once __DIR__ . '/includes/header.php'; // Подключение хедера

// Подключение автозагрузчика Composer
require_once __DIR__ . '/vendor/autoload.php';

// Конфигурация для Google Client
$client = new Google\Client();
$client->setClientId('718620451917-00ruh0u0kbg4kvd02di30b04h35eeh44.apps.googleusercontent.com'); // Замените на ваш Client ID
$client->setClientSecret('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'); // Замените на ваш Client Secret
$client->setRedirectUri('https://web2.tester-buyreadysite.website/login.php'); // Обновленный Redirect URI
$client->addScope('email');
$client->addScope('profile');

// Если в URL присутствует код от Google после перенаправления
if (isset($_GET['code'])) {
    // Обработка кода и получение access token
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    $_SESSION['access_token'] = $token;

    $client->setAccessToken($token);

    // Получение данных пользователя от Google
    $google_oauth = new Google\Service\Oauth2($client);
    $google_account_info = $google_oauth->userinfo->get();
    $email = $google_account_info->email;
    $name = $google_account_info->name;

    // Проверка и запись данных пользователя в БД и сессию (добавьте свою логику здесь)

    // Перенаправление на страницу проектов или на главную
    header('Location: /projects.php');
    exit;
}

// Если сессия уже содержит access token
if (!empty($_SESSION['access_token'])) {
    $client->setAccessToken($_SESSION['access_token']);
}

// Подготовка URL для входа через Google
$loginUrl = $client->createAuthUrl();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Вход на сайт</title>
    <link rel="stylesheet" href="/styles/main.css">
</head>
<body>
<div class="main-container">
    <div class="content">
        <h1>Вход на сайт</h1>
        <p>Для продолжения работы с сайтом, пожалуйста, выполните вход через ваш Google аккаунт.</p>
        <a href="<?= htmlspecialchars($loginUrl) ?>" class="login-button">Вход через Google</a>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

</body>
</html>
