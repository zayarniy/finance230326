<?php
// logout.php
session_name('finance_app');
session_start();

// Полная очистка сессии
$_SESSION = array();

// Удаление cookie сессии
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Уничтожение сессии
session_destroy();

// Дополнительно: удаляем все возможные cookie с сессией
if (isset($_COOKIE['PHPSESSID'])) {
    setcookie('PHPSESSID', '', time() - 3600, '/');
}

// Перенаправление на страницу входа
header('Location: login.php');
exit;
?>