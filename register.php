<?php
session_start();
require_once 'config.php';

// Если пользователь уже авторизован, перенаправляем на дашборд
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Валидация
    if (empty($username) || empty($email) || empty($password)) {
        $error = 'Пожалуйста, заполните все поля';
    } elseif (strlen($username) < 3) {
        $error = 'Имя пользователя должно содержать минимум 3 символа';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Введите корректный email адрес';
    } elseif (strlen($password) < 6) {
        $error = 'Пароль должен содержать минимум 6 символов';
    } elseif ($password !== $confirm_password) {
        $error = 'Пароли не совпадают';
    } else {
        // Проверяем существование пользователя
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = 'Пользователь с таким именем или email уже существует';
        } else {
            // Создаем нового пользователя
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $username, $email, $password_hash);
            
            if ($stmt->execute()) {
                $user_id = $conn->insert_id;
                
                // Создаем настройки по умолчанию для пользователя
                $stmt2 = $conn->prepare("INSERT INTO user_settings (user_id, default_currency, date_format, language) VALUES (?, 'RUB', 'Y-m-d', 'ru')");
                $stmt2->bind_param("i", $user_id);
                $stmt2->execute();
                
                // Создаем категории по умолчанию
                $default_categories = [
                    ['Зарплата', '#28a745', 'income'],
                    ['Фриланс', '#17a2b8', 'income'],
                    ['Подарки', '#ffc107', 'income'],
                    ['Продукты', '#dc3545', 'expense'],
                    ['Транспорт', '#ffc107', 'expense'],
                    ['Коммунальные услуги', '#6c757d', 'expense'],
                    ['Развлечения', '#6f42c1', 'expense'],
                    ['Здоровье', '#20c997', 'expense']
                ];
                
                $stmt3 = $conn->prepare("INSERT INTO categories (user_id, name, color, type) VALUES (?, ?, ?, ?)");
                foreach ($default_categories as $category) {
                    $stmt3->bind_param("isss", $user_id, $category[0], $category[1], $category[2]);
                    $stmt3->execute();
                }
                
                // Создаем счет по умолчанию
                $stmt4 = $conn->prepare("INSERT INTO accounts (user_id, account_number, bank_name, initial_balance, current_balance, color, icon) VALUES (?, 'Наличные', 'Основной счет', 0, 0, '#28a745', 'bi-cash')");
                $stmt4->bind_param("i", $user_id);
                $stmt4->execute();
                
                $success = 'Регистрация успешна! Теперь вы можете войти в систему.';
                
                // Очищаем форму
                $username = $email = '';
            } else {
                $error = 'Ошибка при регистрации. Попробуйте позже.';
            }
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация - Финансовый дневник</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .register-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            padding: 40px;
            width: 100%;
            max-width: 500px;
        }
        .register-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .register-header i {
            font-size: 48px;
            color: #667eea;
        }
        .register-header h2 {
            margin-top: 10px;
            color: #333;
        }
        .btn-register {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 12px;
            font-weight: 600;
            width: 100%;
        }
        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102,126,234,0.4);
        }
    </style>
</head>
<body>
    <div class="register-card">
        <div class="register-header">
            <i class="bi bi-person-plus"></i>
            <h2>Создать аккаунт</h2>
            <p class="text-muted">Зарегистрируйтесь для начала работы</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="mb-3">
                <label for="username" class="form-label">Имя пользователя</label>
                <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($username ?? ''); ?>" required>
            </div>
            <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($email ?? ''); ?>" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Пароль</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <div class="mb-3">
                <label for="confirm_password" class="form-label">Подтверждение пароля</label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
            </div>
            <button type="submit" class="btn btn-register">Зарегистрироваться</button>
        </form>
        
        <hr class="my-4">
        
        <div class="text-center">
            <p class="text-muted mb-0">Уже есть аккаунт? <a href="login.php" class="text-decoration-none">Войти</a></p>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>