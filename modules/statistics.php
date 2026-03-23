<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Параметры фильтрации
$period = $_GET['period'] ?? 'month'; // month, quarter, year, custom
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');
$filter_type = $_GET['filter_type'] ?? 'all'; // all, income, expense
$filter_category = $_GET['filter_category'] ?? 'all';
$filter_account = $_GET['filter_account'] ?? 'all';
$filter_tag = $_GET['filter_tag'] ?? '';
$chart_type = $_GET['chart_type'] ?? 'category'; // category, account, tag, daily

// Установка периода
switch ($period) {
    case 'month':
        $date_from = date('Y-m-01');
        $date_to = date('Y-m-t');
        break;
    case 'quarter':
        $quarter = ceil(date('n') / 3);
        $date_from = date('Y-' . (($quarter - 1) * 3 + 1) . '-01');
        $date_to = date('Y-' . ($quarter * 3) . '-t');
        break;
    case 'year':
        $date_from = date('Y-01-01');
        $date_to = date('Y-12-31');
        break;
}

// Получение данных для статистики
$sql = "SELECT t.*, c.name as category_name, c.color as category_color, 
        a.bank_name, a.account_number, a.color as account_color
        FROM transactions t 
        LEFT JOIN categories c ON t.category_id = c.id 
        LEFT JOIN accounts a ON t.account_id = a.id 
        WHERE t.user_id = ? 
        AND t.transaction_date BETWEEN ? AND ?";
$params = [$user_id, $date_from, $date_to];

if ($filter_type !== 'all') {
    $sql .= " AND t.type = ?";
    $params[] = $filter_type;
}

if ($filter_category !== 'all') {
    $sql .= " AND t.category_id = ?";
    $params[] = $filter_category;
}

if ($filter_account !== 'all') {
    $sql .= " AND t.account_id = ?";
    $params[] = $filter_account;
}

if (!empty($filter_tag)) {
    $sql .= " AND t.tags_text LIKE ?";
    $params[] = "%$filter_tag%";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Общая статистика
$total_income = 0;
$total_expense = 0;
foreach ($transactions as $trans) {
    if ($trans['type'] == 'income') {
        $total_income += $trans['amount'];
    } else {
        $total_expense += $trans['amount'];
    }
}
$balance = $total_income - $total_expense;

// Статистика по категориям
$categories_stats = [];
foreach ($transactions as $trans) {
    $key = $trans['category_name'];
    if (!isset($categories_stats[$key])) {
        $categories_stats[$key] = [
            'name' => $trans['category_name'],
            'color' => $trans['category_color'],
            'income' => 0,
            'expense' => 0,
            'total' => 0,
            'count' => 0
        ];
    }
    
    if ($trans['type'] == 'income') {
        $categories_stats[$key]['income'] += $trans['amount'];
    } else {
        $categories_stats[$key]['expense'] += $trans['amount'];
    }
    $categories_stats[$key]['total'] += $trans['amount'];
    $categories_stats[$key]['count']++;
}

// Статистика по счетам
$accounts_stats = [];
foreach ($transactions as $trans) {
    $key = $trans['bank_name'];
    if (!isset($accounts_stats[$key])) {
        $accounts_stats[$key] = [
            'name' => $trans['bank_name'],
            'color' => $trans['account_color'],
            'income' => 0,
            'expense' => 0,
            'total' => 0,
            'count' => 0
        ];
    }
    
    if ($trans['type'] == 'income') {
        $accounts_stats[$key]['income'] += $trans['amount'];
    } else {
        $accounts_stats[$key]['expense'] += $trans['amount'];
    }
    $accounts_stats[$key]['total'] += $trans['amount'];
    $accounts_stats[$key]['count']++;
}

// Статистика по меткам
$tags_stats = [];
foreach ($transactions as $trans) {
    if (!empty($trans['tags_text'])) {
        $tags = explode(';', $trans['tags_text']);
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if (empty($tag)) continue;
            
            if (!isset($tags_stats[$tag])) {
                $tags_stats[$tag] = [
                    'name' => $tag,
                    'income' => 0,
                    'expense' => 0,
                    'total' => 0,
                    'count' => 0
                ];
            }
            
            if ($trans['type'] == 'income') {
                $tags_stats[$tag]['income'] += $trans['amount'];
            } else {
                $tags_stats[$tag]['expense'] += $trans['amount'];
            }
            $tags_stats[$tag]['total'] += $trans['amount'];
            $tags_stats[$tag]['count']++;
        }
    }
}

// Дневная статистика
$daily_stats = [];
foreach ($transactions as $trans) {
    $date = $trans['transaction_date'];
    if (!isset($daily_stats[$date])) {
        $daily_stats[$date] = [
            'date' => $date,
            'income' => 0,
            'expense' => 0,
            'balance' => 0
        ];
    }
    
    if ($trans['type'] == 'income') {
        $daily_stats[$date]['income'] += $trans['amount'];
    } else {
        $daily_stats[$date]['expense'] += $trans['amount'];
    }
    $daily_stats[$date]['balance'] = $daily_stats[$date]['income'] - $daily_stats[$date]['expense'];
}
ksort($daily_stats);

// Получение списков для фильтров
$stmt = $pdo->prepare("SELECT * FROM categories WHERE user_id = ? ORDER BY name");
$stmt->execute([$user_id]);
$categories = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM accounts WHERE user_id = ? AND is_active = 1 ORDER BY bank_name");
$stmt->execute([$user_id]);
$accounts = $stmt->fetchAll();

// Экспорт в CSV
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="statistics_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Дата', 'Тип', 'Категория', 'Счет', 'Сумма', 'Описание', 'Метки']);
    
    foreach ($transactions as $trans) {
        fputcsv($output, [
            $trans['transaction_date'],
            $trans['type'] == 'income' ? 'Доход' : 'Расход',
            $trans['category_name'],
            $trans['bank_name'],
            $trans['amount'],
            $trans['description'],
            $trans['tags_text']
        ]);
    }
    
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Статистика - Финансовый дневник</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
        .filter-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .stats-table {
            max-height: 400px;
            overflow-y: auto;
        }
        .badge-stat {
            font-size: 12px;
            padding: 5px 10px;
        }
        .period-btn {
            border-radius: 25px;
            padding: 8px 20px;
            margin: 0 5px;
            transition: all 0.3s;
        }
        .period-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
        }
        .export-btn {
            border-radius: 25px;
            padding: 8px 20px;
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
                        <a class="nav-link" href="../dashboard.php">
                            <i class="bi bi-speedometer2"></i> Дашборд
                        </a>
                        <a class="nav-link" href="tags.php">
                            <i class="bi bi-tags"></i> Банк Меток
                        </a>
                        <a class="nav-link" href="categories.php">
                            <i class="bi bi-grid"></i> Банк Категорий
                        </a>
                        <a class="nav-link" href="accounts.php">
                            <i class="bi bi-bank"></i> Справочник Счетов
                        </a>
                        <a class="nav-link" href="finances.php">
                            <i class="bi bi-calculator"></i> Финансы
                        </a>
                        <a class="nav-link" href="transfers.php">
                            <i class="bi bi-arrow-left-right"></i> Переводы
                        </a>
                        <a class="nav-link active" href="statistics.php">
                            <i class="bi bi-graph-up"></i> Статистика
                        </a>
                        <hr class="bg-light">
                        <a class="nav-link" href="../profile.php">
                            <i class="bi bi-person-circle"></i> Профиль
                        </a>
                        <a class="nav-link" href="../logout.php">
                            <i class="bi bi-box-arrow-right"></i> Выход
                        </a>
                    </nav>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-10 p-0">
                <div class="main-content">
                    <div class="p-4">
                        <!-- Header -->
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div>
                                <h2><i class="bi bi-graph-up"></i> Статистика</h2>
                                <p class="text-muted">Анализ доходов и расходов за выбранный период</p>
                            </div>
                            <a href="?export=1&<?php echo http_build_query($_GET); ?>" class="btn btn-success export-btn">
                                <i class="bi bi-download"></i> Экспорт в CSV
                            </a>
                        </div>
                        
                        <!-- Filter Card -->
                        <div class="card filter-card mb-4">
                            <div class="card-body">
                                <form method="GET" action="" class="row g-3">
                                    <div class="col-md-12">
                                        <label class="form-label">Период</label>
                                        <div class="btn-group" role="group">
                                            <a href="?period=month&<?php echo http_build_query(array_merge($_GET, ['period' => 'month'])); ?>" 
                                               class="btn btn-outline-primary period-btn <?php echo $period == 'month' ? 'active' : ''; ?>">
                                                Месяц
                                            </a>
                                            <a href="?period=quarter&<?php echo http_build_query(array_merge($_GET, ['period' => 'quarter'])); ?>" 
                                               class="btn btn-outline-primary period-btn <?php echo $period == 'quarter' ? 'active' : ''; ?>">
                                                Квартал
                                            </a>
                                            <a href="?period=year&<?php echo http_build_query(array_merge($_GET, ['period' => 'year'])); ?>" 
                                               class="btn btn-outline-primary period-btn <?php echo $period == 'year' ? 'active' : ''; ?>">
                                                Год
                                            </a>
                                            <a href="?period=custom&<?php echo http_build_query(array_merge($_GET, ['period' => 'custom'])); ?>" 
                                               class="btn btn-outline-primary period-btn <?php echo $period == 'custom' ? 'active' : ''; ?>">
                                                Произвольный
                                            </a>
                                        </div>
                                    </div>
                                    
                                    <?php if ($period == 'custom'): ?>
                                    <div class="col-md-3">
                                        <label class="form-label">С даты</label>
                                        <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">По дату</label>
                                        <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="col-md-2">
                                        <label class="form-label">Тип</label>
                                        <select name="filter_type" class="form-select">
                                            <option value="all" <?php echo $filter_type == 'all' ? 'selected' : ''; ?>>Все</option>
                                            <option value="income" <?php echo $filter_type == 'income' ? 'selected' : ''; ?>>Доходы</option>
                                            <option value="expense" <?php echo $filter_type == 'expense' ? 'selected' : ''; ?>>Расходы</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Категория</label>
                                        <select name="filter_category" class="form-select">
                                            <option value="all">Все категории</option>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?php echo $cat['id']; ?>" <?php echo $filter_category == $cat['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($cat['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Счет</label>
                                        <select name="filter_account" class="form-select">
                                            <option value="all">Все счета</option>
                                            <?php foreach ($accounts as $acc): ?>
                                                <option value="<?php echo $acc['id']; ?>" <?php echo $filter_account == $acc['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($acc['bank_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Метка</label>
                                        <input type="text" name="filter_tag" class="form-control" placeholder="Поиск по меткам" value="<?php echo htmlspecialchars($filter_tag); ?>">
                                    </div>
                                    <div class="col-md-12">
                                        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Применить фильтры</button>
                                        <a href="statistics.php" class="btn btn-secondary"><i class="bi bi-arrow-repeat"></i> Сбросить</a>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Period Info -->
                        <div class="alert alert-info mb-4">
                            <i class="bi bi-calendar-range"></i> 
                            Период: <?php echo date('d.m.Y', strtotime($date_from)); ?> - <?php echo date('d.m.Y', strtotime($date_to)); ?>
                            (Всего операций: <?php echo count($transactions); ?>)
                        </div>
                        
                        <!-- Statistics Cards -->
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <div class="card stat-card bg-success text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="card-title">Доходы</h6>
                                                <h3 class="mb-0">+<?php echo number_format($total_income, 2, '.', ' '); ?> ₽</h3>
                                                <small><?php echo count(array_filter($transactions, function($t) { return $t['type'] == 'income'; })); ?> операций</small>
                                            </div>
                                            <i class="bi bi-arrow-up-circle fs-1 opacity-50"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card stat-card bg-danger text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="card-title">Расходы</h6>
                                                <h3 class="mb-0">-<?php echo number_format($total_expense, 2, '.', ' '); ?> ₽</h3>
                                                <small><?php echo count(array_filter($transactions, function($t) { return $t['type'] == 'expense'; })); ?> операций</small>
                                            </div>
                                            <i class="bi bi-arrow-down-circle fs-1 opacity-50"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card stat-card <?php echo $balance >= 0 ? 'bg-primary' : 'bg-warning'; ?> text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="card-title">Баланс</h6>
                                                <h3 class="mb-0"><?php echo number_format($balance, 2, '.', ' '); ?> ₽</h3>
                                                <small>Доходы - Расходы</small>
                                            </div>
                                            <i class="bi bi-wallet2 fs-1 opacity-50"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <!-- Category Chart -->
                            <div class="col-md-6">
                                <div class="chart-container">
                                    <h5><i class="bi bi-pie-chart"></i> Распределение по категориям</h5>
                                    <canvas id="categoryChart" width="400" height="300"></canvas>
                                </div>
                            </div>
                            
                            <!-- Account Chart -->
                            <div class="col-md-6">
                                <div class="chart-container">
                                    <h5><i class="bi bi-bank"></i> Распределение по счетам</h5>
                                    <canvas id="accountChart" width="400" height="300"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <!-- Daily Dynamics Chart -->
                            <div class="col-12">
                                <div class="chart-container">
                                    <h5><i class="bi bi-graph-up"></i> Динамика доходов и расходов</h5>
                                    <canvas id="dailyChart" width="800" height="300"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <!-- Tags Statistics -->
                            <?php if (count($tags_stats) > 0): ?>
                            <div class="col-md-6">
                                <div class="chart-container">
                                    <h5><i class="bi bi-tags"></i> Топ меток</h5>
                                    <canvas id="tagsChart" width="400" height="300"></canvas>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Top Categories Table -->
                            <div class="col-md-6">
                                <div class="chart-container">
                                    <h5><i class="bi bi-list-ul"></i> Детализация по категориям</h5>
                                    <div class="stats-table">
                                        <table class="table table-sm table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Категория</th>
                                                    <th>Доходы</th>
                                                    <th>Расходы</th>
                                                    <th>Итого</th>
                                                    <th>Операций</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                uasort($categories_stats, function($a, $b) {
                                                    return $b['total'] <=> $a['total'];
                                                });
                                                foreach ($categories_stats as $stat): 
                                                ?>
                                                <tr>
                                                    <td>
                                                        <span class="badge" style="background-color: <?php echo htmlspecialchars($stat['color']); ?>; color: white;">
                                                            <?php echo htmlspecialchars($stat['name']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-success"><?php echo number_format($stat['income'], 2, '.', ' '); ?></td>
                                                    <td class="text-danger"><?php echo number_format($stat['expense'], 2, '.', ' '); ?></td>
                                                    <td class="fw-bold"><?php echo number_format($stat['total'], 2, '.', ' '); ?></td>
                                                    <td><?php echo $stat['count']; ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Detailed Transactions Table -->
                        <div class="chart-container">
                            <h5><i class="bi bi-table"></i> Детальный список операций</h5>
                            <div class="table-responsive" style="max-height: 500px;">
                                <table class="table table-sm table-hover">
                                    <thead class="table-light sticky-top">
                                        <tr>
                                            <th>Дата</th>
                                            <th>Тип</th>
                                            <th>Категория</th>
                                            <th>Счет</th>
                                            <th>Сумма</th>
                                            <th>Описание</th>
                                            <th>Метки</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($transactions) > 0): ?>
                                            <?php foreach ($transactions as $trans): ?>
                                            <tr>
                                                <td><?php echo date('d.m.Y', strtotime($trans['transaction_date'])); ?></td>
                                                <td>
                                                    <span class="badge <?php echo $trans['type'] == 'income' ? 'bg-success' : 'bg-danger'; ?>">
                                                        <?php echo $trans['type'] == 'income' ? 'Доход' : 'Расход'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge" style="background-color: <?php echo htmlspecialchars($trans['category_color']); ?>">
                                                        <?php echo htmlspecialchars($trans['category_name']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($trans['bank_name']); ?></td>
                                                <td class="<?php echo $trans['type'] == 'income' ? 'text-success' : 'text-danger'; ?> fw-bold">
                                                    <?php echo ($trans['type'] == 'income' ? '+' : '-') . number_format($trans['amount'], 2, '.', ' '); ?> ₽
                                                </td>
                                                <td><?php echo htmlspecialchars($trans['description'] ?: '-'); ?></td>
                                                <td>
                                                    <?php if (!empty($trans['tags_text'])): ?>
                                                        <?php $tags = explode(';', $trans['tags_text']); ?>
                                                        <?php foreach ($tags as $tag): ?>
                                                            <span class="badge bg-secondary"><?php echo htmlspecialchars(trim($tag)); ?></span>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        —
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="text-center py-4">
                                                    <i class="bi bi-inbox fs-1 text-muted"></i>
                                                    <p class="text-muted mt-2">Нет операций за выбранный период</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Данные для графиков
        const categoriesData = <?php 
            $filtered_stats = $filter_type != 'all' ? 
                array_filter($categories_stats, function($stat) use ($filter_type) {
                    return $filter_type == 'income' ? $stat['income'] > 0 : $stat['expense'] > 0;
                }) : $categories_stats;
            echo json_encode(array_values($filtered_stats)); 
        ?>;
        
        const accountsData = <?php 
            $filtered_accounts = $filter_type != 'all' ?
                array_filter($accounts_stats, function($stat) use ($filter_type) {
                    return $filter_type == 'income' ? $stat['income'] > 0 : $stat['expense'] > 0;
                }) : $accounts_stats;
            echo json_encode(array_values($filtered_accounts)); 
        ?>;
        
        const dailyData = <?php echo json_encode(array_values($daily_stats)); ?>;
        const tagsData = <?php 
            $top_tags = array_slice($tags_stats, 0, 10);
            echo json_encode(array_values($top_tags)); 
        ?>;
        
        // Категории график
        if (categoriesData.length > 0) {
            const categoryCtx = document.getElementById('categoryChart').getContext('2d');
            new Chart(categoryCtx, {
                type: 'pie',
                data: {
                    labels: categoriesData.map(item => item.name),
                    datasets: [{
                        data: categoriesData.map(item => <?php echo $filter_type != 'all' ? ($filter_type == 'income' ? 'item.income' : 'item.expense') : 'item.total'; ?>),
                        backgroundColor: categoriesData.map(item => item.color),
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${label}: ${value.toLocaleString('ru-RU', {minimumFractionDigits: 2})} ₽ (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Счета график
        if (accountsData.length > 0) {
            const accountCtx = document.getElementById('accountChart').getContext('2d');
            const colors = ['#667eea', '#764ba2', '#f093fb', '#4facfe', '#00f2fe', '#43e97b', '#fa709a', '#fee140'];
            new Chart(accountCtx, {
                type: 'doughnut',
                data: {
                    labels: accountsData.map(item => item.name),
                    datasets: [{
                        data: accountsData.map(item => <?php echo $filter_type != 'all' ? ($filter_type == 'income' ? 'item.income' : 'item.expense') : 'item.total'; ?>),
                        backgroundColor: colors,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${label}: ${value.toLocaleString('ru-RU', {minimumFractionDigits: 2})} ₽ (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Динамика по дням
        if (dailyData.length > 0) {
            const dailyCtx = document.getElementById('dailyChart').getContext('2d');
            new Chart(dailyCtx, {
                type: 'line',
                data: {
                    labels: dailyData.map(item => {
                        const date = new Date(item.date);
                        return date.toLocaleDateString('ru-RU');
                    }),
                    datasets: [
                        {
                            label: 'Доходы',
                            data: dailyData.map(item => item.income),
                            borderColor: '#28a745',
                            backgroundColor: 'rgba(40, 167, 69, 0.1)',
                            tension: 0.4,
                            fill: true
                        },
                        {
                            label: 'Расходы',
                            data: dailyData.map(item => item.expense),
                            borderColor: '#dc3545',
                            backgroundColor: 'rgba(220, 53, 69, 0.1)',
                            tension: 0.4,
                            fill: true
                        },
                        {
                            label: 'Баланс',
                            data: dailyData.map(item => item.balance),
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
        
        // Метки график
        if (tagsData.length > 0) {
            const tagsCtx = document.getElementById('tagsChart').getContext('2d');
            const tagColors = ['#667eea', '#764ba2', '#f093fb', '#4facfe', '#00f2fe', '#43e97b', '#fa709a', '#fee140', '#f6d365', '#fda085'];
            new Chart(tagsCtx, {
                type: 'bar',
                data: {
                    labels: tagsData.map(item => item.name),
                    datasets: [{
                        label: 'Сумма операций',
                        data: tagsData.map(item => item.total),
                        backgroundColor: tagColors.slice(0, tagsData.length),
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const value = context.raw || 0;
                                    return `Сумма: ${value.toLocaleString('ru-RU', {minimumFractionDigits: 2})} ₽`;
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
    </script>
</body>
</html>