<?php
session_start(); // Стартуем сессию для использования с авторизацией Google

// Подключаем автозагрузчик Composer
require_once __DIR__ . '/../vendor/autoload.php';
// Подключаем файл с настройками базы данных
require_once __DIR__ . '/db.php';

// Конфигурация для Google Client
$client = new Google\Client();
$client->setClientId('718620451917-00ruh0u0kbg4kvd02di30b04h35eeh44.apps.googleusercontent.com'); // Укажите ваш Client ID
$client->setClientSecret('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'); // Укажите ваш Client Secret
$client->setRedirectUri('https://web2.tester-buyreadysite.website/login.php'); // Укажите ваш Redirect URI
$client->addScope('email');
$client->addScope('profile');

// Если в URL есть код Google OAuth 2.0
if (isset($_GET['code'])) {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    $client->setAccessToken($token);

    // Проверяем токен на ошибки
    if (isset($token['error']) && $token['error']) {
        // Обработка ошибки
        echo "Ошибка при получении access token: " . $token['error'];
        exit;
    }

    // Создаем объект Google OAuth 2.0 service
    $oauth = new Google\Service\Oauth2($client);
    $userInfo = $oauth->userinfo->get();

    // После получения информации о пользователе от Google
    if (isset($userInfo) && $userInfo) {
        // Проверяем, есть ли пользователь в базе данных
        $stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = :google_id");
        $stmt->execute(['google_id' => $userInfo->id]);
        $existingUser = $stmt->fetch();

        if ($existingUser) {
            // Пользователь существует, устанавливаем данные в сессию
            $_SESSION['user_id'] = $existingUser['id'];
            $_SESSION['access_token'] = $client->getAccessToken();
        } else {
            // Пользователь не существует, добавляем в базу данных
            $stmt = $pdo->prepare("INSERT INTO users (google_id, username, balance, created_at) VALUES (:google_id, :username, '0.00', NOW())");
            $stmt->execute([
                'google_id' => $userInfo->id,
                'username' => $userInfo->name
            ]);
            // Получаем ID только что добавленного пользователя
            $newUserId = $pdo->lastInsertId();

            // Устанавливаем данные в сессию
            $_SESSION['user_id'] = $newUserId;
            $_SESSION['access_token'] = $client->getAccessToken();
        }

        // Перенаправляем на главную страницу
        header('Location: /');
        exit;
    }

    // Если пользователь нажал "Выход"
    if (isset($_GET['logout'])) {
        // Очищаем данные сессии
        unset($_SESSION['access_token']);
        unset($_SESSION['user_id']);
        // Перенаправляем на страницу входа
        header('Location: /');
        exit;
    }

    // Здесь вы можете добавить код для сохранения информации пользователя в вашу БД
    // ...
}

// Если сессия уже содержит данные авторизации
if (isset($_SESSION['access_token']) && $_SESSION['access_token']) {
    $client->setAccessToken($_SESSION['access_token']);
}

// Проверяем, авторизован ли пользователь и можно ли получить информацию о нем
if ($client->getAccessToken() && !$client->isAccessTokenExpired()) {
    $oauth = new Google\Service\Oauth2($client);
    $userInfo = $oauth->userinfo->get();
    // Здесь также можно использовать $userInfo для отображения данных пользователя
}

// Получаем информацию о балансе текущего пользователя, если он авторизован
$userBalance = 0.00; // Инициализируем баланс по умолчанию
if (isset($_SESSION['user_id']) && $_SESSION['user_id']) {
    $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = :user_id");
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $userBalance = $stmt->fetchColumn();
}

// Если пользователь не авторизован, подготовим URL для входа
$loginUrl = $client->createAuthUrl();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Продвижение страниц</title>
    <link rel="stylesheet" href="/styles/main.css">
</head>
<body>

<header>
    <div class="container">
        <div class="logo">
            <a href="/"><img src="/images/PromoPilot.png" alt="Логотип"></a>
        </div>
        
        <div class="auth-and-balance">
            <?php if (isset($userInfo) && isset($userInfo->name)): ?>
                <div class="balance">
                    <p>Баланс: $<?= htmlspecialchars($userBalance) ?></p>
                </div>
                <div class="user-info">
                    <div class="user-greeting">
                        <span class="greeting">Привет,</span>
                        <span class="user-name"><?= htmlspecialchars($userInfo->name) ?></span>
                    </div>
                    <div class="logout">
                        <a href="/logout.php" class="logout-button">Выход</a>
                    </div>
                </div>
            <?php else: ?>
                <a href="<?= htmlspecialchars($loginUrl) ?>" class="login-button">Вход через Google</a>
            <?php endif; ?>
        </div>
    </div>
</header>

