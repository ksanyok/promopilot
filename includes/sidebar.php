<?php
require_once __DIR__ . '/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Функция для получения проектов пользователя по его ID
function getProjectsByUserId($userId, $pdo) {
    $stmt = $pdo->prepare("SELECT id, name FROM projects WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll();
}

$userProjects = []; // Это массив проектов, полученный из БД
// Проверяем, авторизован ли пользователь
if (isset($_SESSION['user_id']) && $_SESSION['user_id']) {
    // Получаем проекты пользователя
    $userProjects = getProjectsByUserId($_SESSION['user_id'], $pdo);
}

// Если пользователь не авторизован, сайдбар не отображаем
if (!empty($_SESSION['user_id'])):
?>
<aside class="sidebar">
    <div class="sidebar-content">
        <a href="/add-project.php" class="add-project-button">
			<svg class="icon" fill="#ffffff" viewBox="0 0 24 24" width="16px" height="16px"> <!-- SVG иконка плюса -->
				<path d="M19,13h-6v6h-2v-6H5v-2h6V5h2v6h6V13z"/>
			</svg>
			Добавить проект
		</a>

        <hr class="sidebar-divider">
        <?php if (!empty($userProjects)): ?>
			<ul class="projects-list">
				<?php foreach ($userProjects as $userProject): ?>
				<li class="<?= (isset($_GET['id']) && $_GET['id'] == $userProject['id']) ? 'active' : ''; ?>">
					<a href="/project.php?id=<?= $userProject['id'] ?>">
						<svg class="project-icon" fill="#666" viewBox="0 0 24 24" width="16" height="16">
							<path d="M14,2H6C4.9,2,4,2.9,4,4v16c0,1.1,0.9,2,2,2h12c1.1,0,2-0.9,2-2V8l-6-6z M13,9V3.5L18.5,9H13z"/>
						</svg>
						<?= htmlspecialchars($userProject['name']) ?>
					</a>
					<?php if (isset($_GET['id']) && $_GET['id'] == $userProject['id']): ?>
						<ul class="project-history">
							<li><a href="/project-history.php?id=<?= $userProject['id'] ?>">История</a></li>
							<!-- Здесь можно добавить другие элементы истории проекта, если необходимо -->
						</ul>
					<?php endif; ?>
				</li>


				<?php endforeach; ?>
			</ul>

        <?php else: ?>
            <p>У вас пока нет проектов.</p>
        <?php endif; ?>
    </div>
</aside>
<?php endif; ?>