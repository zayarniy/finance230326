<?php
require_once 'config/session.php';

// Проверка авторизации
if (!isLoggedIn()) {
    forceLogout();
}

require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_date_formatted = date('d.m.Y', strtotime($selected_date));

// Продлеваем срок действия токена "запомнить меня" при активности пользователя
if (isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $new_expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    $stmt = $pdo->prepare("UPDATE users SET remember_token_expires = ? WHERE id = ? AND remember_token = ?");
    $stmt->execute([$new_expires, $user_id, $token]);
}

// Получаем суммы доходов и расходов
$stmt = $pdo->prepare("SELECT SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income, SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expense FROM transactions WHERE user_id = ? AND transaction_date <= ?");
$stmt->execute([$user_id, $selected_date]);
$totals = $stmt->fetch();
$total_balance_all = ($totals['total_income'] ?? 0) - ($totals['total_expense'] ?? 0);

// Получаем балансы счетов
$stmt = $pdo->prepare("SELECT a.*, COALESCE((SELECT SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE -t.amount END) FROM transactions t WHERE t.account_id = a.id AND t.transaction_date <= ?), 0) as transaction_balance FROM accounts a WHERE a.user_id = ? AND a.is_active = 1");
$stmt->execute([$selected_date, $user_id]);
$accounts_data = $stmt->fetchAll();

$accounts = [];
$total_balance = 0;
foreach ($accounts_data as $account) {
    $balance = $account['initial_balance'] + $account['transaction_balance'];
    $accounts[] = $account;
    $accounts[count($accounts) - 1]['current_balance'] = $balance;
    $total_balance += $balance;
}

// Расходы за месяц
$month_start = date('Y-m-01', strtotime($selected_date));
$month_end = date('Y-m-t', strtotime($selected_date));
$stmt = $pdo->prepare("SELECT SUM(amount) as month_expenses FROM transactions WHERE user_id = ? AND type = 'expense' AND description<>'Перевод: ' AND transaction_date BETWEEN ? AND ?");
$stmt->execute([$user_id, $month_start, $month_end]);
$month_expenses = $stmt->fetch()['month_expenses'] ?? 0;

$stmt = $pdo->prepare("SELECT SUM(amount) as month_income FROM transactions WHERE user_id = ? AND type = 'income' AND description<>'Перевод: ' AND transaction_date BETWEEN ? AND ?");
$stmt->execute([$user_id, $month_start, $month_end]);
$month_income = $stmt->fetch()['month_income'] ?? 0;

// Предстоящие расходы
$stmt = $pdo->prepare("SELECT SUM(amount) as upcoming FROM transactions WHERE user_id = ? AND type = 'expense' AND transaction_date > ?");
$stmt->execute([$user_id, $selected_date]);
$upcoming = $stmt->fetch()['upcoming'] ?? 0;

// Последние операции
$stmt = $pdo->prepare("SELECT t.*, c.name as category_name, c.color as category_color FROM transactions t LEFT JOIN categories c ON t.category_id = c.id WHERE t.user_id = ? AND t.transaction_date <= ? ORDER BY t.transaction_date DESC LIMIT 5");
$stmt->execute([$user_id, $selected_date]);
$recent = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#667eea">
    <title>Дашборд - Финансовый дневник</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="manifest" href="manifest.json">
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

        .date-selector {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 30px;
            padding: 6px 12px;
            backdrop-filter: blur(10px);
        }

        .date-selector input {
            background: transparent;
            border: none;
            color: white;
            font-size: 14px;
        }

        .date-selector input::-webkit-calendar-picker-indicator {
            filter: invert(1);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            padding: 0 16px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 16px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
            margin: 8px 0;
        }

        .section-card {
            background: white;
            border-radius: 20px;
            margin: 0 16px 16px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .section-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .account-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .account-item:last-child {
            border-bottom: none;
        }

        .account-icon {
            width: 40px;
            height: 40px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .transaction-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .transaction-date {
            font-size: 11px;
            color: #6c757d;
        }

        .text-income {
            color: #28a745;
        }

        .text-expense {
            color: #dc3545;
        }

        .fab {
            position: fixed;
            bottom: 80px;
            right: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            width: 56px;
            height: 56px;
            border-radius: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
            cursor: pointer;
            z-index: 1000;
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

        .balance-positive {
            color: #28a745;
        }

        .balance-negative {
            color: #dc3545;
        }

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
        }

        .nav-scroll .nav-item.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
        }

        .nav-scroll .nav-item i {
            font-size: 20px;
            display: block;
            margin-bottom: 4px;
        }

        .nav-scroll .nav-item span {
            font-size: 10px;
            white-space: nowrap;
        }
    </style>
</head>

<body>
    <div class="mobile-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <div class="small opacity-75">Добро пожаловать,</div>
                <h4 class="mb-0"><?php echo htmlspecialchars($_SESSION['username']); ?></h4>
            </div>
            <div class="date-selector">
                <form method="GET" class="d-flex align-items-center gap-2">
                    <i class="bi bi-calendar3"></i>
                    <input type="date" name="date" value="<?php echo $selected_date; ?>" onchange="this.form.submit()">
                    <a href="dashboard.php" class="text-white text-decoration-none small">Сегодня</a>
                </form>
            </div>
        </div>
        <div class="mt-3">
            <div class="small">Данные на <?php echo $selected_date_formatted; ?></div>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div>💰</div>
            <div class="stat-value"><?php echo number_format($total_balance_all, 0, '.', ' '); ?> ₽</div>
            <div class="small text-muted">Общий баланс</div>
        </div>
        <div class="stat-card">
            <div>🏦</div>
            <div class="stat-value"><?php echo number_format($total_balance, 0, '.', ' '); ?> ₽</div>
            <div class="small text-muted">Сумма счетов</div>
        </div>
        <div class="stat-card">
            <div class="text-danger">↓</div>
            <div class="stat-value text-danger">-<?php echo number_format($month_expenses, 0, '.', ' '); ?> ₽</div>
            <div class="small text-muted">Расходы за месяц</div>
        </div>
        <div class="stat-card">
            <div class="text-success">↑</div>
            <div class="stat-value text-success">+<?php echo number_format($month_income, 0, '.', ' '); ?> ₽</div>
            <div class="small text-muted">Доходы за месяц</div>
        </div>
        <div class="stat-card">
            <div>📅</div>
            <div class="stat-value text-warning"><?php echo number_format($upcoming, 0, '.', ' '); ?> ₽</div>
            <div class="small text-muted">Предстоит потратить</div>
        </div>
        <div class="stat-card">
            <div>📊</div>
            <div class="stat-value"><?php echo count($accounts); ?></div>
            <div class="small text-muted">Активных счетов</div>
        </div>
    </div>

    <div class="section-card">
        <div class="section-title"><i class="bi bi-bank"></i> Мои счета</div>
        <?php if (count($accounts) > 0): ?>
            <?php foreach ($accounts as $account): ?>
                <div class="account-item">
                    <div class="d-flex align-items-center gap-3">
                        <!--
                        <div class="account-icon" style="background: <?php echo $account['color']; ?>20; color: <?php echo $account['color']; ?>">
                            <?php echo $account['icon']; ?>
                        </div>-->
                        <div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($account['bank_name']); ?></div>
                            <div class="small text-muted">
                                <?php echo htmlspecialchars(substr($account['account_number'], -8)); ?>
                            </div>
                        </div>
                    </div>
                    <div
                        class="fw-bold <?php echo $account['current_balance'] >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                        <?php echo number_format($account['current_balance'], 0, '.', ' '); ?> ₽
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="mt-3 pt-2 border-top d-flex justify-content-between">
                <strong>Итого:</strong>
                <strong class="<?php echo $total_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                    <?php echo number_format($total_balance, 0, '.', ' '); ?> ₽
                </strong>
            </div>
        <?php else: ?>
            <div class="text-center text-muted py-3">Нет активных счетов</div>
        <?php endif; ?>
        <a href="modules/accounts.php" class="btn btn-outline-primary w-100 mt-3 rounded-pill">Управление счетами</a>
    </div>

    <div class="section-card">
        <div class="section-title"><i class="bi bi-clock-history"></i> Последние операции</div>
        <?php if (count($recent) > 0): ?>
            <?php foreach ($recent as $t): ?>
                <div class="transaction-item">
                    <div>
                        <div class="transaction-date"><?php echo date('d.m.Y', strtotime($t['transaction_date'])); ?></div>
                        <span class="badge"
                            style="background-color: <?php echo $t['category_color']; ?>"><?php echo htmlspecialchars($t['category_name']); ?></span>
                        <?php if (!empty($t['description'])): ?>
                            <div class="small text-muted mt-1"><?php echo htmlspecialchars(substr($t['description'], 0, 30)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="fw-bold <?php echo $t['type'] == 'income' ? 'text-income' : 'text-expense'; ?>">
                        <?php echo ($t['type'] == 'income' ? '+' : '-') . number_format($t['amount'], 0, '.', ' '); ?> ₽
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-center text-muted py-3">Нет операций</div>
        <?php endif; ?>
        <a href="modules/finances.php" class="btn btn-primary w-100 mt-3 rounded-pill">Добавить операцию</a>
    </div>

    <a href="modules/finances.php" class="fab"><i class="bi bi-plus-lg"></i></a>

    <div class="mobile-nav">
        <div class="nav-scroll">
            <a href="dashboard.php" class="nav-item active">
                <i class="bi bi-house-door"></i>
                <span>Главная</span>
            </a>
            <a href="modules/finances.php" class="nav-item">
                <i class="bi bi-calculator"></i>
                <span>Финансы</span>
            </a>
            <a href="modules/accounts.php" class="nav-item">
                <i class="bi bi-bank"></i>
                <span>Счета</span>
            </a>
            <a href="modules/statistics.php" class="nav-item">
                <i class="bi bi-graph-up"></i>
                <span>Статистика</span>
            </a>
            <a href="modules/transfers.php" class="nav-item">
                <i class="bi bi-arrow-left-right"></i>
                <span>Переводы</span>
            </a>
            <a href="modules/debts.php" class="nav-item">
                <i class="bi bi-credit-card-2-front"></i>
                <span>Долги</span>
            </a>
            <a href="profile.php" class="nav-item">
                <i class="bi bi-person"></i>
                <span>Профиль</span>
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>