<?php
// Настройки безопасности
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');

// Запрещаем кэширование
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

// Уникальное имя сессии
$session_name = 'finance_app';
session_name($session_name);

session_start();

require_once 'config/database.php';

// Проверка авторизации - если пользователь уже залогинен
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
    header('Location: dashboard.php');
    exit;
}

// Проверка cookie "запомнить меня"
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    
    // Ищем пользователя по токену
    $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE remember_token = ? AND remember_token_expires > NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Автоматический вход
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        
        header('Location: dashboard.php');
        exit;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']) && $_POST['remember_me'] == '1';
    
    if (!empty($username) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT id, username, email, password_hash FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            // Очищаем старую сессию
            session_unset();
            session_destroy();
            
            // Создаем новую сессию
            session_start();
            
            // Регенерируем ID сессии
            session_regenerate_id(true);
            
            // Сохраняем данные пользователя
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();
            
            // Если отмечена галочка "Запомнить меня"
            if ($remember_me) {
                // Генерируем уникальный токен
                $remember_token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
                
                // Сохраняем токен в базе данных
                $stmt = $pdo->prepare("UPDATE users SET remember_token = ?, remember_token_expires = ? WHERE id = ?");
                $stmt->execute([$remember_token, $expires, $user['id']]);
                
                // Устанавливаем cookie на 30 дней
                setcookie('remember_token', $remember_token, time() + (86400 * 30), '/', '', false, true);
            }
            
            header('Location: dashboard.php');
            exit;
        } else {
            $error = "Неверное имя пользователя или пароль";
        }
    } else {
        $error = "Пожалуйста, заполните все поля";
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#667eea">
    <title>Вход - Финансовый дневник</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        * {
            -webkit-tap-highlight-color: transparent;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        
        .login-card {
            border-radius: 32px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            margin: 20px;
        }
        
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 20px;
            text-align: center;
        }
        
        .login-body {
            background: white;
            padding: 30px 20px;
        }
        
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 14px;
            font-weight: bold;
            border-radius: 30px;
        }
        
        .form-control {
            border-radius: 30px;
            padding: 12px 20px;
            border: 1px solid #e9ecef;
        }
        
        .input-group-text {
            border-radius: 30px 0 0 30px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
        }
        
        .form-check-label {
            font-size: 14px;
            color: #495057;
        }
        
        .form-check-input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .btn-link {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }
        
        .btn-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="login-card">
                    <div class="login-header">
                        <i class="bi bi-wallet2" style="font-size: 48px;"></i>
                        <h3 class="mt-2">Финансовый дневник</h3>
                        <p class="mb-0">Войдите в свой аккаунт</p>
                    </div>
                    <div class="login-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label class="form-label">Имя пользователя</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                                    <input type="text" class="form-control" name="username" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Пароль</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                    <input type="password" class="form-control" name="password" required>
                                </div>
                            </div>
                            
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="remember_me" name="remember_me" value="1">
                                <label class="form-check-label" for="remember_me">
                                    Запомнить меня
                                </label>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-login w-100">
                                <i class="bi bi-box-arrow-in-right"></i> Войти
                            </button>
                        </form>
                        
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>