<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$period = $_GET['period'] ?? 'month';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');
$filter_type = $_GET['filter_type'] ?? 'all';

switch ($period) {
    case 'month':
        $date_from = date('Y-m-01');
        $date_to = date('Y-m-t');
        break;
    case 'quarter':
        $q = ceil(date('n') / 3);
        $date_from = date('Y-' . (($q - 1) * 3 + 1) . '-01');
        $date_to = date('Y-' . ($q * 3) . '-t');
        break;
    case 'year':
        $date_from = date('Y-01-01');
        $date_to = date('Y-12-31');
        break;
}

$sql = "SELECT t.*, c.name as category_name, c.color as category_color FROM transactions t LEFT JOIN categories c ON t.category_id = c.id WHERE t.user_id = ? AND t.transaction_date BETWEEN ? AND ?";
$params = [$user_id, $date_from, $date_to];
if ($filter_type != 'all') {
    $sql .= " AND t.type = ?";
    $params[] = $filter_type;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$total_income = array_sum(array_filter(array_column($transactions, 'amount', 'type'), fn($k) => $k == 'income', ARRAY_FILTER_USE_KEY));
$total_expense = array_sum(array_filter(array_column($transactions, 'amount', 'type'), fn($k) => $k == 'expense', ARRAY_FILTER_USE_KEY));
$balance = $total_income - $total_expense;

$categories = [];
foreach ($transactions as $t) {
    $name = $t['category_name'];
    if (!isset($categories[$name]))
        $categories[$name] = ['name' => $name, 'color' => $t['category_color'], 'total' => 0];
    $categories[$name]['total'] += $t['amount'];
}
usort($categories, fn($a, $b) => $b['total'] <=> $a['total']);
$categories = array_slice($categories, 0, 5);

$daily = [];
foreach ($transactions as $t) {
    $date = $t['transaction_date'];
    if (!isset($daily[$date]))
        $daily[$date] = ['date' => $date, 'income' => 0, 'expense' => 0];
    if ($t['type'] == 'income')
        $daily[$date]['income'] += $t['amount'];
    else
        $daily[$date]['expense'] += $t['amount'];
}
ksort($daily);
$daily = array_slice($daily, -7, 7);
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
            <div class="small">Доходы</div>
        </div>
        <div class="stat-card">
            <div class="text-danger">↓</div>
            <div class="stat-value text-danger">-<?php echo number_format($total_expense, 0, '.', ' '); ?></div>
            <div class="small">Расходы</div>
        </div>
        <div class="stat-card">
            <div>💰</div>
            <div class="stat-value"><?php echo number_format($balance, 0, '.', ' '); ?></div>
            <div class="small">Баланс</div>
        </div>
    </div>

    <div class="period-bar">
        <a href="?period=month&<?php echo http_build_query(array_merge($_GET, ['period' => 'month'])); ?>"
            class="period-btn <?php echo $period == 'month' ? 'active' : ''; ?>">Месяц</a>
        <a href="?period=quarter&<?php echo http_build_query(array_merge($_GET, ['period' => 'quarter'])); ?>"
            class="period-btn <?php echo $period == 'quarter' ? 'active' : ''; ?>">Квартал</a>
        <a href="?period=year&<?php echo http_build_query(array_merge($_GET, ['period' => 'year'])); ?>"
            class="period-btn <?php echo $period == 'year' ? 'active' : ''; ?>">Год</a>
        <a href="?period=custom&<?php echo http_build_query(array_merge($_GET, ['period' => 'custom'])); ?>"
            class="period-btn <?php echo $period == 'custom' ? 'active' : ''; ?>">Свой</a>
    </div>

    <div class="filter-bar bg-white mx-3 mb-3 p-3 rounded-4">
        <div class="small text-muted">Период: <?php echo date('d.m.Y', strtotime($date_from)); ?> -
            <?php echo date('d.m.Y', strtotime($date_to)); ?></div>
    </div>

    <?php if (!empty($daily)): ?>
        <div class="chart-container">
            <div class="fw-semibold mb-3"><i class="bi bi-graph-up"></i> Динамика за 7 дней</div>
            <canvas id="dailyChart" height="200"></canvas>
        </div>
    <?php endif; ?>

    <?php if (!empty($categories)): ?>
        <div class="chart-container">
            <div class="fw-semibold mb-3"><i class="bi bi-pie-chart"></i> Топ категорий</div>
            <?php $max = max(array_column($categories, 'total')); ?>
            <?php foreach ($categories as $cat): ?>
                <div class="category-item">
                    <div class="d-flex justify-content-between small mb-1">
                        <span><span
                                style="display:inline-block; width:10px; height:10px; background:<?php echo $cat['color']; ?>; border-radius:50%;"></span>
                            <?php echo htmlspecialchars($cat['name']); ?></span>
                        <span class="fw-bold"><?php echo number_format($cat['total'], 0, '.', ' '); ?> ₽</span>
                    </div>
                    <div class="progress">
                        <div class="progress-bar"
                            style="width: <?php echo ($cat['total'] / $max) * 100; ?>%; background: <?php echo $cat['color']; ?>">
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="chart-container">
        <div class="fw-semibold mb-3"><i class="bi bi-list-ul"></i> Последние операции</div>
        <div class="transaction-list">
            <?php foreach (array_slice($transactions, 0, 10) as $t): ?>
                <div class="transaction-item d-flex justify-content-between align-items-center">
                    <div>
                        <div class="small text-muted"><?php echo date('d.m.Y', strtotime($t['transaction_date'])); ?></div>
                        <span class="badge"
                            style="background: <?php echo $t['category_color']; ?>"><?php echo htmlspecialchars($t['category_name']); ?></span>
                    </div>
                    <div class="fw-bold <?php echo $t['type'] == 'income' ? 'text-success' : 'text-danger'; ?>">
                        <?php echo ($t['type'] == 'income' ? '+' : '-') . number_format($t['amount'], 0, '.', ' '); ?> ₽
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($transactions)): ?>
                <div class="text-center text-muted py-3">Нет операций</div>
            <?php endif; ?>
        </div>
    </div>

    <a href="?export=1&<?php echo http_build_query($_GET); ?>" class="fab"><i class="bi bi-download"></i></a>

    <div class="mobile-nav">
        <div class="row g-0">
            <div class="col-2"><a href="../dashboard.php" class="nav-item"><i
                        class="bi bi-house-door"></i><span>Главная</span></a></div>
            <div class="col-2"><a href="finances.php" class="nav-item"><i
                        class="bi bi-calculator"></i><span>Финансы</span></a></div>
            <div class="col-2"><a href="accounts.php" class="nav-item"><i class="bi bi-bank"></i><span>Счета</span></a>
            </div>
            <div class="col-2"><a href="statistics.php" class="nav-item active"><i
                        class="bi bi-graph-up"></i><span>Статистика</span></a></div>
            <div class="col-2"> <a href="transfers.php" class="nav-item"><i
                        class="bi bi-arrow-left-right"></i><span>Переводы</span></a></div>
            <div class="col-2"> <a href="../profile.php" class="nav-item"><i
                        class="bi bi-person"></i><span>Профиль</span></a></div>
        </div>
    </div>

    <!-- Filter Modal -->
    <div class="modal fade" id="filterModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Фильтры</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="GET">
                    <div class="modal-body">
                        <?php if ($period == 'custom'): ?>
                            <input type="date" name="date_from" class="form-control mb-2" value="<?php echo $date_from; ?>">
                            <input type="date" name="date_to" class="form-control mb-2" value="<?php echo $date_to; ?>">
                        <?php endif; ?>
                        <input type="hidden" name="period" value="<?php echo $period; ?>">
                        <select name="filter_type" class="form-select mb-2">
                            <option value="all">Все типы</option>
                            <option value="income">Доходы</option>
                            <option value="expense">Расходы</option>
                        </select>
                    </div>
                    <div class="modal-footer"><button type="submit"
                            class="btn btn-primary w-100 rounded-pill">Применить</button></div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const filterModal = new bootstrap.Modal(document.getElementById('filterModal'));
            document.getElementById('filterBtn').onclick = () => filterModal.show();

            const dailyData = <?php echo json_encode(array_values($daily)); ?>;
            if (dailyData.length > 0) {
                new Chart(document.getElementById('dailyChart'), {
                    type: 'bar',
                    data: {
                        labels: dailyData.map(d => new Date(d.date).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })),
                        datasets: [
                            { label: 'Доходы', data: dailyData.map(d => d.income), backgroundColor: 'rgba(40,167,69,0.7)' },
                            { label: 'Расходы', data: dailyData.map(d => d.expense), backgroundColor: 'rgba(220,53,69,0.7)' }
                        ]
                    },
                    options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'top' } } }
                });
            }
        });
    </script>
</body>

</html>