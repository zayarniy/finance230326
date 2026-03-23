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

// Получаем начальную дату для расчетов (дата первого открытия счета или дата первой операции)
$stmt = $pdo->prepare("
    SELECT MIN(opening_date) as min_date 
    FROM accounts 
    WHERE user_id = ? AND opening_date IS NOT NULL
");
$stmt->execute([$user_id]);
$result = $stmt->fetch();
$start_date = $result['min_date'] ?? date('Y-m-d', strtotime('-1 year'));

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

// Получаем предстоящие расходы (будущие расходы после выбранной даты)
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

// Получаем расходы за текущий месяц (относительно выбранной даты)
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

// Получаем средний баланс по счетам
$average_balance = $account_count > 0 ? $total_balance / $account_count : 0;

// Получаем последние 5 транзакций до выбранной даты
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

// Получаем предстоящие транзакции (после выбранной даты)
$stmt = $pdo->prepare("
    SELECT t.*, c.name as category_name, c.color as category_color,
           a.account_number, a.bank_name
    FROM transactions t
    LEFT JOIN categories c ON t.category_id = c.id
    LEFT JOIN accounts a ON t.account_id = a.id
    WHERE t.user_id = ? AND t.transaction_date > ?
    ORDER BY t.transaction_date ASC
    LIMIT 5
");
$stmt->execute([$user_id, $selected_date]);
$upcoming_transactions = $stmt->fetchAll();

// Получаем динамику баланса за последние 12 месяцев
$balance_history = [];
for ($i = 11; $i >= 0; $i--) {
    $date = new DateTime($selected_date);
    $date->modify("-$i months");
    $month_start = $date->format('Y-m-01');
    $month_end = $date->format('Y-m-t');
    $month_name = $date->format('M Y');
    
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as month_income,
            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as month_expense
        FROM transactions 
        WHERE user_id = ? AND transaction_date <= ?
    ");
    $stmt->execute([$user_id, $month_end]);
    $month_data = $stmt->fetch();
    
    $balance_history[] = [
        'month' => $month_name,
        'income' => $month_data['month_income'] ?? 0,
        'expense' => $month_data['month_expense'] ?? 0,
        'balance' => ($month_data['month_income'] ?? 0) - ($month_data['month_expense'] ?? 0)
    ];
}

// Получаем топ категорий расходов за выбранный месяц
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
    LIMIT 5
");
$stmt->execute([$user_id, $selected_month_start, $selected_month_end]);
$top_categories = $stmt->fetchAll();

// Получаем топ категорий доходов за выбранный месяц
$stmt = $pdo->prepare("
    SELECT 
        c.name as category_name,
        c.color as category_color,
        SUM(t.amount) as total_amount
    FROM transactions t
    LEFT JOIN categories c ON t.category_id = c.id
    WHERE t.user_id = ? 
    AND t.type = 'income'
    AND t.transaction_date BETWEEN ? AND ?
    GROUP BY t.category_id
    ORDER BY total_amount DESC
    LIMIT 5
");
$stmt->execute([$user_id, $selected_month_start, $selected_month_end]);
$top_income_categories = $stmt->fetchAll();

// Получаем статистику по дням месяца
$daily_stats = [];
for ($day = 1; $day <= date('t', strtotime($selected_date)); $day++) {
    $current_date = date('Y-m-d', strtotime("$selected_month_start + " . ($day - 1) . " days"));
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as daily_income,
            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as daily_expense
        FROM transactions
        WHERE user_id = ? AND transaction_date = ?
    ");
    $stmt->execute([$user_id, $current_date]);
    $day_data = $stmt->fetch();
    
    $daily_stats[] = [
        'day' => $day,
        'date' => $current_date,
        'income' => $day_data['daily_income'] ?? 0,
        'expense' => $day_data['daily_expense'] ?? 0
    ];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Дашборд - Финансовый дневник</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 12px 20px;
            margin: 5px 0;
            border-radius: 10px;
            transition: all 0.3s;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
            transform: translateX(5px);
        }
        .sidebar .nav-link i {
            margin-right: 10px;
        }
        .main-content {
            background-color: #f8f9fa;
            min-height: 100vh;
        }
        .stat-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: transform 0.3s;
            cursor: pointer;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }
        .account-card {
            border-radius: 12px;
            border-left: 4px solid;
            transition: transform 0.2s;
        }
        .account-card:hover {
            transform: translateX(5px);
        }
        .navbar-top {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .date-selector {
            background: white;
            border-radius: 25px;
            padding: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .date-selector input {
            border: none;
            padding: 8px 15px;
            border-radius: 25px;
            font-size: 14px;
        }
        .date-selector button {
            border-radius: 25px;
            padding: 8px 20px;
        }
        .balance-positive {
            color: #28a745;
        }
        .balance-negative {
            color: #dc3545;
        }
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .upcoming-badge {
            background: #ffc107;
            color: #000;
        }
        .stat-detail {
            font-size: 12px;
            margin-top: 5px;
            opacity: 0.8;
        }
        .progress-bar-custom {
            transition: width 0.6s ease;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 p-0">
                <div class="sidebar">
                    <div class="text-center py-4">
                        <i class="bi bi-wallet2" style="font-size: 48px; color: white;"></i>
                        <h5 class="text-white mt-2"><?php echo htmlspecialchars($_SESSION['username']); ?></h5>
                        <small class="text-white-50">Финансовый дневник</small>
                    </div>
                    <nav class="nav flex-column px-3">
                        <a class="nav-link active" href="dashboard.php">
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
                        <a class="nav-link" href="profile.php">
                            <i class="bi bi-person-circle"></i> Профиль
                        </a>
                        <a class="nav-link" href="logout.php">
                            <i class="bi bi-box-arrow-right"></i> Выход
                        </a>
                    </nav>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-10 p-0">
                <div class="navbar-top px-4 py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">Дашборд</h4>
                        <div class="date-selector">
                            <form method="GET" action="" class="d-flex gap-2 align-items-center">
                                <i class="bi bi-calendar3 text-muted"></i>
                                <input type="date" name="date" value="<?php echo $selected_date; ?>" class="form-control form-control-sm" style="width: auto;">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="bi bi-eye"></i> Показать
                                </button>
                                <a href="dashboard.php" class="btn btn-secondary btn-sm">
                                    <i class="bi bi-arrow-repeat"></i> Сегодня
                                </a>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="main-content p-4">
                    <!-- Date Info -->
                    <div class="alert alert-info mb-4">
                        <i class="bi bi-info-circle"></i> 
                        Данные на <strong><?php echo $selected_date_formatted; ?></strong>
                        <?php if ($selected_date != date('Y-m-d')): ?>
                            <span class="text-muted">(исторический срез)</span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Статистические карточки (6 карточек) -->
                    <div class="row mb-4">
                        <div class="col-md-4 col-lg-2 mb-3">
                            <div class="card stat-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-2">Общий баланс</h6>
                                            <h4 class="mb-0 <?php echo $total_balance_all >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                                <?php echo number_format($total_balance_all, 2, '.', ' '); ?>
                                            </h4>
                                            <small class="text-muted">₽</small>
                                            <div class="stat-detail">на <?php echo $selected_date_formatted; ?></div>
                                        </div>
                                        <div class="stat-icon" style="background: rgba(102, 126, 234, 0.1); color: #667eea;">
                                            <i class="bi bi-wallet2"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 col-lg-2 mb-3">
                            <div class="card stat-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-2">Сумма счетов</h6>
                                            <h4 class="mb-0 <?php echo $total_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                                <?php echo number_format($total_balance, 2, '.', ' '); ?>
                                            </h4>
                                            <small class="text-muted">₽</small>
                                            <div class="stat-detail"><?php echo $account_count; ?> счетов</div>
                                        </div>
                                        <div class="stat-icon" style="background: rgba(13, 110, 253, 0.1); color: #0d6efd;">
                                            <i class="bi bi-bank"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 col-lg-2 mb-3">
                            <div class="card stat-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-2">Расходы за месяц</h6>
                                            <h4 class="mb-0 text-danger">
                                                <?php echo number_format($month_expenses, 2, '.', ' '); ?>
                                            </h4>
                                            <small class="text-muted">₽</small>
                                            <div class="stat-detail"><?php echo date('F Y', strtotime($selected_date)); ?></div>
                                        </div>
                                        <div class="stat-icon" style="background: rgba(220, 53, 69, 0.1); color: #dc3545;">
                                            <i class="bi bi-cart"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 col-lg-2 mb-3">
                            <div class="card stat-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-2">Доходы за месяц</h6>
                                            <h4 class="mb-0 text-success">
                                                <?php echo number_format($month_income, 2, '.', ' '); ?>
                                            </h4>
                                            <small class="text-muted">₽</small>
                                            <div class="stat-detail"><?php echo date('F Y', strtotime($selected_date)); ?></div>
                                        </div>
                                        <div class="stat-icon" style="background: rgba(40, 167, 69, 0.1); color: #28a745;">
                                            <i class="bi bi-graph-up"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 col-lg-2 mb-3">
                            <div class="card stat-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-2">Средний баланс счета</h6>
                                            <h4 class="mb-0 <?php echo $average_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                                <?php echo number_format($average_balance, 2, '.', ' '); ?>
                                            </h4>
                                            <small class="text-muted">₽</small>
                                            <div class="stat-detail">на 1 счет</div>
                                        </div>
                                        <div class="stat-icon" style="background: rgba(32, 201, 151, 0.1); color: #20c997;">
                                            <i class="bi bi-calculator"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 col-lg-2 mb-3">
                            <div class="card stat-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-2">Предстоящие расходы</h6>
                                            <h4 class="mb-0 text-warning">
                                                <?php echo number_format($upcoming_expenses, 2, '.', ' '); ?>
                                            </h4>
                                            <small class="text-muted">₽</small>
                                            <div class="stat-detail"><?php echo $upcoming_count; ?> операций</div>
                                        </div>
                                        <div class="stat-icon" style="background: rgba(255, 193, 7, 0.1); color: #ffc107;">
                                            <i class="bi bi-calendar-week"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Balance History Chart -->
                    <div class="chart-container">
                        <h5><i class="bi bi-graph-up"></i> Динамика баланса за последние 12 месяцев</h5>
                        <canvas id="balanceChart" width="800" height="300"></canvas>
                    </div>
                    
                    <div class="row">
                        <!-- Daily Stats for Month -->
                        <div class="col-md-6 mb-4">
                            <div class="chart-container">
                                <h5><i class="bi bi-calendar-day"></i> Ежедневная динамика за месяц</h5>
                                <canvas id="dailyChart" width="400" height="250"></canvas>
                            </div>
                        </div>
                        
                        <!-- Top Categories -->
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="bi bi-pie-chart"></i> Топ категорий расходов</h5>
                                    <small class="text-muted">за <?php echo date('F Y', strtotime($selected_date)); ?></small>
                                </div>
                                <div class="card-body">
                                    <?php if (count($top_categories) > 0): ?>
                                        <?php foreach ($top_categories as $category): ?>
                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <span>
                                                        <span class="badge" style="background-color: <?php echo htmlspecialchars($category['category_color']); ?>">
                                                            <?php echo htmlspecialchars($category['category_name']); ?>
                                                        </span>
                                                    </span>
                                                    <span class="fw-bold"><?php echo number_format($category['total_amount'], 2, '.', ' '); ?> ₽</span>
                                                </div>
                                                <div class="progress" style="height: 8px;">
                                                    <?php 
                                                    $percentage = ($category['total_amount'] / max($month_expenses, 1)) * 100;
                                                    ?>
                                                    <div class="progress-bar progress-bar-custom" style="width: <?php echo $percentage; ?>%; background-color: <?php echo htmlspecialchars($category['category_color']); ?>"></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="text-center text-muted my-4">Нет данных за выбранный месяц</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <!-- Top Income Categories -->
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="bi bi-arrow-up-circle"></i> Топ категорий доходов</h5>
                                    <small class="text-muted">за <?php echo date('F Y', strtotime($selected_date)); ?></small>
                                </div>
                                <div class="card-body">
                                    <?php if (count($top_income_categories) > 0): ?>
                                        <?php foreach ($top_income_categories as $category): ?>
                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <span>
                                                        <span class="badge" style="background-color: <?php echo htmlspecialchars($category['category_color']); ?>">
                                                            <?php echo htmlspecialchars($category['category_name']); ?>
                                                        </span>
                                                    </span>
                                                    <span class="fw-bold text-success">+<?php echo number_format($category['total_amount'], 2, '.', ' '); ?> ₽</span>
                                                </div>
                                                <div class="progress" style="height: 8px;">
                                                    <?php 
                                                    $percentage = ($category['total_amount'] / max($month_income, 1)) * 100;
                                                    ?>
                                                    <div class="progress-bar progress-bar-custom" style="width: <?php echo $percentage; ?>%; background-color: <?php echo htmlspecialchars($category['category_color']); ?>"></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="text-center text-muted my-4">Нет данных за выбранный месяц</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Accounts -->
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header bg-white">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0"><i class="bi bi-bank"></i> Мои счета</h5>
                                        <span class="text-muted">на <?php echo $selected_date_formatted; ?></span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <?php if (count($accounts) > 0): ?>
                                        <?php foreach ($accounts as $account): ?>
                                            <div class="account-card card mb-2" style="border-left-color: <?php echo htmlspecialchars($account['color']); ?>">
                                                <div class="card-body p-3">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <i class="<?php echo htmlspecialchars($account['icon']); ?>" style="font-size: 20px; color: <?php echo htmlspecialchars($account['color']); ?>"></i>
                                                            <strong><?php echo htmlspecialchars($account['bank_name']); ?></strong>
                                                            <br>
                                                            <small class="text-muted"><?php echo htmlspecialchars($account['account_number']); ?></small>
                                                        </div>
                                                        <div class="text-end">
                                                            <h6 class="mb-0 <?php echo $account['current_balance'] >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                                                <?php echo number_format($account['current_balance'], 2, '.', ' '); ?> ₽
                                                            </h6>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="mt-3 pt-2 border-top">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <strong>Итого:</strong>
                                                <strong class="<?php echo $total_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                                    <?php echo number_format($total_balance, 2, '.', ' '); ?> ₽
                                                </strong>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-center text-muted my-4">Нет активных счетов</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <!-- Recent Transactions -->
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="bi bi-clock-history"></i> Последние операции</h5>
                                    <small class="text-muted">до <?php echo $selected_date_formatted; ?></small>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (count($recent_transactions) > 0): ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($recent_transactions as $transaction): ?>
                                                <div class="list-group-item">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <small class="text-muted"><?php echo date('d.m.Y', strtotime($transaction['transaction_date'])); ?></small>
                                                            <br>
                                                            <span class="badge" style="background-color: <?php echo htmlspecialchars($transaction['category_color']); ?>">
                                                                <?php echo htmlspecialchars($transaction['category_name']); ?>
                                                            </span>
                                                            <small class="text-muted"><?php echo htmlspecialchars($transaction['bank_name']); ?></small>
                                                        </div>
                                                        <div class="text-end">
                                                            <span class="<?php echo $transaction['type'] == 'income' ? 'text-success' : 'text-danger'; ?> fw-bold">
                                                                <?php echo ($transaction['type'] == 'income' ? '+' : '-') . number_format($transaction['amount'], 2, '.', ' '); ?> ₽
                                                            </span>
                                                            <br>
                                                            <small class="text-muted"><?php echo htmlspecialchars($transaction['description'] ?? '-'); ?></small>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center py-4">
                                            <i class="bi bi-inbox fs-1 text-muted"></i>
                                            <p class="text-muted mt-2">Нет операций до выбранной даты</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Upcoming Transactions -->
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="bi bi-calendar-event"></i> Предстоящие операции</h5>
                                    <small class="text-muted">после <?php echo $selected_date_formatted; ?></small>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (count($upcoming_transactions) > 0): ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($upcoming_transactions as $transaction): ?>
                                                <div class="list-group-item">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <small class="text-muted"><?php echo date('d.m.Y', strtotime($transaction['transaction_date'])); ?></small>
                                                            <br>
                                                            <span class="badge" style="background-color: <?php echo htmlspecialchars($transaction['category_color']); ?>">
                                                                <?php echo htmlspecialchars($transaction['category_name']); ?>
                                                            </span>
                                                            <small class="text-muted"><?php echo htmlspecialchars($transaction['bank_name']); ?></small>
                                                        </div>
                                                        <div class="text-end">
                                                            <span class="text-danger fw-bold">
                                                                -<?php echo number_format($transaction['amount'], 2, '.', ' '); ?> ₽
                                                            </span>
                                                            <br>
                                                            <small class="text-muted"><?php echo htmlspecialchars($transaction['description'] ?? '-'); ?></small>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if ($upcoming_count > 5): ?>
                                            <div class="text-center p-3">
                                                <a href="modules/finances.php" class="btn btn-sm btn-outline-primary">
                                                    Показать все <?php echo $upcoming_count; ?> операции
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="text-center py-4">
                                            <i class="bi bi-calendar-check fs-1 text-muted"></i>
                                            <p class="text-muted mt-2">Нет предстоящих операций</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // График динамики баланса
        const balanceHistory = <?php echo json_encode($balance_history); ?>;
        
        if (balanceHistory.length > 0) {
            const ctx = document.getElementById('balanceChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: balanceHistory.map(item => item.month),
                    datasets: [
                        {
                            label: 'Доходы',
                            data: balanceHistory.map(item => item.income),
                            borderColor: '#28a745',
                            backgroundColor: 'rgba(40, 167, 69, 0.1)',
                            tension: 0.4,
                            fill: true
                        },
                        {
                            label: 'Расходы',
                            data: balanceHistory.map(item => item.expense),
                            borderColor: '#dc3545',
                            backgroundColor: 'rgba(220, 53, 69, 0.1)',
                            tension: 0.4,
                            fill: true
                        },
                        {
                            label: 'Баланс',
                            data: balanceHistory.map(item => item.balance),
                            borderColor: '#667eea',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            tension: 0.4,
                            fill: true,
                            borderWidth: 2
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${context.dataset.label}: ${context.raw.toLocaleString('ru-RU', {minimumFractionDigits: 2})} ₽`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString('ru-RU') + ' ₽';
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Ежедневная динамика за месяц
        const dailyStats = <?php echo json_encode($daily_stats); ?>;
        
        if (dailyStats.length > 0) {
            const dailyCtx = document.getElementById('dailyChart').getContext('2d');
            new Chart(dailyCtx, {
                type: 'bar',
                data: {
                    labels: dailyStats.map(item => item.day),
                    datasets: [
                        {
                            label: 'Доходы',
                            data: dailyStats.map(item => item.income),
                            backgroundColor: 'rgba(40, 167, 69, 0.7)',
                            borderColor: '#28a745',
                            borderWidth: 1
                        },
                        {
                            label: 'Расходы',
                            data: dailyStats.map(item => item.expense),
                            backgroundColor: 'rgba(220, 53, 69, 0.7)',
                            borderColor: '#dc3545',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${context.dataset.label}: ${context.raw.toLocaleString('ru-RU', {minimumFractionDigits: 2})} ₽`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString('ru-RU') + ' ₽';
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'День месяца'
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>