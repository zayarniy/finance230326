<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Получаем выбранную дату из GET параметра или устанавливаем текущую
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_date_obj = new DateTime($selected_date);
$selected_date_formatted = $selected_date_obj->format('d.m.Y');

// Получаем суммы доходов и расходов на выбранную дату
$stmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income,
        SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expense
    FROM transactions 
    WHERE user_id = ? AND transaction_date <= ?
");
$stmt->execute([$user_id, $selected_date]);
$totals = $stmt->fetch();
$total_income_all = $totals['total_income'] ?? 0;
$total_expense_all = $totals['total_expense'] ?? 0;
$total_balance_all = $total_income_all - $total_expense_all;

// Получаем балансы по счетам на выбранную дату
$stmt = $pdo->prepare("
    SELECT 
        a.id,
        a.account_number,
        a.bank_name,
        a.initial_balance,
        a.color,
        a.icon,
        COALESCE((
            SELECT SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE -t.amount END)
            FROM transactions t
            WHERE t.account_id = a.id 
            AND t.transaction_date <= ?
        ), 0) as transaction_balance
    FROM accounts a
    WHERE a.user_id = ? AND a.is_active = 1
    ORDER BY a.current_balance DESC
");
$stmt->execute([$selected_date, $user_id]);
$accounts_data = $stmt->fetchAll();

// Рассчитываем текущий баланс для каждого счета на выбранную дату
$accounts = [];
$total_balance = 0;
foreach ($accounts_data as $account) {
    $balance = $account['initial_balance'] + $account['transaction_balance'];
    $accounts[] = [
        'id' => $account['id'],
        'account_number' => $account['account_number'],
        'bank_name' => $account['bank_name'],
        'current_balance' => $balance,
        'color' => $account['color'],
        'icon' => $account['icon']
    ];
    $total_balance += $balance;
}

// Получаем предстоящие расходы
$stmt = $pdo->prepare("
    SELECT 
        SUM(amount) as upcoming_expenses,
        COUNT(*) as upcoming_count
    FROM transactions 
    WHERE user_id = ? 
    AND type = 'expense' 
    AND transaction_date > ?
");
$stmt->execute([$user_id, $selected_date]);
$upcoming = $stmt->fetch();
$upcoming_expenses = $upcoming['upcoming_expenses'] ?? 0;
$upcoming_count = $upcoming['upcoming_count'] ?? 0;

// Получаем расходы за текущий месяц
$selected_month_start = date('Y-m-01', strtotime($selected_date));
$selected_month_end = date('Y-m-t', strtotime($selected_date));

$stmt = $pdo->prepare("
    SELECT SUM(amount) as month_expenses 
    FROM transactions 
    WHERE user_id = ? 
    AND type = 'expense' 
    AND transaction_date BETWEEN ? AND ?
");
$stmt->execute([$user_id, $selected_month_start, $selected_month_end]);
$month_expenses = $stmt->fetch()['month_expenses'] ?? 0;

// Получаем доходы за текущий месяц
$stmt = $pdo->prepare("
    SELECT SUM(amount) as month_income 
    FROM transactions 
    WHERE user_id = ? 
    AND type = 'income' 
    AND transaction_date BETWEEN ? AND ?
");
$stmt->execute([$user_id, $selected_month_start, $selected_month_end]);
$month_income = $stmt->fetch()['month_income'] ?? 0;

// Получаем количество счетов
$stmt = $pdo->prepare("
    SELECT COUNT(*) as account_count 
    FROM accounts 
    WHERE user_id = ? AND is_active = 1
");
$stmt->execute([$user_id]);
$account_count = $stmt->fetch()['account_count'] ?? 0;

// Получаем последние 5 транзакций
$stmt = $pdo->prepare("
    SELECT t.*, c.name as category_name, c.color as category_color, 
           a.account_number, a.bank_name
    FROM transactions t
    LEFT JOIN categories c ON t.category_id = c.id
    LEFT JOIN accounts a ON t.account_id = a.id
    WHERE t.user_id = ? AND t.transaction_date <= ?
    ORDER BY t.transaction_date DESC, t.created_at DESC
    LIMIT 5
");
$stmt->execute([$user_id, $selected_date]);
$recent_transactions = $stmt->fetchAll();

// Получаем топ категорий расходов
$stmt = $pdo->prepare("
    SELECT 
        c.name as category_name,
        c.color as category_color,
        SUM(t.amount) as total_amount
    FROM transactions t
    LEFT JOIN categories c ON t.category_id = c.id
    WHERE t.user_id = ? 
    AND t.type = 'expense'
    AND t.transaction_date BETWEEN ? AND ?
    GROUP BY t.category_id
    ORDER BY total_amount DESC
    LIMIT 3
");
$stmt->execute([$user_id, $selected_month_start, $selected_month_end]);
$top_categories = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#667eea">
    <title>Дашборд - Финансовый дневник</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        * {
            -webkit-tap-highlight-color: transparent;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        
        /* Mobile Navigation */
        .mobile-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
            padding: 8px 0;
            z-index: 1000;
            border-top: 1px solid #e9ecef;
        }
        
        .mobile-nav .nav-item {
            text-align: center;
            padding: 8px 0;
            color: #6c757d;
            transition: all 0.2s;
            border-radius: 12px;
            margin: 0 4px;
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
            font-weight: 500;
        }
        
        /* Header */
        .mobile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 16px;
            border-radius: 0 0 24px 24px;
            margin-bottom: 16px;
        }
        
        .user-greeting {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 4px;
        }
        
        .user-name {
            font-size: 20px;
            font-weight: bold;
            margin: 0;
        }
        
        /* Date Selector */
        .date-selector-mobile {
            background: rgba(255,255,255,0.2);
            border-radius: 30px;
            padding: 6px 12px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            backdrop-filter: blur(10px);
        }
        
        .date-selector-mobile input {
            background: transparent;
            border: none;
            color: white;
            font-size: 14px;
            padding: 4px;
        }
        
        .date-selector-mobile input::-webkit-calendar-picker-indicator {
            filter: invert(1);
        }
        
        .date-selector-mobile button {
            background: white;
            border: none;
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 12px;
            color: #667eea;
            font-weight: 500;
        }
        
        /* Stat Cards */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            padding: 0 16px;
            margin-bottom: 20px;
        }
        
        .stat-card-mobile {
            background: white;
            border-radius: 20px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: transform 0.2s;
        }
        
        .stat-card-mobile:active {
            transform: scale(0.98);
        }
        
        .stat-label {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .stat-value {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 4px;
        }
        
        .stat-sub {
            font-size: 10px;
            color: #adb5bd;
        }
        
        /* Section Cards */
        .section-card {
            background: white;
            border-radius: 20px;
            margin: 0 16px 16px 16px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
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
        
        /* Account Items */
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
        
        .account-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .account-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        
        .account-name {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 2px;
        }
        
        .account-number {
            font-size: 11px;
            color: #6c757d;
        }
        
        .account-balance {
            font-weight: bold;
            font-size: 16px;
        }
        
        /* Transaction Items */
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
            margin-bottom: 4px;
        }
        
        .transaction-category {
            font-size: 13px;
            font-weight: 500;
        }
        
        .transaction-amount {
            font-weight: bold;
            font-size: 15px;
        }
        
        /* Category Progress */
        .category-item {
            margin-bottom: 12px;
        }
        
        .category-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
            font-size: 13px;
        }
        
        .progress {
            height: 6px;
            border-radius: 10px;
            background-color: #e9ecef;
        }
        
        /* Buttons */
        .btn-mobile {
            width: 100%;
            padding: 12px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 14px;
            margin-top: 8px;
        }
        
        /* Color Classes */
        .text-income {
            color: #28a745;
        }
        
        .text-expense {
            color: #dc3545;
        }
        
        /* Scroll Area */
        .scroll-area {
            max-height: 400px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        /* Safe Area for Bottom Navigation */
        .pb-safe {
            padding-bottom: 70px;
        }
        
        @media (max-width: 380px) {
            .stat-value {
                font-size: 18px;
            }
            
            .account-name {
                font-size: 13px;
            }
            
            .account-balance {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Header -->
    <div class="mobile-header">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <div class="user-greeting">Добро пожаловать,</div>
                <h2 class="user-name"><?php echo htmlspecialchars($_SESSION['username']); ?></h2>
            </div>
            <div class="date-selector-mobile">
                <i class="bi bi-calendar3"></i>
                <form method="GET" action="" class="d-inline">
                    <input type="date" name="date" value="<?php echo $selected_date; ?>" onchange="this.form.submit()" style="background: transparent; color: white;">
                </form>
                <a href="dashboard.php" class="text-decoration-none">Сегодня</a>
            </div>
        </div>
        
        <!-- Quick Stats -->
        <div class="row g-2 mt-3">
            <div class="col-6">
                <div class="bg-white bg-opacity-20 rounded-3 p-2 text-center">
                    <small class="d-block opacity-75">Общий баланс</small>
                    <strong class="fs-5"><?php echo number_format($total_balance_all, 0, '.', ' '); ?> ₽</strong>
                </div>
            </div>
            <div class="col-6">
                <div class="bg-white bg-opacity-20 rounded-3 p-2 text-center">
                    <small class="d-block opacity-75">Сумма счетов</small>
                    <strong class="fs-5"><?php echo number_format($total_balance, 0, '.', ' '); ?> ₽</strong>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="pb-safe">
        <!-- Stats Grid -->
        <div class="stat-grid">
            <div class="stat-card-mobile">
                <div class="stat-label">
                    <i class="bi bi-arrow-down-circle text-danger"></i>
                    Расходы за месяц
                </div>
                <div class="stat-value text-danger">
                    <?php echo number_format($month_expenses, 0, '.', ' '); ?> ₽
                </div>
                <div class="stat-sub"><?php echo date('M Y', strtotime($selected_date)); ?></div>
            </div>
            
            <div class="stat-card-mobile">
                <div class="stat-label">
                    <i class="bi bi-arrow-up-circle text-success"></i>
                    Доходы за месяц
                </div>
                <div class="stat-value text-success">
                    <?php echo number_format($month_income, 0, '.', ' '); ?> ₽
                </div>
                <div class="stat-sub"><?php echo date('M Y', strtotime($selected_date)); ?></div>
            </div>
            
            <div class="stat-card-mobile">
                <div class="stat-label">
                    <i class="bi bi-bank"></i>
                    Активных счетов
                </div>
                <div class="stat-value">
                    <?php echo $account_count; ?>
                </div>
                <div class="stat-sub">всего счетов</div>
            </div>
            
            <div class="stat-card-mobile">
                <div class="stat-label">
                    <i class="bi bi-calendar-week text-warning"></i>
                    Предстоит потратить
                </div>
                <div class="stat-value text-warning">
                    <?php echo number_format($upcoming_expenses, 0, '.', ' '); ?> ₽
                </div>
                <div class="stat-sub"><?php echo $upcoming_count; ?> операций</div>
            </div>
        </div>
        
        <!-- Top Categories -->
        <?php if (count($top_categories) > 0): ?>
        <div class="section-card">
            <div class="section-title">
                <i class="bi bi-pie-chart text-primary"></i>
                Топ расходов
            </div>
            <?php foreach ($top_categories as $category): ?>
                <div class="category-item">
                    <div class="category-header">
                        <span>
                            <span class="badge" style="background-color: <?php echo htmlspecialchars($category['category_color']); ?>; width: 10px; height: 10px; display: inline-block; border-radius: 50%;"></span>
                            <?php echo htmlspecialchars($category['category_name']); ?>
                        </span>
                        <span class="fw-bold"><?php echo number_format($category['total_amount'], 0, '.', ' '); ?> ₽</span>
                    </div>
                    <div class="progress">
                        <?php $percentage = ($category['total_amount'] / max($month_expenses, 1)) * 100; ?>
                        <div class="progress-bar" style="width: <?php echo $percentage; ?>%; background-color: <?php echo htmlspecialchars($category['category_color']); ?>"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Accounts Section -->
        <div class="section-card">
            <div class="section-title">
                <i class="bi bi-bank text-primary"></i>
                Мои счета
                <span class="ms-auto small text-muted">на <?php echo $selected_date_formatted; ?></span>
            </div>
            <div class="scroll-area">
                <?php if (count($accounts) > 0): ?>
                    <?php foreach ($accounts as $account): ?>
                        <div class="account-item">
                            <div class="account-info">
                                <div class="account-icon" style="background: <?php echo htmlspecialchars($account['color']); ?>20; color: <?php echo htmlspecialchars($account['color']); ?>">
                                    <i class="<?php echo htmlspecialchars($account['icon']); ?>"></i>
                                </div>
                                <div>
                                    <div class="account-name"><?php echo htmlspecialchars($account['bank_name']); ?></div>
                                    <div class="account-number"><?php echo htmlspecialchars(substr($account['account_number'], -8)); ?></div>
                                </div>
                            </div>
                            <div class="account-balance <?php echo $account['current_balance'] >= 0 ? 'text-income' : 'text-expense'; ?>">
                                <?php echo number_format($account['current_balance'], 0, '.', ' '); ?> ₽
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="mt-3 pt-2 border-top">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong>Итого на счетах</strong>
                            <strong class="<?php echo $total_balance >= 0 ? 'text-income' : 'text-expense'; ?>">
                                <?php echo number_format($total_balance, 0, '.', ' '); ?> ₽
                            </strong>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-center text-muted py-3">Нет активных счетов</p>
                <?php endif; ?>
            </div>
            <a href="modules/accounts.php" class="btn btn-outline-primary btn-mobile">
                <i class="bi bi-plus-circle"></i> Управление счетами
            </a>
        </div>
        
        <!-- Recent Transactions -->
        <div class="section-card">
            <div class="section-title">
                <i class="bi bi-clock-history"></i>
                Последние операции
            </div>
            <div class="scroll-area">
                <?php if (count($recent_transactions) > 0): ?>
                    <?php foreach ($recent_transactions as $transaction): ?>
                        <div class="transaction-item">
                            <div>
                                <div class="transaction-date"><?php echo date('d.m.Y', strtotime($transaction['transaction_date'])); ?></div>
                                <div>
                                    <span class="badge" style="background-color: <?php echo htmlspecialchars($transaction['category_color']); ?>; font-size: 11px;">
                                        <?php echo htmlspecialchars($transaction['category_name']); ?>
                                    </span>
                                    <small class="text-muted ms-1"><?php echo htmlspecialchars($transaction['bank_name']); ?></small>
                                </div>
                                <?php if (!empty($transaction['description'])): ?>
                                    <div class="small text-muted mt-1"><?php echo htmlspecialchars(substr($transaction['description'], 0, 30)); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="transaction-amount <?php echo $transaction['type'] == 'income' ? 'text-income' : 'text-expense'; ?>">
                                <?php echo ($transaction['type'] == 'income' ? '+' : '-') . number_format($transaction['amount'], 0, '.', ' '); ?> ₽
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-center text-muted py-3">Нет операций</p>
                <?php endif; ?>
            </div>
            <a href="modules/finances.php" class="btn btn-primary btn-mobile">
                <i class="bi bi-plus-circle"></i> Добавить операцию
            </a>
        </div>
    </div>
    
    <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav">
        <div class="row g-0">
            <div class="col-3">
                <a href="dashboard.php" class="nav-item active d-block text-decoration-none">
                    <i class="bi bi-house-door-fill"></i>
                    <span>Главная</span>
                </a>
            </div>
            <div class="col-3">
                <a href="modules/finances.php" class="nav-item d-block text-decoration-none">
                    <i class="bi bi-calculator"></i>
                    <span>Финансы</span>
                </a>
            </div>
            <div class="col-3">
                <a href="modules/statistics.php" class="nav-item d-block text-decoration-none">
                    <i class="bi bi-graph-up"></i>
                    <span>Статистика</span>
                </a>
            </div>
            <div class="col-3">
                <a href="profile.php" class="nav-item d-block text-decoration-none">
                    <i class="bi bi-person"></i>
                    <span>Профиль</span>
                </a>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Плавная прокрутка
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });
        
        // Активное состояние навигации
        const currentPath = window.location.pathname;
        document.querySelectorAll('.mobile-nav .nav-item').forEach(item => {
            const href = item.getAttribute('href');
            if (href && currentPath.includes(href.replace('.php', ''))) {
                item.classList.add('active');
            }
        });
        
        // Оптимизация для touch
        const cards = document.querySelectorAll('.stat-card-mobile, .account-item, .transaction-item');
        cards.forEach(card => {
            card.addEventListener('touchstart', function() {
                this.style.transform = 'scale(0.98)';
            });
            card.addEventListener('touchend', function() {
                this.style.transform = '';
            });
        });
    </script>
</body>
</html>