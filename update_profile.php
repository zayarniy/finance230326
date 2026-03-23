<?php
// update_profile.php - Обновление профиля пользователя
require_once 'config.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Не авторизован']);
    exit();
}

header('Content-Type: application/json');

$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($username) || empty($email)) {
    echo json_encode(['error' => 'Все поля обязательны для заполнения']);
    exit();
}

try {
    // Проверка уникальности username и email
    $stmt = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
    $stmt->execute([$username, $email, $_SESSION['user_id']]);
    if ($stmt->fetch()) {
        echo json_encode(['error' => 'Пользователь с таким именем или email уже существует']);
        exit();
    }
    
    // Обновление данных
    if (!empty($password)) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, password_hash = ? WHERE id = ?");
        $result = $stmt->execute([$username, $email, $password_hash, $_SESSION['user_id']]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
        $result = $stmt->execute([$username, $email, $_SESSION['user_id']]);
    }
    
    if ($result) {
        $_SESSION['username'] = $username;
        $_SESSION['email'] = $email;
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Ошибка при обновлении профиля']);
    }
} catch (PDOException $e) {
    echo json_encode(['error' => 'Ошибка базы данных: ' . $e->getMessage()]);
}
?>