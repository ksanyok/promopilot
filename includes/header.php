<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Подключаем автозагрузчик Composer
require_once __DIR__ . '/../vendor/autoload.php';
// Подключаем файл с настройками базы данных
require_once __DIR__ . '/db.php';
// Подключаем auth для функции env_load_array и универсальной session_start защиты
require_once __DIR__ . '/auth.php';

// Конфигурация для Google Client из .env / окружения с безопасным фолбэком
$env = env_load_array(__DIR__ . '/../.env');
$envClientId = $env['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID') ?: '';
$envClientSecret = $env['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET') ?: '';
$envRedirect = $env['GOOGLE_REDIRECT_URI'] ?? getenv('GOOGLE_REDIRECT_URI') ?: '';

$googleAuthAvailable = $envClientId !== '' && $envClientSecret !== '' && $envRedirect !== '' && filter_var($envRedirect, FILTER_VALIDATE_URL);

$loginUrl = null;
if ($googleAuthAvailable) {
    $client = new Google\Client();
    $client->setClientId($envClientId);
    $client->setClientSecret($envClientSecret);
    $client->setRedirectUri($envRedirect);
    $client->addScope('email');
    $client->addScope('profile');

    // Если в URL есть код Google OAuth 2.0
    if (isset($_GET['code'])) {
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        $client->setAccessToken($token);

        // Проверяем токен на ошибки
        if (isset($token['error']) && $token['error']) {
            echo "Ошибка при получении access token: " . $token['error'];
            exit;
        }

        // Создаем объект Google OAuth 2.0 service
        $oauth = new Google\Service\Oauth2($client);
        $userInfo = $oauth->userinfo->get();

        if (isset($userInfo) && $userInfo) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = :google_id");
            $stmt->execute(['google_id' => $userInfo->id]);
            $existingUser = $stmt->fetch();

            if ($existingUser) {
                $_SESSION['user_id'] = $existingUser['id'];
                $_SESSION['access_token'] = $client->getAccessToken();
            } else {
                $stmt = $pdo->prepare("INSERT INTO users (google_id, username, balance, created_at) VALUES (:google_id, :username, '0.00', NOW())");
                $stmt->execute([
                    'google_id' => $userInfo->id,
                    'username' => $userInfo->name
                ]);
                $newUserId = $pdo->lastInsertId();
                $_SESSION['user_id'] = $newUserId;
                $_SESSION['access_token'] = $client->getAccessToken();
            }

            header('Location: /');
            exit;
        }

        if (isset($_GET['logout'])) {
            unset($_SESSION['access_token']);
            unset($_SESSION['user_id']);
            header('Location: /');
            exit;
        }
    }

    if (isset($_SESSION['access_token']) && $_SESSION['access_token']) {
        $client->setAccessToken($_SESSION['access_token']);
    }

    if ($client->getAccessToken() && !$client->isAccessTokenExpired()) {
        $oauth = new Google\Service\Oauth2($client);
        $userInfo = $oauth->userinfo->get();
    }

    $loginUrl = $client->createAuthUrl();
}

$userBalance = 0.00;
if (isset($_SESSION['user_id']) && $_SESSION['user_id']) {
    $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = :user_id");
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $userBalance = $stmt->fetchColumn();
}
?>
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
                <?php if ($googleAuthAvailable && !empty($loginUrl)): ?>
                    <a href="<?= htmlspecialchars($loginUrl) ?>" class="login-button">Вход через Google</a>
                <?php else: ?>
                    <a href="/login.php" class="login-button">Войти</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</header>

