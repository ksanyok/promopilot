<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || !is_admin()) { redirect('auth/login.php'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('admin/referral.php'); }
if (!verify_csrf()) { redirect('admin/referral.php?m=' . urlencode(__('Ошибка сохранения (CSRF).')) . '&t=error'); }

$userId = (int)($_POST['user_id'] ?? 0);
$commissionRaw = trim((string)($_POST['referral_commission_percent'] ?? ''));
$refCode = trim((string)($_POST['referral_code'] ?? ''));

if ($userId <= 0) { redirect('admin/referral.php?m=' . urlencode(__('Укажите корректный ID пользователя.')) . '&t=error'); }

try { $conn = connect_db(); } catch (Throwable $e) { $conn = null; }
if (!$conn) { redirect('admin/referral.php?m=' . urlencode(__('База данных недоступна.')) . '&t=error'); }

// Validate user
$stmt = $conn->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
if (!$stmt) { $conn->close(); redirect('admin/referral.php?m=' . urlencode(__('Ошибка БД.')) . '&t=error'); }
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
$exists = $res && $res->num_rows > 0;
$stmt->close();
if (!$exists) { $conn->close(); redirect('admin/referral.php?m=' . urlencode(__('Пользователь не найден.')) . '&t=error'); }

$commission = null;
if ($commissionRaw !== '') {
    $commission = max(0, min(100, round((float)str_replace(',', '.', $commissionRaw), 2)));
}

if ($refCode === '') {
    // generate simple code: user<id>-<random>
    $rand = bin2hex(random_bytes(3));
    $refCode = 'u' . $userId . '-' . $rand;
}

// Ensure unique referral_code (best-effort)
for ($i = 0; $i < 3; $i++) {
    $stmt = $conn->prepare('UPDATE users SET referral_code = ? WHERE id = ?');
    if (!$stmt) { break; }
    $stmt->bind_param('si', $refCode, $userId);
    if ($stmt->execute()) { $stmt->close(); break; }
    $stmt->close();
    $refCode .= '-' . substr(bin2hex(random_bytes(1)), 0, 2);
}

if ($commission === null) {
    // Reset to default by setting 0; resolve via settings later
    $stmt = $conn->prepare('UPDATE users SET referral_commission_percent = 0 WHERE id = ?');
    if ($stmt) { $stmt->bind_param('i', $userId); $stmt->execute(); $stmt->close(); }
    redirect('admin/referral.php?m=' . urlencode(__('Сброшена персональная комиссия; установлен реферальный код.')) . '&t=success');
} else {
    $stmt = $conn->prepare('UPDATE users SET referral_commission_percent = ? WHERE id = ?');
    if ($stmt) { $stmt->bind_param('di', $commission, $userId); $stmt->execute(); $stmt->close(); }
    redirect('admin/referral.php?m=' . urlencode(__('Персональная комиссия сохранена и установлен реферальный код.')) . '&t=success');
}

$conn->close();
redirect('admin/referral.php');
