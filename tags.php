<?php
// tags_module.php - Модуль управления метками
require_once 'config.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Не авторизован']);
    exit();
}

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_all':
            // Получение всех меток пользователя
            $stmt = $pdo->prepare("SELECT id, name, color FROM tags WHERE user_id = ? ORDER BY name");
            $stmt->execute([$user_id]);
            $tags = $stmt->fetchAll();
            echo json_encode(['success' => true, 'tags' => $tags]);
            break;
            
        case 'create':
            // Создание новой метки
            $name = trim($_POST['name'] ?? '');
            $color = trim($_POST['color'] ?? '#6c757d');
            
            if (empty($name)) {
                echo json_encode(['error' => 'Название метки обязательно']);
                break;
            }
            
            // Проверка на существование
            $stmt = $pdo->prepare("SELECT id FROM tags WHERE user_id = ? AND name = ?");
            $stmt->execute([$user_id, $name]);
            if ($stmt->fetch()) {
                echo json_encode(['error' => 'Метка с таким названием уже существует']);
                break;
            }
            
            $stmt = $pdo->prepare("INSERT INTO tags (user_id, name, color) VALUES (?, ?, ?)");
            $result = $stmt->execute([$user_id, $name, $color]);
            
            if ($result) {
                $id = $pdo->lastInsertId();
                echo json_encode(['success' => true, 'id' => $id, 'message' => 'Метка успешно создана']);
            } else {
                echo json_encode(['error' => 'Ошибка при создании метки']);
            }
            break;
            
        case 'update':
            // Обновление метки
            $id = intval($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $color = trim($_POST['color'] ?? '#6c757d');
            
            if ($id <= 0 || empty($name)) {
                echo json_encode(['error' => 'Некорректные данные']);
                break;
            }
            
            // Проверка существования
            $stmt = $pdo->prepare("SELECT id FROM tags WHERE user_id = ? AND id = ?");
            $stmt->execute([$user_id, $id]);
            if (!$stmt->fetch()) {
                echo json_encode(['error' => 'Метка не найдена']);
                break;
            }
            
            // Проверка уникальности имени
            $stmt = $pdo->prepare("SELECT id FROM tags WHERE user_id = ? AND name = ? AND id != ?");
            $stmt->execute([$user_id, $name, $id]);
            if ($stmt->fetch()) {
                echo json_encode(['error' => 'Метка с таким названием уже существует']);
                break;
            }
            
            $stmt = $pdo->prepare("UPDATE tags SET name = ?, color = ? WHERE id = ? AND user_id = ?");
            $result = $stmt->execute([$name, $color, $id, $user_id]);
            
            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Метка успешно обновлена']);
            } else {
                echo json_encode(['error' => 'Ошибка при обновлении метки']);
            }
            break;
            
        case 'delete':
            // Удаление метки
            $id = intval($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['error' => 'Некорректный ID метки']);
                break;
            }
            
            // Проверка существования
            $stmt = $pdo->prepare("SELECT id FROM tags WHERE user_id = ? AND id = ?");
            $stmt->execute([$user_id, $id]);
            if (!$stmt->fetch()) {
                echo json_encode(['error' => 'Метка не найдена']);
                break;
            }
            
            $stmt = $pdo->prepare("DELETE FROM tags WHERE id = ? AND user_id = ?");
            $result = $stmt->execute([$id, $user_id]);
            
            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Метка успешно удалена']);
            } else {
                echo json_encode(['error' => 'Ошибка при удалении метки']);
            }
            break;
            
        case 'get_usage_stats':
            // Получение статистики использования меток
            $stmt = $pdo->prepare("
                SELECT 
                    t.id,
                    t.name,
                    t.color,
                    COUNT(tr.id) as usage_count
                FROM tags t
                LEFT JOIN transactions tr ON FIND_IN_SET(t.name, REPLACE(tr.tags_text, ';', ',')) > 0 AND tr.user_id = t.user_id
                WHERE t.user_id = ?
                GROUP BY t.id
                ORDER BY usage_count DESC, t.name
            ");
            $stmt->execute([$user_id]);
            $stats = $stmt->fetchAll();
            echo json_encode(['success' => true, 'stats' => $stats]);
            break;
            
        default:
            echo json_encode(['error' => 'Неизвестное действие']);
            break;
    }
} catch (PDOException $e) {
    echo json_encode(['error' => 'Ошибка базы данных: ' . $e->getMessage()]);
}
?>