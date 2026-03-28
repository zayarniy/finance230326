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
$period = $_GET['period'] ?? 'month';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');
$filter_type = $_GET['filter_type'] ?? 'all'; // all, income, expense
$filter_category = $_GET['filter_category'] ?? 'all';
$filter_account = $_GET['filter_account'] ?? 'all';
$filter_tag = $_GET['filter_tag'] ?? '';
// По умолчанию исключаем переводы (show_transfers = 0)
$show_transfers = isset($_GET['show_transfers']) && $_GET['show_transfers'] == '1';

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

// Построение SQL запроса для получения данных
$sql = "SELECT t.*, c.name as category_name, c.color as category_color, 
        a.bank_name, a.account_number, a.color as account_color
        FROM transactions t 
        LEFT JOIN categories c ON t.category_id = c.id 
        LEFT JOIN accounts a ON t.account_id = a.id 
        WHERE t.user_id = ? 
        AND t.transaction_date BETWEEN ? AND ?";
$params = [$user_id, $date_from, $date_to];

// Фильтр по типу
if ($filter_type !== 'all') {
    $sql .= " AND t.type = ?";
    $params[] = $filter_type;
} else {
    // Если не выбран конкретный тип, исключаем переводы (если чекбокс не отмечен)
    if (!$show_transfers) {
        $sql .= " AND t.type != 'transfer' AND t.description NOT LIKE '%Перевод%'";
    }
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
$total_transfers = 0;

foreach ($transactions as $trans) {
    if ($trans['type'] == 'income') {
        $total_income += $trans['amount'];
    } elseif ($trans['type'] == 'expense') {
        $total_expense += $trans['amount'];
    } elseif ($trans['type'] == 'transfer') {
        $total_transfers += $trans['amount'];
    }
}
$balance = $total_income - $total_expense;

// Статистика по категориям (исключаем переводы, если чекбокс не отмечен)
$categories_stats = [];
foreach ($transactions as $trans) {
    // Пропускаем переводы, если чекбокс не отмечен
    if (!$show_transfers && ($trans['type'] == 'transfer' || strpos($trans['description'], 'Перевод') !== false)) {
        continue;
    }

    $key = $trans['category_name'];
    if (!isset($categories_stats[$key])) {
        $categories_stats[$key] = [
            'name' => $trans['category_name'],
            'color' => $trans['category_color'],
            'total' => 0,
            'count' => 0,
            'type' => $trans['type']
        ];
    }
    $categories_stats[$key]['total'] += $trans['amount'];
    $categories_stats[$key]['count']++;
}

// Фильтруем категории по типу, если выбран конкретный тип
if ($filter_type !== 'all') {
    $categories_stats = array_filter($categories_stats, function ($cat) use ($filter_type) {
        return $cat['type'] == $filter_type;
    });
}

// Сортируем по убыванию суммы
uasort($categories_stats, function ($a, $b) {
    return $b['total'] <=> $a['total'];
});
$categories_stats = array_slice($categories_stats, 0, 10);

// Статистика по счетам
$accounts_stats = [];
foreach ($transactions as $trans) {
    // Пропускаем переводы, если чекбокс не отмечен
    if (!$show_transfers && ($trans['type'] == 'transfer' || strpos($trans['description'], 'Перевод') !== false)) {
        continue;
    }

    $key = $trans['bank_name'];
    if (!isset($accounts_stats[$key])) {
        $accounts_stats[$key] = [
            'name' => $trans['bank_name'],
            'color' => $trans['account_color'],
            'income' => 0,
            'expense' => 0,
            'total' => 0
        ];
    }

    if ($trans['type'] == 'income') {
        $accounts_stats[$key]['income'] += $trans['amount'];
    } elseif ($trans['type'] == 'expense') {
        $accounts_stats[$key]['expense'] += $trans['amount'];
    }
    $accounts_stats[$key]['total'] += $trans['amount'];
}

// Статистика по меткам
$tags_stats = [];
foreach ($transactions as $trans) {
    // Пропускаем переводы, если чекбокс не отмечен
    if (!$show_transfers && ($trans['type'] == 'transfer' || strpos($trans['description'], 'Перевод') !== false)) {
        continue;
    }

    if (!empty($trans['tags_text'])) {
        $tags = explode(';', $trans['tags_text']);
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if (empty($tag))
                continue;

            if (!isset($tags_stats[$tag])) {
                $tags_stats[$tag] = [
                    'name' => $tag,
                    'total' => 0,
                    'count' => 0
                ];
            }
            $tags_stats[$tag]['total'] += $trans['amount'];
            $tags_stats[$tag]['count']++;
        }
    }
}
uasort($tags_stats, function ($a, $b) {
    return $b['total'] <=> $a['total'];
});
$tags_stats = array_slice($tags_stats, 0, 5);

// Дневная статистика
$daily_stats = [];
foreach ($transactions as $trans) {
    // Пропускаем переводы, если чекбокс не отмечен
    if (!$show_transfers && ($trans['type'] == 'transfer' || strpos($trans['description'], 'Перевод') !== false)) {
        continue;
    }

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
    } elseif ($trans['type'] == 'expense') {
        $daily_stats[$date]['expense'] += $trans['amount'];
    }
    $daily_stats[$date]['balance'] = $daily_stats[$date]['income'] - $daily_stats[$date]['expense'];
}
ksort($daily_stats);
$daily_stats = array_slice($daily_stats, -7, 7);

// Получение списков для фильтров (исключаем категории переводов)
$stmt = $pdo->prepare("SELECT * FROM categories WHERE user_id = ? AND type != 'transfer' ORDER BY name");
$stmt->execute([$user_id]);
$categories = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM accounts WHERE user_id = ? AND is_active = 1 ORDER BY bank_name");
$stmt->execute([$user_id]);
$accounts = $stmt->fetchAll();

// Получаем все метки из транзакций
$tags_list = [];
$tag_sql = "SELECT tags_text FROM transactions WHERE user_id = ? AND tags_text IS NOT NULL AND tags_text != ''";
if (!$show_transfers) {
    $tag_sql .= " AND type != 'transfer' AND description NOT LIKE '%Перевод%'";
}
$stmt = $pdo->prepare($tag_sql);
$stmt->execute([$user_id]);
while ($row = $stmt->fetch()) {
    $parts = explode(';', $row['tags_text']);
    foreach ($parts as $tag) {
        $tag = trim($tag);
        if (!empty($tag) && !in_array($tag, $tags_list)) {
            $tags_list[] = $tag;
        }
    }
}
sort($tags_list);

// Функция для построения URL с сохранением параметров
function buildFilterUrl($params = [])
{
    $currentParams = $_GET;
    foreach ($params as $key => $value) {
        if ($value === null) {
            unset($currentParams[$key]);
        } else {
            $currentParams[$key] = $value;
        }
    }
    return '?' . http_build_query($currentParams);
}

// Экспорт в CSV
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="statistics_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Дата', 'Тип', 'Категория', 'Счет', 'Сумма', 'Описание', 'Метки']);

    foreach ($transactions as $trans) {
        fputcsv($output, [
            $trans['transaction_date'],
            $trans['type'] == 'income' ? 'Доход' : ($trans['type'] == 'expense' ? 'Расход' : 'Перевод'),
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
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#667eea">
    <title>Статистика - Финансовый дневник</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            padding: 0 16px;
            margin-bottom: 16px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 12px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .stat-value {
            font-size: 18px;
            font-weight: bold;
            margin: 6px 0;
        }

        .stat-label {
            font-size: 11px;
            color: #6c757d;
        }

        .period-bar {
            background: white;
            margin: 0 16px 16px;
            border-radius: 20px;
            padding: 8px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .period-btn {
            flex: 1;
            padding: 10px;
            border-radius: 30px;
            text-align: center;
            text-decoration: none;
            background: #f8f9fa;
            color: #6c757d;
            font-size: 13px;
        }

        .period-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .filter-bar {
            background: white;
            margin: 0 16px 16px;
            border-radius: 20px;
            padding: 12px;
        }

        .filter-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #e9ecef;
        }

        .filter-checkbox input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .filter-checkbox label {
            font-size: 13px;
            color: #495057;
            cursor: pointer;
            margin: 0;
        }

        .chart-container {
            background: white;
            margin: 0 16px 16px;
            border-radius: 20px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .category-item {
            margin-bottom: 12px;
        }

        .progress {
            height: 6px;
            border-radius: 10px;
        }

        .transaction-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .transaction-item {
            padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .fab {
            position: fixed;
            bottom: 80px;
            right: 20px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            width: 50px;
            height: 50px;
            border-radius: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
            cursor: pointer;
            z-index: 1000;
            text-decoration: none;
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

        .text-success {
            color: #28a745;
        }

        .text-danger {
            color: #dc3545;
        }

        .modal-content {
            border-radius: 24px 24px 0 0;
        }

        .form-control,
        .form-select {
            border-radius: 30px;
            padding: 12px 16px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .badge-info {
            background-color: #17a2b8;
            color: white;
        }

    </style>
</head>

<body>
    <div class="mobile-header">
        <div class="d-flex justify-content-between align-items-center">
            <a href="../dashboard.php" class="back-button">← Назад</a>
            <button class="back-button" id="filterBtn">📊 Фильтр</button>
        </div>
        <div class="page-title fs-3 fw-bold mt-2">Статистика</div>
        <div class="small">Анализ доходов и расходов</div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="text-success">↑</div>
            <div class="stat-value text-success">+<?php echo number_format($total_income, 0, '.', ' '); ?></div>
            <div class="stat-label">Доходы</div>
        </div>
        <div class="stat-card">
            <div class="text-danger">↓</div>
            <div class="stat-value text-danger">-<?php echo number_format($total_expense, 0, '.', ' '); ?></div>
            <div class="stat-label">Расходы</div>
        </div>
        <div class="stat-card">
            <div>💰</div>
            <div class="stat-value"><?php echo number_format($balance, 0, '.', ' '); ?></div>
            <div class="stat-label">Баланс</div>
        </div>
    </div>

    <div class="period-bar">
        <a href="<?php echo buildFilterUrl(['period' => 'month']); ?>"
            class="period-btn <?php echo $period == 'month' ? 'active' : ''; ?>">Месяц</a>
        <a href="<?php echo buildFilterUrl(['period' => 'quarter']); ?>"
            class="period-btn <?php echo $period == 'quarter' ? 'active' : ''; ?>">Квартал</a>
        <a href="<?php echo buildFilterUrl(['period' => 'year']); ?>"
            class="period-btn <?php echo $period == 'year' ? 'active' : ''; ?>">Год</a>
        <a href="<?php echo buildFilterUrl(['period' => 'custom']); ?>"
            class="period-btn <?php echo $period == 'custom' ? 'active' : ''; ?>">Свой</a>
    </div>

    <div class="filter-bar">
        <div class="small text-muted mb-2">
            Период: <?php echo date('d.m.Y', strtotime($date_from)); ?> -
            <?php echo date('d.m.Y', strtotime($date_to)); ?>
            <?php if ($filter_type != 'all'): ?>
                <span class="badge bg-secondary ms-2">Тип:
                    <?php echo $filter_type == 'income' ? 'Доходы' : ($filter_type == 'expense' ? 'Расходы' : 'Переводы'); ?></span>
            <?php endif; ?>
            <?php if (!$show_transfers && $filter_type == 'all'): ?>
                <span class="badge badge-info ms-2">Переводы исключены</span>
            <?php endif; ?>
            <?php if ($show_transfers): ?>
                <span class="badge bg-secondary ms-2">Переводы показаны</span>
            <?php endif; ?>
        </div>
        <div class="filter-checkbox">
            <input type="checkbox" id="showTransfers" <?php echo $show_transfers ? 'checked' : ''; ?>
                onchange="toggleShowTransfers()">
            <label for="showTransfers">Показывать переводы в статистике</label>
        </div>
    </div>

    <!-- График динамики -->
    <?php if (!empty($daily_stats)): ?>
        <div class="chart-container">
            <div class="fw-semibold mb-3"><i class="bi bi-graph-up"></i> Динамика за последние 7 дней</div>
            <canvas id="dailyChart" height="200"></canvas>
        </div>
    <?php endif; ?>

    <!-- Топ категорий -->
    <?php if (!empty($categories_stats)): ?>
        <div class="chart-container">
            <div class="fw-semibold mb-3"><i class="bi bi-pie-chart"></i> Топ категорий</div>
            <?php $max_total = max(array_column($categories_stats, 'total')); ?>
            <?php foreach ($categories_stats as $cat): ?>
                <div class="category-item">
                    <div class="d-flex justify-content-between small mb-1">
                        <span>
                            <span
                                style="display:inline-block; width:10px; height:10px; background:<?php echo $cat['color']; ?>; border-radius:50%;"></span>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </span>
                        <span class="fw-bold <?php echo $cat['type'] == 'income' ? 'text-success' : 'text-danger'; ?>">
                            <?php echo ($cat['type'] == 'income' ? '+' : '-') . number_format($cat['total'], 0, '.', ' '); ?> ₽
                        </span>
                    </div>
                    <div class="progress">
                        <div class="progress-bar"
                            style="width: <?php echo ($cat['total'] / $max_total) * 100; ?>%; background: <?php echo $cat['color']; ?>">
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Топ меток -->
    <?php if (!empty($tags_stats)): ?>
        <div class="chart-container">
            <div class="fw-semibold mb-3"><i class="bi bi-tags"></i> Топ меток</div>
            <div class="row g-2">
                <?php foreach ($tags_stats as $tag): ?>
                    <div class="col-6">
                        <div class="bg-light rounded-3 p-2">
                            <div class="small text-muted">#<?php echo htmlspecialchars($tag['name']); ?></div>
                            <div class="fw-bold"><?php echo number_format($tag['total'], 0, '.', ' '); ?> ₽</div>
                            <div class="small"><?php echo $tag['count']; ?> операций</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Список операций -->
    <div class="chart-container">
        <div class="fw-semibold mb-3"><i class="bi bi-list-ul"></i> Последние операции</div>
        <div class="transaction-list">
            <?php if (count($transactions) > 0): ?>
                <?php foreach (array_slice($transactions, 0, 10) as $t): ?>
                    <div class="transaction-item d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small text-muted"><?php echo date('d.m.Y', strtotime($t['transaction_date'])); ?></div>
                            <span class="badge"
                                style="background: <?php echo $t['category_color']; ?>"><?php echo htmlspecialchars($t['category_name']); ?></span>
                            <?php if ($t['type'] == 'transfer'): ?>
                                <span class="badge bg-secondary ms-1">Перевод</span>
                            <?php endif; ?>
                        </div>
                        <div
                            class="fw-bold <?php echo $t['type'] == 'income' ? 'text-success' : ($t['type'] == 'expense' ? 'text-danger' : 'text-secondary'); ?>">
                            <?php echo ($t['type'] == 'income' ? '+' : ($t['type'] == 'expense' ? '-' : '')) . number_format($t['amount'], 0, '.', ' '); ?>
                            ₽
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center text-muted py-3">Нет операций за выбранный период</div>
            <?php endif; ?>
        </div>
    </div>

    <a href="<?php echo buildFilterUrl(['export' => '1']); ?>" class="fab"><i class="bi bi-download"></i></a>

    <div class="mobile-nav">
        <div class="nav-scroll">
            <a href="../dashboard.php" class="nav-item"><i class="bi bi-house-door"></i><span>Главная</span></a>
            <a href="finances.php" class="nav-item"><i class="bi bi-calculator"></i><span>Финансы</span></a>
            <a href="accounts.php" class="nav-item"><i class="bi bi-bank"></i><span>Счета</span></a>
            <a href="statistics.php" class="nav-item active"><i
                    class="bi bi-graph-up-fill"></i><span>Статистика</span></a>
            <a href="transfers.php" class="nav-item"><i class="bi bi-arrow-left-right"></i><span>Переводы</span></a>
            <a href="debts.php" class="nav-item"><i class="bi bi-credit-card-2-front"></i><span>Долги</span></a>
            <a href="../profile.php" class="nav-item"><i class="bi bi-person"></i><span>Профиль</span></a>
        </div>
    </div>

    <!-- Filter Modal -->
    <div class="modal fade" id="filterModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Фильтры</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="GET" id="filterForm">
                    <div class="modal-body">
                        <input type="hidden" name="period" value="<?php echo $period; ?>">

                        <?php if ($period == 'custom'): ?>
                            <div class="mb-2">
                                <label class="form-label">С даты</label>
                                <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                            </div>
                            <div class="mb-2">
                                <label class="form-label">По дату</label>
                                <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                            </div>
                        <?php endif; ?>

                        <div class="mb-2">
                            <label class="form-label">Тип операции</label>
                            <select name="filter_type" class="form-select">
                                <option value="all" <?php echo $filter_type == 'all' ? 'selected' : ''; ?>>Все</option>
                                <option value="income" <?php echo $filter_type == 'income' ? 'selected' : ''; ?>>Доходы
                                </option>
                                <option value="expense" <?php echo $filter_type == 'expense' ? 'selected' : ''; ?>>Расходы
                                </option>
                            </select>
                        </div>

                        <div class="mb-2">
                            <label class="form-label">Категория</label>
                            <select name="filter_category" class="form-select">
                                <option value="all">Все категории</option>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo $filter_category == $c['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-2">
                            <label class="form-label">Счет</label>
                            <select name="filter_account" class="form-select">
                                <option value="all">Все счета</option>
                                <?php foreach ($accounts as $a): ?>
                                    <option value="<?php echo $a['id']; ?>" <?php echo $filter_account == $a['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($a['bank_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-2">
                            <label class="form-label">Метка</label>
                            <select name="filter_tag" class="form-select">
                                <option value="">Все метки</option>
                                <?php foreach ($tags_list as $tag): ?>
                                    <option value="<?php echo htmlspecialchars($tag); ?>" <?php echo $filter_tag == $tag ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tag); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        

                        <div class="form-check mt-2">
                            <input type="checkbox" name="show_transfers" id="modalShowTransfers"
                                class="form-check-input" value="1" <?php echo $show_transfers ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="modalShowTransfers">
                                Показывать переводы в статистике
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="statistics.php" class="btn btn-secondary">Сбросить</a>
                        <button type="submit" class="btn btn-primary">Применить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const filterModal = new bootstrap.Modal(document.getElementById('filterModal'));
            document.getElementById('filterBtn').onclick = () => filterModal.show();

            // Синхронизация чекбоксов
            const mainCheckbox = document.getElementById('showTransfers');
            const modalCheckbox = document.getElementById('modalShowTransfers');

            if (mainCheckbox && modalCheckbox) {
                mainCheckbox.addEventListener('change', function () {
                    modalCheckbox.checked = this.checked;
                });

                modalCheckbox.addEventListener('change', function () {
                    mainCheckbox.checked = this.checked;
                });
            }

            // График динамики
            const dailyData = <?php echo json_encode(array_values($daily_stats)); ?>;
            if (dailyData.length > 0) {
                const ctx = document.getElementById('dailyChart').getContext('2d');
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: dailyData.map(d => new Date(d.date).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })),
                        datasets: [
                            { label: 'Доходы', data: dailyData.map(d => d.income), backgroundColor: 'rgba(40,167,69,0.7)' },
                            { label: 'Расходы', data: dailyData.map(d => d.expense), backgroundColor: 'rgba(220,53,69,0.7)' }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: { legend: { position: 'top' } }
                    }
                });
            }
        });

        function toggleShowTransfers() {
            const checkbox = document.getElementById('showTransfers');
            const currentUrl = new URL(window.location.href);

            if (checkbox.checked) {
                currentUrl.searchParams.set('show_transfers', '1');
            } else {
                currentUrl.searchParams.delete('show_transfers');
            }

            // Сохраняем все текущие параметры фильтрации
            const currentType = '<?php echo $filter_type; ?>';
            if (currentType !== 'all') {
                currentUrl.searchParams.set('filter_type', currentType);
            } else {
                currentUrl.searchParams.delete('filter_type');
            }

            // Сохраняем период
            const period = '<?php echo $period; ?>';
            currentUrl.searchParams.set('period', period);

            // Сохраняем даты для произвольного периода
            if (period === 'custom') {
                const dateFrom = '<?php echo $date_from; ?>';
                const dateTo = '<?php echo $date_to; ?>';
                currentUrl.searchParams.set('date_from', dateFrom);
                currentUrl.searchParams.set('date_to', dateTo);
            }

            // Сохраняем категорию и счет
            const category = '<?php echo $filter_category; ?>';
            const account = '<?php echo $filter_account; ?>';
            const tag = '<?php echo $filter_tag; ?>';
            if (category !== 'all') currentUrl.searchParams.set('filter_category', category);
            if (account !== 'all') currentUrl.searchParams.set('filter_account', account);
            if (tag) currentUrl.searchParams.set('filter_tag', tag);

            window.location.href = currentUrl.toString();
        }
    </script>
</body>

</html>