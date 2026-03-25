<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    die('Необходимо авторизоваться');
}

$user_id = $_SESSION['user_id'];

echo "<h1>Диагностика категории 'Перевод'</h1>";

// 1. Проверяем структуру таблицы categories
echo "<h2>1. Структура таблицы categories</h2>";
$stmt = $pdo->query("DESCRIBE categories");
$columns = $stmt->fetchAll();
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
foreach ($columns as $col) {
    echo "<tr>";
    echo "<td>{$col['Field']}</td>";
    echo "<td>{$col['Type']}</td>";
    echo "<td>{$col['Null']}</td>";
    echo "<td>{$col['Key']}</td>";
    echo "<td>{$col['Default']}</td>";
    echo "<td>{$col['Extra']}</td>";
    echo "</tr>";
}
echo "</table>";

// 2. Проверяем все категории пользователя
echo "<h2>2. Все категории пользователя (ID: $user_id)</h2>";
$stmt = $pdo->prepare("SELECT * FROM categories WHERE user_id = ? ORDER BY type, name");
$stmt->execute([$user_id]);
$categories = $stmt->fetchAll();

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Название</th><th>Цвет</th><th>Тип</th></tr>";
foreach ($categories as $cat) {
    echo "<tr>";
    echo "<td>{$cat['id']}</td>";
    echo "<td>{$cat['name']}</td>";
    echo "<td>{$cat['color']}</td>";
    echo "<td>{$cat['type']}</td>";
    echo "</tr>";
}
echo "</table>";

// 3. Ищем категорию "Перевод" разными способами
echo "<h2>3. Поиск категории 'Перевод'</h2>";

// Способ 1: По названию и типу transfer
$stmt = $pdo->prepare("SELECT id, name, type FROM categories WHERE user_id = ? AND name = 'Перевод' AND type = 'transfer' LIMIT 1");
$stmt->execute([$user_id]);
$transfer1 = $stmt->fetch();
echo "<p><strong>Поиск по name='Перевод' AND type='transfer':</strong> ";
if ($transfer1) {
    echo "Найдено! ID: {$transfer1['id']}, Name: {$transfer1['name']}, Type: {$transfer1['type']}</p>";
} else {
    echo "НЕ НАЙДЕНО</p>";
}

// Способ 2: Только по названию (без учета типа)
$stmt = $pdo->prepare("SELECT id, name, type FROM categories WHERE user_id = ? AND name = 'Перевод' LIMIT 1");
$stmt->execute([$user_id]);
$transfer2 = $stmt->fetch();
echo "<p><strong>Поиск только по name='Перевод':</strong> ";
if ($transfer2) {
    echo "Найдено! ID: {$transfer2['id']}, Name: {$transfer2['name']}, Type: {$transfer2['type']}</p>";
} else {
    echo "НЕ НАЙДЕНО</p>";
}

// Способ 3: Поиск по части названия
$stmt = $pdo->prepare("SELECT id, name, type FROM categories WHERE user_id = ? AND name LIKE '%Перевод%'");
$stmt->execute([$user_id]);
$transfer3 = $stmt->fetchAll();
echo "<p><strong>Поиск по name LIKE '%Перевод%':</strong> ";
if (count($transfer3) > 0) {
    echo "Найдено " . count($transfer3) . " записей:<br>";
    foreach ($transfer3 as $t) {
        echo "- ID: {$t['id']}, Name: {$t['name']}, Type: {$t['type']}<br>";
    }
} else {
    echo "НЕ НАЙДЕНО</p>";
}

// 4. Проверяем транзакции переводов
echo "<h2>4. Транзакции с описанием 'Перевод'</h2>";
$stmt = $pdo->prepare("SELECT id, account_id, category_id, type, amount, transaction_date, description FROM transactions WHERE user_id = ? AND description LIKE '%Перевод%' LIMIT 10");
$stmt->execute([$user_id]);
$transfers = $stmt->fetchAll();

if (count($transfers) > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Категория ID</th><th>Тип</th><th>Сумма</th><th>Дата</th><th>Описание</th></tr>";
    foreach ($transfers as $t) {
        echo "<tr>";
        echo "<td>{$t['id']}</td>";
        echo "<td>{$t['category_id']}</td>";
        echo "<td>{$t['type']}</td>";
        echo "<td>{$t['amount']}</td>";
        echo "<td>{$t['transaction_date']}</td>";
        echo "<td>{$t['description']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>Нет транзакций с описанием 'Перевод'</p>";
}

// 5. Проверяем возможные значения ENUM в поле type
echo "<h2>5. Возможные значения ENUM поля type</h2>";
$stmt = $pdo->query("SHOW COLUMNS FROM categories WHERE Field = 'type'");
$column = $stmt->fetch();
if ($column && preg_match("/enum\((.*)\)/", $column['Type'], $matches)) {
    $enum_values = str_getcsv($matches[1], ',', "'");
    echo "<p>Допустимые значения: " . implode(', ', $enum_values) . "</p>";
}

// 6. Рекомендации
echo "<h2>6. Рекомендации</h2>";
if (!$transfer1 && !$transfer2) {
    echo "<p style='color: red;'>❌ Категория 'Перевод' не найдена! Необходимо создать её.</p>";
    echo "<p>Выполните SQL запрос:</p>";
    echo "<pre>
INSERT INTO categories (user_id, name, color, type) 
VALUES ($user_id, 'Перевод', '#6c757d', 'transfer');
</pre>";
} elseif ($transfer1) {
    echo "<p style='color: green;'>✅ Категория 'Перевод' найдена! ID: {$transfer1['id']}</p>";
} elseif ($transfer2 && $transfer2['type'] != 'transfer') {
    echo "<p style='color: orange;'>⚠️ Категория 'Перевод' найдена, но имеет тип '{$transfer2['type']}', а должен быть 'transfer'.</p>";
    echo "<p>Выполните SQL запрос для обновления:</p>";
    echo "<pre>
UPDATE categories SET type = 'transfer' WHERE id = {$transfer2['id']};
</pre>";
}

// 7. Если нужно, создаем категорию
if (isset($_GET['create'])) {
    $stmt = $pdo->prepare("INSERT INTO categories (user_id, name, color, type) VALUES (?, 'Перевод', '#6c757d', 'transfer')");
    $stmt->execute([$user_id]);
    echo "<p style='color: green;'>✅ Категория 'Перевод' успешно создана! ID: " . $pdo->lastInsertId() . "</p>";
    echo "<meta http-equiv='refresh' content='2'>";
}
?>
<p>
    <a href="?create=1" class="btn btn-primary" onclick="return confirm('Создать категорию Перевод?')">Создать категорию "Перевод"</a>
</p>
<p>
    <a href="dashboard.php">Вернуться на дашборд</a>
</p>