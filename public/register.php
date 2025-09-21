<?php
session_start();
include '../includes/functions.php';

if (is_logged_in()) {
    redirect('../index.php');
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $confirm = $_POST['confirm'];

    if ($password !== $confirm) {
        $message = 'Пароли не совпадают.';
    } else {
        $conn = connect_db();
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $message = 'Пользователь с таким логином уже существует.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'client')");
            $stmt->bind_param("ss", $username, $hashed);
            if ($stmt->execute()) {
                $message = 'Регистрация успешна! <a href="login.php">Войти</a>';
            } else {
                $message = 'Ошибка регистрации.';
            }
        }
        $conn->close();
    }
}
?>

<?php include '../includes/header.php'; ?>
<div class="row justify-content-center">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h4>Регистрация</h4>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-info"><?php echo $message; ?></div>
                <?php endif; ?>
                <form method="post">
                    <div class="mb-3">
                        <label>Логин:</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Пароль:</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Подтвердите пароль:</label>
                        <input type="password" name="confirm" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-success">Зарегистрироваться</button>
                </form>
                <p class="mt-3">Уже есть аккаунт? <a href="login.php">Войти</a></p>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>