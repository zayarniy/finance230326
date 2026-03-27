<?php
// config/session.php
session_start();

// Настройки безопасности сессии
if (session_status() === PHP_SESSION_NONE) {
    // Устанавливаем параметры сессии
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', 0); // Установите 1 если используете HTTPS
    ini_set('session.cookie_samesite', 'Lax');
    
    session_start();
}

// Проверка валидности сессии
if (isset($_SESSION['user_id'])) {
    // Проверяем, что сессия принадлежит правильному пользователю
    if (!isset($_SESSION['session_created'])) {
        $_SESSION['session_created'] = time();
    }
    
    // Автоматический выход через 24 часа бездействия
    $inactive = 86400/24; // 24 часа
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $inactive)) {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }
    $_SESSION['last_activity'] = time();
}
?>