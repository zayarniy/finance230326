<?php
//session_start();
require_once 'config/session.php';
requireAuth();
require_once 'config/database.php';
$user_id = $_SESSION['user_id'];

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Обработка обновления профиля
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (!empty($username) && !empty($email)) {
            // Проверяем уникальность
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
                        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
                        $stmt->execute([$user_id]);
                        $user_data = $stmt->fetch();

                        if (password_verify($current_password, $user_data['password_hash'])) {
                            if ($new_password === $confirm_password) {
                                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                                $stmt->execute([$password_hash, $user_id]);
                                $message = "Профиль обновлен. Пароль изменен.";
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
    }
}

// Получаем данные пользователя
$stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Получаем настройки пользователя
$stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
$stmt->execute([$user_id]);
$settings = $stmt->fetch();

if (!$settings) {
    $stmt = $pdo->prepare("INSERT INTO user_settings (user_id, default_currency, date_format, language) VALUES (?, 'RUB', 'Y-m-d', 'ru')");
    $stmt->execute([$user_id]);
    $stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $settings = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#667eea">
    <title>Профиль - Финансовый дневник</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        * {
            -webkit-tap-highlight-color: transparent;
        }

        body {
            background: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            padding-bottom: 70px;
        }

        .mobile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 16px;
            border-radius: 0 0 24px 24px;
            margin-bottom: 16px;
        }

        .back-button {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 30px;
            padding: 8px 16px;
            color: white;
            text-decoration: none;
            font-size: 14px;
            display: inline-block;
        }

        .profile-card {
            background: white;
            border-radius: 20px;
            margin: 0 16px 16px;
            padding: 24px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 32px;
            color: white;
        }

        .form-card {
            background: white;
            border-radius: 20px;
            margin: 0 16px 16px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .section-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #212529;
        }

        .form-control,
        .form-select {
            border-radius: 30px;
            padding: 12px 16px;
            border: 1px solid #e9ecef;
        }

        .mobile-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05);
            padding: 8px 0;
            z-index: 1000;
        }

        .mobile-nav .nav-item {
            text-align: center;
            padding: 8px 0;
            color: #6c757d;
            text-decoration: none;
            display: block;
        }

        .mobile-nav .nav-item.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .mobile-nav .nav-item i {
            font-size: 22px;
            display: block;
            margin-bottom: 4px;
        }

        .mobile-nav .nav-item span {
            font-size: 11px;
        }

        .btn-outline-primary {
            border-radius: 30px;
            padding: 12px;
            transition: all 0.2s;
        }

        .btn-outline-primary:active {
            transform: scale(0.98);
        }

        .alert {
            border-radius: 16px;
            margin: 0 16px 16px;
        }

        hr {
            margin: 20px 0;
        }

        /* Добавьте эти стили в profile.php */
        .nav-scroll {
            display: flex;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            gap: 4px;
            padding: 0 8px;
        }

        .nav-scroll::-webkit-scrollbar {
            display: none;
        }

        .nav-scroll .nav-item {
            flex: 0 0 auto;
            min-width: 70px;
            text-align: center;
            padding: 8px 0;
            color: #6c757d;
            text-decoration: none;
            display: block;
            border-radius: 12px;
            transition: all 0.2s;
        }

        .nav-scroll .nav-item.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .nav-scroll .nav-item i {
            font-size: 22px;
            display: block;
            margin-bottom: 4px;
        }

        .nav-scroll .nav-item span {
            font-size: 11px;
            white-space: nowrap;
        }
    </style>
</head>

<body>
    <div class="mobile-header">
        <div class="d-flex justify-content-between align-items-center">
            <a href="dashboard.php" class="back-button">← Назад</a>
            <div></div>
        </div>
        <div class="page-title fs-3 fw-bold mt-2">Профиль</div>
        <div class="small">Управление аккаунтом и настройками</div>
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

    <!-- Карточка профиля -->
    <div class="profile-card">
        <div class="avatar"><i class="bi bi-person-fill"></i></div>
        <h4><?php echo htmlspecialchars($user['username']); ?></h4>
        <p class="text-muted"><?php echo htmlspecialchars($user['email']); ?></p>
    </div>

    <!-- Ссылки на справочники -->
    <div class="form-card">
        <div class="section-title">
            <i class="bi bi-grid"></i> Справочники
        </div>
        <div class="d-flex gap-2">
            <a href="tags.php" class="btn btn-outline-primary rounded-pill flex-fill">
                <i class="bi bi-tags"></i> Метки
            </a>
            <a href="categories.php" class="btn btn-outline-primary rounded-pill flex-fill">
                <i class="bi bi-grid"></i> Категории
            </a>
        </div>
    </div>

    <!-- Редактирование профиля -->
    <div class="form-card">
        <div class="section-title">
            <i class="bi bi-person-gear"></i> Редактирование профиля
        </div>
        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label">Имя пользователя *</label>
                <input type="text" name="username" class="form-control"
                    value="<?php echo htmlspecialchars($user['username']); ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Email *</label>
                <input type="email" name="email" class="form-control"
                    value="<?php echo htmlspecialchars($user['email']); ?>" required>
            </div>

            <hr>

            <div class="mb-3">
                <label class="form-label">Текущий пароль</label>
                <input type="password" name="current_password" class="form-control"
                    placeholder="Введите для смены пароля">
            </div>

            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label">Новый пароль</label>
                    <input type="password" name="new_password" class="form-control" placeholder="Новый пароль">
                </div>
                <div class="col-6">
                    <label class="form-label">Подтверждение</label>
                    <input type="password" name="confirm_password" class="form-control" placeholder="Подтвердите">
                </div>
            </div>

            <button type="submit" name="update_profile" class="btn btn-primary w-100 rounded-pill mt-3">
                <i class="bi bi-save"></i> Сохранить изменения
            </button>
        </form>
    </div>

    <!-- Настройки приложения -->
    <div class="form-card">
        <div class="section-title">
            <i class="bi bi-gear"></i> Настройки приложения
        </div>
        <form method="POST" action="">
            <div class="row g-2">
                <div class="col-6 mb-2">
                    <label class="form-label">Валюта</label>
                    <select name="currency" class="form-select">
                        <option value="RUB" <?php echo $settings['default_currency'] == 'RUB' ? 'selected' : ''; ?>>Рубль
                            (RUB)</option>
                        <option value="USD" <?php echo $settings['default_currency'] == 'USD' ? 'selected' : ''; ?>>Доллар
                            (USD)</option>
                        <option value="EUR" <?php echo $settings['default_currency'] == 'EUR' ? 'selected' : ''; ?>>Евро
                            (EUR)</option>
                    </select>
                </div>
                <div class="col-6 mb-2">
                    <label class="form-label">Формат даты</label>
                    <select name="date_format" class="form-select">
                        <option value="Y-m-d" <?php echo $settings['date_format'] == 'Y-m-d' ? 'selected' : ''; ?>>
                            ГГГГ-ММ-ДД</option>
                        <option value="d-m-Y" <?php echo $settings['date_format'] == 'd-m-Y' ? 'selected' : ''; ?>>
                            ДД-ММ-ГГГГ</option>
                        <option value="m/d/Y" <?php echo $settings['date_format'] == 'm/d/Y' ? 'selected' : ''; ?>>
                            ММ/ДД/ГГГГ</option>
                    </select>
                </div>
            </div>

            <div class="form-check mb-3">
                <input type="checkbox" name="notifications" class="form-check-input" id="notifications" <?php echo $settings['notification_enabled'] ? 'checked' : ''; ?>>
                <label class="form-check-label" for="notifications">
                    Включить уведомления
                </label>
            </div>

            <button type="submit" name="update_settings" class="btn btn-outline-primary w-100 rounded-pill">
                <i class="bi bi-save"></i> Сохранить настройки
            </button>
        </form>
    </div>

    <!-- Выход -->
    <div class="form-card">
        <div class="d-flex justify-content-between align-items-center">
            <div><i class="bi bi-box-arrow-right"></i> Выход из аккаунта</div>
            <a href="logout.php" class="btn btn-danger rounded-pill">Выйти</a>
        </div>
    </div>

    <div class="mobile-nav">
        <div class="nav-scroll">
            <a href="dashboard.php" class="nav-item">
                <i class="bi bi-house-door"></i>
                <span>Главная</span>
            </a>
            <a href="finances.php" class="nav-item">
                <i class="bi bi-calculator"></i>
                <span>Финансы</span>
            </a>
            <a href="accounts.php" class="nav-item">
                <i class="bi bi-bank"></i>
                <span>Счета</span>
            </a>
            <a href="statistics.php" class="nav-item">
                <i class="bi bi-graph-up"></i>
                <span>Статистика</span>
            </a>
            <a href="transfers.php" class="nav-item">
                <i class="bi bi-arrow-left-right"></i>
                <span>Переводы</span>
            </a>
            <a href="debts.php" class="nav-item">
                <i class="bi bi-credit-card-2-front"></i>
                <span>Долги</span>
            </a>
            <a href="profile.php" class="nav-item active">
                <i class="bi bi-person"></i>
                <span>Профиль</span>
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>