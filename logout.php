<?php
// logout.php
session_name('finance_app');
session_start();

require_once 'config/database.php';

// Очищаем токен "запомнить меня" в базе данных
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL, remember_token_expires = NULL WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
}

// Очистка сессии
$_SESSION = array();

// Удаление cookie сессии
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Удаление cookie "запомнить меня"
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
}

// Уничтожение сессии
session_destroy();

// Перенаправление на страницу входа
header('Location: login.php');
exit;
?>