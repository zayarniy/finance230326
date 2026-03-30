<?php
// config/session.php

// Настройки безопасности
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 1800);

// Запрещаем кэширование
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

// Уникальное имя сессии
$session_name = 'finance_app';
session_name($session_name);

// Запускаем сессию
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Функция проверки авторизации
function isLoggedIn() {
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0;
}

// Функция выхода
function forceLogout() {
    $_SESSION = array();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

// Функция проверки авторизации с редиректом
function requireAuth() {
    if (!isLoggedIn()) {
        forceLogout();
    }
    // Обновляем время активности
    $_SESSION['last_activity'] = time();
}

// Проверка времени бездействия (только если пользователь залогинен)
if (isLoggedIn()) {
    $inactive_timeout = 1200; // 20 минут
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $inactive_timeout)) {
        forceLogout();
    }
}
?>