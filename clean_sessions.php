<?php
// clean_sessions.php - запускать раз в час через cron

// Настройки
$session_save_path = session_save_path();
if (empty($session_save_path)) {
    $session_save_path = ini_get('session.save_path');
}

if (empty($session_save_path)) {
    $session_save_path = sys_get_temp_dir();
}

$max_lifetime = 1800; // 30 минут

// Удаляем старые файлы сессий
if (is_dir($session_save_path)) {
    $files = glob($session_save_path . '/sess_*');
    $now = time();
    
    foreach ($files as $file) {
        if (is_file($file) && ($now - filemtime($file) > $max_lifetime)) {
            unlink($file);
        }
    }
}

echo "Очистка сессий выполнена\n";
?>