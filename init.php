<?php
// create_user.php - Создание пользователя admin
require_once 'config.php';

// Проверяем, существует ли уже пользователь admin
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'admin' OR email = 'admin@finance.local'");
$stmt->execute();
$existingUser = $stmt->fetch();

if ($existingUser) {
    echo "Пользователь admin уже существует. ID: " . $existingUser['id'] . "\n";
    echo "Хотите обновить пароль? Запустите update_admin_password.php\n";
    exit();
}

// Создаем нового пользователя admin
$username = 'admin';
$email = 'admin@finance.local';
$password = '123';
$password_hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
    $result = $stmt->execute([$username, $email, $password_hash]);
    
    if ($result) {
        $userId = $pdo->lastInsertId();
        echo "✓ Пользователь admin успешно создан!\n";
        echo "  ID: " . $userId . "\n";
        echo "  Имя пользователя: admin\n";
        echo "  Email: admin@finance.local\n";
        echo "  Пароль: 123\n";
        echo "\nТеперь вы можете войти в систему используя:\n";
        echo "  Имя пользователя: admin\n";
        echo "  Пароль: 123\n";
        
        // Создаем настройки по умолчанию для пользователя
        $stmt = $pdo->prepare("INSERT INTO user_settings (user_id, default_currency, date_format, language) VALUES (?, 'RUB', 'Y-m-d', 'ru')");
        $stmt->execute([$userId]);
        echo "\n✓ Настройки пользователя созданы\n";
        
        // Создаем демо-категории для admin
        $categories = [
            ['Зарплата', '#28a745', 'income'],
            ['Фриланс', '#17a2b8', 'income'],
            ['Подарки', '#ffc107', 'income'],
            ['Продукты', '#dc3545', 'expense'],
            ['Транспорт', '#ffc107', 'expense'],
            ['Коммунальные услуги', '#6c757d', 'expense'],
            ['Развлечения', '#6f42c1', 'expense'],
            ['Здоровье', '#20c997', 'expense'],
            ['Образование', '#fd7e14', 'expense'],
            ['Одежда', '#e83e8c', 'expense']
        ];
        
        $stmt = $pdo->prepare("INSERT INTO categories (user_id, name, color, type) VALUES (?, ?, ?, ?)");
        foreach ($categories as $category) {
            $stmt->execute([$userId, $category[0], $category[1], $category[2]]);
        }
        echo "✓ Создано " . count($categories) . " демо-категорий\n";
        
        // Создаем демо-метки для admin
        $tags = [
            ['важное', '#dc3545'],
            ['ежемесячное', '#28a745'],
            ['разовое', '#6c757d'],
            ['семья', '#fd7e14'],
            ['работа', '#007bff'],
            ['хобби', '#e83e8c']
        ];
        
        $stmt = $pdo->prepare("INSERT INTO tags (user_id, name, color) VALUES (?, ?, ?)");
        foreach ($tags as $tag) {
            $stmt->execute([$userId, $tag[0], $tag[1]]);
        }
        echo "✓ Создано " . count($tags) . " демо-меток\n";
        
        // Создаем демо-счета для admin
        $accounts = [
            ['40817810000000000001', 'Сбербанк', 50000.00, 50000.00, '#28a745', 'bi-bank', 'Основной счет для зарплаты', '2024-01-01'],
            ['40817810000000000002', 'Тинькофф', 30000.00, 30000.00, '#ffc107', 'bi-credit-card', 'Для повседневных расходов', '2024-01-15'],
            ['Наличные', 'Домашний кошелек', 10000.00, 10000.00, '#17a2b8', 'bi-cash', 'Наличные средства', '2024-02-01']
        ];
        
        $stmt = $pdo->prepare("INSERT INTO accounts (user_id, account_number, bank_name, initial_balance, current_balance, color, icon, note, opening_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($accounts as $account) {
            $stmt->execute([$userId, $account[0], $account[1], $account[2], $account[3], $account[4], $account[5], $account[6], $account[7]]);
        }
        echo "✓ Создано " . count($accounts) . " демо-счетов\n";
        
        // Создаем демо-транзакции для admin
        // Получаем ID категорий и счетов
        $stmt = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND type = 'income' LIMIT 1");
        $stmt->execute([$userId]);
        $incomeCategory = $stmt->fetch();
        
        $stmt = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND type = 'expense' LIMIT 1");
        $stmt->execute([$userId]);
        $expenseCategory = $stmt->fetch();
        
        $stmt = $pdo->prepare("SELECT id FROM accounts WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $account = $stmt->fetch();
        
        if ($incomeCategory && $expenseCategory && $account) {
            $transactions = [
                ['income', 75000.00, date('Y-m-d', strtotime('-25 days')), 'Зарплата за январь', 'зарплата;основной'],
                ['expense', 8500.00, date('Y-m-d', strtotime('-20 days')), 'Продукты на месяц', 'продукты;ежемесячное'],
                ['expense', 3500.00, date('Y-m-d', strtotime('-18 days')), 'Транспортные расходы', 'транспорт;работа'],
                ['expense', 2500.00, date('Y-m-d', strtotime('-15 days')), 'Ресторан', 'развлечения;семья'],
                ['expense', 1800.00, date('Y-m-d', strtotime('-10 days')), 'Аптека', 'здоровье;важное'],
                ['income', 15000.00, date('Y-m-d', strtotime('-5 days')), 'Фриланс проект', 'фриланс;разовое'],
                ['expense', 4500.00, date('Y-m-d', strtotime('-3 days')), 'Коммунальные услуги', 'коммуналка;ежемесячное'],
                ['expense', 3200.00, date('Y-m-d', strtotime('-1 days')), 'Супермаркет', 'продукты;семья']
            ];
            
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_id, category_id, type, amount, transaction_date, description, tags_text) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($transactions as $transaction) {
                $categoryId = $transaction[0] == 'income' ? $incomeCategory['id'] : $expenseCategory['id'];
                $stmt->execute([$userId, $account['id'], $categoryId, $transaction[0], $transaction[1], $transaction[2], $transaction[3], $transaction[4]]);
            }
            echo "✓ Создано " . count($transactions) . " демо-транзакций\n";
        }
        
        echo "\n========================================\n";
        echo "Готово! Теперь вы можете войти в систему:\n";
        echo "http://localhost/login.php\n";
        echo "Имя пользователя: admin\n";
        echo "Пароль: 123\n";
        echo "========================================\n";
        
    } else {
        echo "✗ Ошибка при создании пользователя\n";
    }
    
} catch (PDOException $e) {
    echo "✗ Ошибка базы данных: " . $e->getMessage() . "\n";
}
?>