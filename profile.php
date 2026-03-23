<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Получаем данные пользователя
$stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Получаем настройки пользователя
$stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
$stmt->execute([$user_id]);
$settings = $stmt->fetch();

if (!$settings) {
    // Создаем настройки по умолчанию
    $stmt = $pdo->prepare("INSERT INTO user_settings (user_id, default_currency, date_format, language) VALUES (?, 'RUB', 'Y-m-d', 'ru')");
    $stmt->execute([$user_id]);
    $stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $settings = $stmt->fetch();
}

// Обработка обновления профиля
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (!empty($username) && !empty($email)) {
            // Проверяем уникальность username и email
            $stmt = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
            $stmt->execute([$username, $email, $user_id]);
            if ($stmt->fetch()) {
                $error = "Имя пользователя или email уже используются";
            } else {
                // Обновляем основные данные
                $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
                $stmt->execute([$username, $email, $user_id]);
                
                // Обновляем пароль если указан
                if (!empty($new_password)) {
                    if (empty($current_password)) {
                        $error = "Введите текущий пароль для смены пароля";
                    } else {
                        // Проверяем текущий пароль
                        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
                        $stmt->execute([$user_id]);
                        $user_data = $stmt->fetch();
                        
                        if (password_verify($current_password, $user_data['password_hash'])) {
                            if ($new_password === $confirm_password) {
                                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                                $stmt->execute([$password_hash, $user_id]);
                                $message = "Профиль успешно обновлен. Пароль изменен.";
                            } else {
                                $error = "Новый пароль и подтверждение не совпадают";
                            }
                        } else {
                            $error = "Неверный текущий пароль";
                        }
                    }
                }
                
                if (empty($error) && empty($new_password)) {
                    $message = "Профиль успешно обновлен";
                }
                
                // Обновляем сессию
                $_SESSION['username'] = $username;
                $_SESSION['email'] = $email;
            }
        } else {
            $error = "Заполните все обязательные поля";
        }
    } elseif (isset($_POST['update_settings'])) {
        $currency = $_POST['currency'] ?? 'RUB';
        $date_format = $_POST['date_format'] ?? 'Y-m-d';
        $language = $_POST['language'] ?? 'ru';
        $notifications = isset($_POST['notifications']) ? 1 : 0;
        
        $stmt = $pdo->prepare("UPDATE user_settings SET default_currency = ?, date_format = ?, language = ?, notification_enabled = ? WHERE user_id = ?");
        $stmt->execute([$currency, $date_format, $language, $notifications, $user_id]);
        $message = "Настройки успешно обновлены";
        
        // Обновляем данные настроек
        $stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $settings = $stmt->fetch();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Профиль - Финансовый дневник</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 12px 20px;
            margin: 5px 0;
            border-radius: 10px;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        .profile-card {
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            border: none;
        }
        .avatar-circle {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        .avatar-circle i {
            font-size: 48px;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-2 p-0">
                <div class="sidebar">
                    <div class="text-center py-4">
                        <i class="bi bi-wallet2" style="font-size: 48px; color: white;"></i>
                        <h5 class="text-white mt-2"><?php echo htmlspecialchars($_SESSION['username']); ?></h5>
                    </div>
                    <nav class="nav flex-column px-3">
                        <a class="nav-link" href="dashboard.php">
                            <i class="bi bi-speedometer2"></i> Дашборд
                        </a>
                        <a class="nav-link" href="modules/tags.php">
                            <i class="bi bi-tags"></i> Банк Меток
                        </a>
                        <a class="nav-link" href="modules/categories.php">
                            <i class="bi bi-grid"></i> Банк Категорий
                        </a>
                        <a class="nav-link" href="modules/accounts.php">
                            <i class="bi bi-bank"></i> Справочник Счетов
                        </a>
                        <a class="nav-link" href="modules/finances.php">
                            <i class="bi bi-calculator"></i> Финансы
                        </a>
                        <a class="nav-link" href="modules/transfers.php">
                            <i class="bi bi-arrow-left-right"></i> Переводы
                        </a>
                        <a class="nav-link" href="modules/statistics.php">
                            <i class="bi bi-graph-up"></i> Статистика
                        </a>
                        <hr class="bg-light">
                        <a class="nav-link active" href="profile.php">
                            <i class="bi bi-person-circle"></i> Профиль
                        </a>
                        <a class="nav-link" href="logout.php">
                            <i class="bi bi-box-arrow-right"></i> Выход
                        </a>
                    </nav>
                </div>
            </div>
            
            <div class="col-md-10 p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="bi bi-person-circle"></i> Мой профиль</h2>
                </div>
                
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-4 mb-4">
                        <div class="card profile-card">
                            <div class="card-body text-center">
                                <div class="avatar-circle">
                                    <i class="bi bi-person-fill"></i>
                                </div>
                                <h4><?php echo htmlspecialchars($user['username']); ?></h4>
                                <p class="text-muted"><?php echo htmlspecialchars($user['email']); ?></p>
                                <hr>
                                <div class="text-start">
                                    <p><i class="bi bi-calendar-check"></i> Дата регистрации: <?php echo date('d.m.Y'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-8">
                        <div class="card profile-card mb-4">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="bi bi-pencil-square"></i> Редактирование профиля</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="mb-3">
                                        <label for="username" class="form-label">Имя пользователя *</label>
                                        <input type="text" class="form-control" id="username" name="username" 
                                               value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email *</label>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                    </div>
                                    
                                    <hr>
                                    
                                    <div class="mb-3">
                                        <label for="current_password" class="form-label">Текущий пароль</label>
                                        <input type="password" class="form-control" id="current_password" name="current_password">
                                        <small class="text-muted">Введите текущий пароль, если хотите изменить пароль</small>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="new_password" class="form-label">Новый пароль</label>
                                            <input type="password" class="form-control" id="new_password" name="new_password">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="confirm_password" class="form-label">Подтверждение пароля</label>
                                            <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                                        </div>
                                    </div>
                                    
                                    <button type="submit" name="update_profile" class="btn btn-primary">
                                        <i class="bi bi-save"></i> Сохранить изменения
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <div class="card profile-card">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="bi bi-gear"></i> Настройки приложения</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="currency" class="form-label">Основная валюта</label>
                                            <select class="form-select" id="currency" name="currency">
                                                <option value="RUB" <?php echo $settings['default_currency'] == 'RUB' ? 'selected' : ''; ?>>Рубль (RUB)</option>
                                                <option value="USD" <?php echo $settings['default_currency'] == 'USD' ? 'selected' : ''; ?>>Доллар (USD)</option>
                                                <option value="EUR" <?php echo $settings['default_currency'] == 'EUR' ? 'selected' : ''; ?>>Евро (EUR)</option>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label for="date_format" class="form-label">Формат даты</label>
                                            <select class="form-select" id="date_format" name="date_format">
                                                <option value="Y-m-d" <?php echo $settings['date_format'] == 'Y-m-d' ? 'selected' : ''; ?>>ГГГГ-ММ-ДД</option>
                                                <option value="d-m-Y" <?php echo $settings['date_format'] == 'd-m-Y' ? 'selected' : ''; ?>>ДД-ММ-ГГГГ</option>
                                                <option value="m/d/Y" <?php echo $settings['date_format'] == 'm/d/Y' ? 'selected' : ''; ?>>ММ/ДД/ГГГГ</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="language" class="form-label">Язык интерфейса</label>
                                        <select class="form-select" id="language" name="language">
                                            <option value="ru" <?php echo $settings['language'] == 'ru' ? 'selected' : ''; ?>>Русский</option>
                                            <option value="en" <?php echo $settings['language'] == 'en' ? 'selected' : ''; ?>>English</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" id="notifications" name="notifications" 
                                               <?php echo $settings['notification_enabled'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="notifications">
                                            Включить уведомления
                                        </label>
                                    </div>
                                    
                                    <button type="submit" name="update_settings" class="btn btn-primary">
                                        <i class="bi bi-save"></i> Сохранить настройки
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>