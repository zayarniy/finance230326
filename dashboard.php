<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Получаем общую сумму всех счетов
$stmt = $pdo->prepare("SELECT SUM(current_balance) as total_balance FROM accounts WHERE user_id = ? AND is_active = 1");
$stmt->execute([$user_id]);
$total_balance = $stmt->fetch()['total_balance'] ?? 0;

// Получаем сумму предстоящих расходов (расходы за текущий месяц)
$current_month_start = date('Y-m-01');
$current_month_end = date('Y-m-t');

$stmt = $pdo->prepare("
    SELECT SUM(amount) as total_expenses 
    FROM transactions 
    WHERE user_id = ? 
    AND type = 'expense' 
    AND transaction_date BETWEEN ? AND ?
");
$stmt->execute([$user_id, $current_month_start, $current_month_end]);
$total_expenses = $stmt->fetch()['total_expenses'] ?? 0;

// Получаем статистику по счетам
$stmt = $pdo->prepare("
    SELECT account_number, bank_name, current_balance, color, icon 
    FROM accounts 
    WHERE user_id = ? AND is_active = 1 
    ORDER BY current_balance DESC
");
$stmt->execute([$user_id]);
$accounts = $stmt->fetchAll();

// Получаем последние 5 транзакций
$stmt = $pdo->prepare("
    SELECT t.*, c.name as category_name, c.color as category_color, a.account_number, a.bank_name
    FROM transactions t
    LEFT JOIN categories c ON t.category_id = c.id
    LEFT JOIN accounts a ON t.account_id = a.id
    WHERE t.user_id = ?
    ORDER BY t.transaction_date DESC, t.created_at DESC
    LIMIT 5
");
$stmt->execute([$user_id]);
$recent_transactions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Дашборд - Финансовый дневник</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
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
        .sidebar .nav-link:hover {
            background: rgba(255,255,255,0.1);
            color: white;
            transform: translateX(5px);
        }
        .sidebar .nav-link.active {
            background: rgba(255,255,255,0.2);
            color: white;
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
        .module-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
        }
        .module-badge:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(102,126,234,0.3);
        }
        .module-badge i {
            font-size: 48px;
            margin-bottom: 10px;
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
                        <div>
                            <span class="text-muted me-3">
                                <i class="bi bi-calendar3"></i> <?php echo date('d.m.Y'); ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="main-content p-4">
                    <!-- Статистические карточки -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card stat-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-2">Общая сумма счетов</h6>
                                            <h3 class="mb-0"><?php echo number_format($total_balance, 2, '.', ' '); ?> ₽</h3>
                                        </div>
                                        <div class="stat-icon" style="background: rgba(40, 167, 69, 0.1); color: #28a745;">
                                            <i class="bi bi-wallet"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card stat-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-2">Расходы за этот месяц</h6>
                                            <h3 class="mb-0"><?php echo number_format($total_expenses, 2, '.', ' '); ?> ₽</h3>
                                            <small class="text-muted">Предстоящие и текущие расходы</small>
                                        </div>
                                        <div class="stat-icon" style="background: rgba(220, 53, 69, 0.1); color: #dc3545;">
                                            <i class="bi bi-cart"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Счета -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5><i class="bi bi-bank"></i> Мои счета</h5>
                                <a href="modules/accounts.php" class="btn btn-sm btn-outline-primary">Управление счетами</a>
                            </div>
                            <div class="row">
                                <?php foreach ($accounts as $account): ?>
                                    <div class="col-md-4 mb-3">
                                        <div class="card account-card" style="border-left-color: <?php echo htmlspecialchars($account['color']); ?>">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <i class="<?php echo htmlspecialchars($account['icon']); ?>" style="font-size: 24px; color: <?php echo htmlspecialchars($account['color']); ?>"></i>
                                                        <h6 class="mt-2 mb-0"><?php echo htmlspecialchars($account['bank_name']); ?></h6>
                                                        <small class="text-muted"><?php echo htmlspecialchars($account['account_number']); ?></small>
                                                    </div>
                                                    <h5 class="mb-0"><?php echo number_format($account['current_balance'], 2, '.', ' '); ?> ₽</h5>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Последние транзакции -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="bi bi-clock-history"></i> Последние операции</h5>
                                </div>
                                <div class="card-body">
                                    <?php if (count($recent_transactions) > 0): ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Дата</th>
                                                        <th>Тип</th>
                                                        <th>Категория</th>
                                                        <th>Счет</th>
                                                        <th>Сумма</th>
                                                        <th>Описание</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($recent_transactions as $transaction): ?>
                                                        <tr>
                                                            <td><?php echo date('d.m.Y', strtotime($transaction['transaction_date'])); ?></td>
                                                            <td>
                                                                <?php if ($transaction['type'] == 'income'): ?>
                                                                    <span class="badge bg-success">Приход</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-danger">Расход</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <span class="badge" style="background-color: <?php echo htmlspecialchars($transaction['category_color']); ?>">
                                                                    <?php echo htmlspecialchars($transaction['category_name']); ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($transaction['bank_name']); ?></td>
                                                            <td class="<?php echo $transaction['type'] == 'income' ? 'text-success' : 'text-danger'; ?>">
                                                                <?php echo ($transaction['type'] == 'income' ? '+' : '-') . number_format($transaction['amount'], 2, '.', ' '); ?> ₽
                                                            </td>
                                                            <td><?php echo htmlspecialchars($transaction['description'] ?? '-'); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-center text-muted my-4">Нет операций. Добавьте первую запись в разделе "Финансы"</p>
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
</body>
</html>