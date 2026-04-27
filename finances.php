<?php
require_once 'config/session.php';
requireAuth();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';
$filter_type = $_GET['type'] ?? 'all';
$filter_date_from = $_GET['date_from'] ?? date('Y-m-01');
$filter_date_to = $_GET['date_to'] ?? date('Y-m-t');
$filter_category = $_GET['category'] ?? 'all';
$filter_account = $_GET['account'] ?? 'all';
$filter_tag = $_GET['tag'] ?? '';
// По умолчанию исключаем переводы (show_transfers = 0)
$show_transfers = isset($_GET['show_transfers']) && $_GET['show_transfers'] == '1';

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Добавление новой операции
    if (isset($_POST['add_transaction'])) {
        $type = $_POST['transaction_type'] ?? 'expense';
        $account_id = $_POST['account_id'] ?? 0;
        $category_id = $_POST['category_id'] ?? 0;
        $amount = floatval($_POST['amount'] ?? 0);
        $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
        $description = trim($_POST['description'] ?? '');
        $tags_text = trim($_POST['tags_text'] ?? '');
        
        if ($account_id > 0 && $category_id > 0 && $amount > 0) {
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_id, category_id, type, amount, transaction_date, description, tags_text) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $account_id, $category_id, $type, $amount, $transaction_date, $description, $tags_text]);
                
                if ($type == 'income') {
                    $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ? AND user_id = ?");
                } else {
                    $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ? AND user_id = ?");
                }
                $stmt->execute([$amount, $account_id, $user_id]);
                
                $pdo->commit();
                header("Location: finances.php");
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Ошибка при добавлении операции";
            }
        } else {
            $error = "Заполните все обязательные поля";
        }
    }
    
    // Редактирование операции
    elseif (isset($_POST['edit_transaction'])) {
        $transaction_id = $_POST['transaction_id'] ?? 0;
        $type = $_POST['transaction_type'] ?? 'expense';
        $account_id = $_POST['account_id'] ?? 0;
        $category_id = $_POST['category_id'] ?? 0;
        $amount = floatval($_POST['amount'] ?? 0);
        $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
        $description = trim($_POST['description'] ?? '');
        $tags_text = trim($_POST['tags_text'] ?? '');
        
        if ($transaction_id > 0 && $account_id > 0 && $category_id > 0 && $amount > 0) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND user_id = ?");
                $stmt->execute([$transaction_id, $user_id]);
                $old_transaction = $stmt->fetch();
                
                if ($old_transaction) {
                    $pdo->beginTransaction();
                    
                    if ($old_transaction['type'] == 'income') {
                        $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ? AND user_id = ?");
                    } else {
                        $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ? AND user_id = ?");
                    }
                    $stmt->execute([$old_transaction['amount'], $old_transaction['account_id'], $user_id]);
                    
                    $stmt = $pdo->prepare("UPDATE transactions SET type = ?, account_id = ?, category_id = ?, amount = ?, transaction_date = ?, description = ?, tags_text = ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$type, $account_id, $category_id, $amount, $transaction_date, $description, $tags_text, $transaction_id, $user_id]);
                    
                    if ($type == 'income') {
                        $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ? AND user_id = ?");
                    } else {
                        $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ? AND user_id = ?");
                    }
                    $stmt->execute([$amount, $account_id, $user_id]);
                    
                    $pdo->commit();
                    header("Location: finances.php");
                    exit;
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Ошибка при обновлении операции";
            }
        } else {
            $error = "Заполните все обязательные поля";
        }
    }
    
    // Удаление операции (уже есть в коде, оставляем)
    elseif (isset($_POST['delete_transaction'])) {
        $transaction_id = $_POST['transaction_id'] ?? 0;
        
        if ($transaction_id > 0) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND user_id = ?");
                $stmt->execute([$transaction_id, $user_id]);
                $transaction = $stmt->fetch();
                
                if ($transaction) {
                    $pdo->beginTransaction();
                    
                    if ($transaction['type'] == 'income') {
                        $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ? AND user_id = ?");
                    } else {
                        $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ? AND user_id = ?");
                    }
                    $stmt->execute([$transaction['amount'], $transaction['account_id'], $user_id]);
                    
                    $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?");
                    $stmt->execute([$transaction_id, $user_id]);
                    
                    $pdo->commit();
                    header("Location: finances.php");
                    exit;
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Ошибка при удалении операции";
            }
        }
    }
}

// Построение запроса для списка операций
$sql = "SELECT t.*, c.name as category_name, c.color as category_color, a.account_number, a.bank_name, a.color as account_color 
        FROM transactions t 
        LEFT JOIN categories c ON t.category_id = c.id 
        LEFT JOIN accounts a ON t.account_id = a.id 
        WHERE t.user_id = ?";
$params = [$user_id];

// Фильтр по типу (если выбран конкретный тип)
if ($filter_type !== 'all') {
    $sql .= " AND t.type = ?";
    $params[] = $filter_type;
} else {
    // Если не выбран конкретный тип, по умолчанию исключаем переводы
    if (!$show_transfers) {
        $sql .= " AND t.type != 'transfer' AND t.description NOT LIKE '%Перевод%'";
    }
}

// Фильтр по дате
if ($filter_date_from && $filter_date_to) {
    $sql .= " AND t.transaction_date BETWEEN ? AND ?";
    $params[] = $filter_date_from;
    $params[] = $filter_date_to;
}

// Фильтр по категории
if ($filter_category !== 'all') {
    $sql .= " AND t.category_id = ?";
    $params[] = $filter_category;
}

// Фильтр по счету
if ($filter_account !== 'all') {
    $sql .= " AND t.account_id = ?";
    $params[] = $filter_account;
}

// Фильтр по метке
if (!empty($filter_tag)) {
    $sql .= " AND t.tags_text LIKE ?";
    $params[] = "%$filter_tag%";
}

$sql .= " ORDER BY t.transaction_date DESC, t.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Получение справочников
$stmt = $pdo->prepare("SELECT * FROM accounts WHERE user_id = ? AND is_active = 1 ORDER BY bank_name");
$stmt->execute([$user_id]);
$accounts = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM categories WHERE user_id = ? AND type != 'transfer' ORDER BY type DESC, name");
$stmt->execute([$user_id]);
$categories = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM tags WHERE user_id = ? ORDER BY name");
$stmt->execute([$user_id]);
$tags = $stmt->fetchAll();

// Статистика (по умолчанию исключаем переводы)
$stats_sql = "SELECT 
        SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income,
        SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expense
    FROM transactions 
    WHERE user_id = ? 
    AND transaction_date BETWEEN ? AND ?";
$stats_params = [$user_id, $filter_date_from, $filter_date_to];

// Если не выбран конкретный тип, исключаем переводы из статистики
if ($filter_type === 'all' && !$show_transfers) {
    $stats_sql .= " AND (type != 'transfer' AND description NOT LIKE '%Перевод%')";
} elseif ($filter_type !== 'all' && $filter_type !== 'transfer') {
    // Если выбран доход или расход, исключаем переводы
    $stats_sql .= " AND (type != 'transfer' AND description NOT LIKE '%Перевод%')";
}

$stmt = $pdo->prepare($stats_sql);
$stmt->execute($stats_params);
$totals = $stmt->fetch();
$total_income = $totals['total_income'] ?? 0;
$total_expense = $totals['total_expense'] ?? 0;
$balance = $total_income - $total_expense;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#667eea">
    <title>Финансы - Финансовый дневник</title>
    <link rel="icon" type="image/png" href="favicon.png">    
    <link rel="manifest" href="manifest.json">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        * { -webkit-tap-highlight-color: transparent; }
        body { background: #f8f9fa; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding-bottom: 70px; }
        
        .mobile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 16px;
            border-radius: 0 0 24px 24px;
            margin-bottom: 16px;
        }
        
        .back-button {
            background: rgba(255,255,255,0.2);
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
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .stat-value { font-size: 18px; font-weight: bold; margin: 6px 0; }
        .stat-label { font-size: 11px; color: #6c757d; }
        
        .filter-bar {
            background: white;
            margin: 0 16px 16px;
            border-radius: 20px;
            padding: 12px;
        }
        
        .filter-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }
        
        .filter-chip {
            padding: 6px 14px;
            border-radius: 30px;
            background: #f8f9fa;
            text-decoration: none;
            font-size: 13px;
            color: #495057;
        }
        
        .filter-chip.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
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
        
        .transaction-card {
            background: white;
            border-radius: 16px;
            padding: 14px;
            margin: 0 16px 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: transform 0.2s;
        }
        
        .transaction-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .transaction-date { font-size: 12px; color: #6c757d; }
        .transaction-created { font-size: 10px; color: #adb5bd; margin-top: 2px; }
        
        .transaction-type {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .transaction-type.income { background: #d4edda; color: #155724; }
        .transaction-type.expense { background: #f8d7da; color: #721c24; }
        .transaction-type.transfer { background: #e2e3e5; color: #383d41; }
        
        .transaction-body {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .transaction-amount { font-size: 18px; font-weight: bold; }
        .transaction-amount.income { color: #28a745; }
        .transaction-amount.expense { color: #dc3545; }
        .transaction-amount.transfer { color: #6c757d; }
        
        .transaction-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #e9ecef;
        }
        
        .transaction-actions button {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            border: none;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .transaction-actions button:active { transform: scale(0.95); }
        .btn-edit { color: #667eea; }
        .btn-delete { color: #dc3545; }
        
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
            box-shadow: 0 4px 12px rgba(102,126,234,0.4);
            cursor: pointer;
            z-index: 1000;
        }
        
        .fab:active { transform: scale(0.95); }
        .fab i { font-size: 24px; color: white; }
        
        .mobile-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
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
        
        .nav-scroll::-webkit-scrollbar { display: none; }
        
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
        
        .nav-scroll .nav-item i { font-size: 22px; display: block; margin-bottom: 4px; }
        .nav-scroll .nav-item span { font-size: 11px; white-space: nowrap; }
        
        .modal-content { border-radius: 24px 24px 0 0; }
        .form-control, .form-select { border-radius: 30px; padding: 12px 16px; }
        
        .type-selector { display: flex; gap: 12px; margin-bottom: 20px; }
        .type-btn {
            flex: 1; padding: 12px; border-radius: 30px; border: 2px solid #e9ecef;
            background: white; font-weight: 600; cursor: pointer;
        }
        .type-btn.active-income { background: #28a745; color: white; border-color: #28a745; }
        .type-btn.active-expense { background: #dc3545; color: white; border-color: #dc3545; }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state i { font-size: 64px; margin-bottom: 16px; opacity: 0.5; }
        
        .tag-badge {
            background: #e9ecef;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            display: inline-block;
            margin-right: 4px;
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
            <a href="dashboard.php" class="back-button">← Назад</a>
            <button class="back-button" id="filterBtn">📊 Фильтр</button>
        </div>
        <div class="page-title fs-3 fw-bold mt-2">Финансы</div>
        <div class="small">Управление доходами и расходами</div>
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
    
    <div class="filter-bar">
        <div class="small text-muted mb-2">
            Период: <?php echo date('d.m.Y', strtotime($filter_date_from)); ?> - <?php echo date('d.m.Y', strtotime($filter_date_to)); ?>
            <?php if ($filter_type === 'all' && !$show_transfers): ?>
                <span class="badge badge-info ms-2">Переводы исключены</span>
            <?php endif; ?>
            <?php if ($show_transfers): ?>
                <span class="badge bg-secondary ms-2">Показаны переводы</span>
            <?php endif; ?>
        </div>
        <div class="filter-chips">
            <a href="?type=all&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&show_transfers=<?php echo $show_transfers ? '1' : '0'; ?>" class="filter-chip <?php echo $filter_type == 'all' ? 'active' : ''; ?>">Все</a>
            <a href="?type=income&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&show_transfers=<?php echo $show_transfers ? '1' : '0'; ?>" class="filter-chip <?php echo $filter_type == 'income' ? 'active' : ''; ?>">Доходы</a>
            <a href="?type=expense&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&show_transfers=<?php echo $show_transfers ? '1' : '0'; ?>" class="filter-chip <?php echo $filter_type == 'expense' ? 'active' : ''; ?>">Расходы</a>
            <a href="?type=transfer&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&show_transfers=1" class="filter-chip <?php echo $filter_type == 'transfer' ? 'active' : ''; ?>">Переводы</a>
        </div>
        <div class="filter-checkbox">
            <input type="checkbox" id="showTransfers" <?php echo $show_transfers ? 'checked' : ''; ?> onchange="toggleShowTransfers()">
            <label for="showTransfers">Показывать переводы в списке и статистике</label>
        </div>
    </div>
    
    <?php if (count($transactions) > 0): ?>
        <?php foreach ($transactions as $t): ?>
            <div class="transaction-card" data-id="<?php echo $t['id']; ?>" data-type="<?php echo $t['type']; ?>" data-account="<?php echo $t['account_id']; ?>" data-category="<?php echo $t['category_id']; ?>" data-amount="<?php echo $t['amount']; ?>" data-date="<?php echo $t['transaction_date']; ?>" data-desc="<?php echo htmlspecialchars($t['description'] ?? ''); ?>" data-tags="<?php echo htmlspecialchars($t['tags_text'] ?? ''); ?>">
                <div class="transaction-header">
                    <div>
                        <span class="transaction-date">📅 <?php echo date('d.m.Y', strtotime($t['transaction_date'])); ?></span>
                        <div class="transaction-created">🕐 Добавлена: <?php echo date('d.m.Y H:i', strtotime($t['created_at'])); ?></div>
                    </div>
                    <span class="transaction-type <?php echo $t['type']; ?>">
                        <?php echo $t['type'] == 'income' ? 'Доход' : ($t['type'] == 'expense' ? 'Расход' : 'Перевод'); ?>
                    </span>
                </div>
                <div class="transaction-body">
                    <div>
                        <span class="badge" style="background-color: <?php echo $t['category_color']; ?>"><?php echo htmlspecialchars($t['category_name']); ?></span>
                        <div class="small text-muted mt-1">🏦 <?php echo htmlspecialchars($t['bank_name']); ?></div>
                        <?php if (!empty($t['tags_text'])): ?>
                            <div class="mt-1">
                                <?php $tags = explode(';', $t['tags_text']); ?>
                                <?php foreach (array_slice($tags, 0, 2) as $tag): ?>
                                    <span class="tag-badge">#<?php echo htmlspecialchars(trim($tag)); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="transaction-amount <?php echo $t['type']; ?>">
                        <?php echo ($t['type'] == 'income' ? '+' : ($t['type'] == 'expense' ? '-' : '')) . number_format($t['amount'], 0, '.', ' '); ?> ₽
                    </div>
                </div>
                <?php if (!empty($t['description'])): ?>
                    <div class="small text-muted mt-2">📝 <?php echo htmlspecialchars(substr($t['description'], 0, 50)); ?></div>
                <?php endif; ?>
                <div class="transaction-actions">
                    <button class="btn-edit" onclick="event.stopPropagation(); editTransactionFromCard(this)">✏️ Редактировать</button>
                    <button class="btn-delete" onclick="event.stopPropagation(); deleteTransactionFromCard(this)">🗑️ Удалить</button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <h5>Нет операций</h5>
            <p>Добавьте первую операцию</p>
        </div>
    <?php endif; ?>
    
    <div class="fab" id="addBtn"><i class="bi bi-plus-lg"></i></div>
    
    <div class="mobile-nav">
        <div class="nav-scroll">
            <a href="dashboard.php" class="nav-item"><i class="bi bi-house-door"></i><span>Главная</span></a>
            <a href="finances.php" class="nav-item active"><i class="bi bi-calculator-fill"></i><span>Финансы</span></a>
            <a href="accounts.php" class="nav-item"><i class="bi bi-bank"></i><span>Счета</span></a>
            <a href="statistics.php" class="nav-item"><i class="bi bi-graph-up"></i><span>Статистика</span></a>
            <a href="transfers.php" class="nav-item"><i class="bi bi-arrow-left-right"></i><span>Переводы</span></a>
            <a href="debts.php" class="nav-item"><i class="bi bi-credit-card-2-front"></i><span>Долги</span></a>
            <a href="profile.php" class="nav-item"><i class="bi bi-person"></i><span>Профиль</span></a>
        </div>
    </div>
    
    <!-- Filter Modal -->
    <div class="modal fade" id="filterModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h5>Фильтры</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="GET" id="filterForm">
                <div class="modal-body">
                    <select name="type" class="form-select mb-2">
                        <option value="all" <?php echo $filter_type == 'all' ? 'selected' : ''; ?>>Все операции</option>
                        <option value="income" <?php echo $filter_type == 'income' ? 'selected' : ''; ?>>Доходы</option>
                        <option value="expense" <?php echo $filter_type == 'expense' ? 'selected' : ''; ?>>Расходы</option>
                        <option value="transfer" <?php echo $filter_type == 'transfer' ? 'selected' : ''; ?>>Переводы</option>
                    </select>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <input type="date" name="date_from" class="form-control" value="<?php echo $filter_date_from; ?>" placeholder="С даты">
                        </div>
                        <div class="col-6">
                            <input type="date" name="date_to" class="form-control" value="<?php echo $filter_date_to; ?>" placeholder="По дату">
                        </div>
                    </div>
                    <select name="category" class="form-select mb-2">
                        <option value="all">Все категории</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $filter_category == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="account" class="form-select mb-2">
                        <option value="all">Все счета</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?php echo $a['id']; ?>" <?php echo $filter_account == $a['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($a['bank_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="tag" class="form-control mb-2" placeholder="Метка" value="<?php echo htmlspecialchars($filter_tag); ?>">
                    <div class="form-check mt-2">
                        <input type="checkbox" name="show_transfers" id="modalShowTransfers" class="form-check-input" value="1" <?php echo $show_transfers ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="modalShowTransfers">
                            Показывать переводы в списке и статистике
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="finances.php" class="btn btn-secondary">Сбросить</a>
                    <button type="submit" class="btn btn-primary">Применить</button>
                </div>
            </form>
        </div></div>
    </div>
    
    <!-- Transaction Modal (Add/Edit) -->
    <div class="modal fade" id="transactionModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h5 id="modalTitle">Добавить операцию</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST" id="transactionForm">
                <div class="modal-body">
                    <input type="hidden" name="transaction_id" id="transId">
                    <input type="hidden" name="transaction_type" id="transType" value="expense">
                    <div class="type-selector">
                        <button type="button" class="type-btn" id="expenseBtn">Расход</button>
                        <button type="button" class="type-btn" id="incomeBtn">Доход</button>
                    </div>
                    <select name="account_id" class="form-select mb-2" required>
                        <option value="">Выберите счет</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?php echo $a['id']; ?>" data-balance="<?php echo $a['current_balance']; ?>"><?php echo htmlspecialchars($a['bank_name']); ?> (<?php echo number_format($a['current_balance'], 0, '.', ' '); ?> ₽)</option>
                        <?php endforeach; ?>
                    </select>
                    <select name="category_id" class="form-select mb-2" required>
                        <option value="">Выберите категорию</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo $c['id']; ?>" data-type="<?php echo $c['type']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="amount" class="form-control mb-2" placeholder="Сумма" step="0.01" required>
                    <input type="date" name="transaction_date" class="form-control mb-2" value="<?php echo date('Y-m-d'); ?>" required>
                    <textarea name="description" class="form-control mb-2" rows="2" placeholder="Описание"></textarea>
                    <input type="text" name="tags_text" class="form-control" placeholder="Метки (через ;)">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" name="add_transaction" class="btn btn-primary" id="submitBtn">Добавить</button>
                </div>
            </form>
        </div></div>
    </div>
    
    <!-- Delete Transaction Modal -->
    <div class="modal fade" id="deleteTransactionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Удалить операцию?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="deleteForm">
                    <div class="modal-body">
                        <input type="hidden" name="transaction_id" id="delete_transaction_id">
                        <p>Вы уверены, что хотите удалить эту операцию?</p>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> Это действие изменит баланс счета и не может быть отменено.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" name="delete_transaction" class="btn btn-danger">Удалить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let filterModal, transactionModal, deleteModal;
        
        document.addEventListener('DOMContentLoaded', function() {
            filterModal = new bootstrap.Modal(document.getElementById('filterModal'));
            transactionModal = new bootstrap.Modal(document.getElementById('transactionModal'));
            deleteModal = new bootstrap.Modal(document.getElementById('deleteTransactionModal'));
            
            document.getElementById('filterBtn').onclick = () => filterModal.show();
            document.getElementById('addBtn').onclick = () => { resetForm(); transactionModal.show(); };
            
            document.getElementById('expenseBtn').onclick = () => setType('expense');
            document.getElementById('incomeBtn').onclick = () => setType('income');
            
            function setType(type) {
                document.getElementById('transType').value = type;
                const expenseBtn = document.getElementById('expenseBtn');
                const incomeBtn = document.getElementById('incomeBtn');
                if (type === 'expense') {
                    expenseBtn.classList.add('active-expense');
                    incomeBtn.classList.remove('active-income');
                } else {
                    incomeBtn.classList.add('active-income');
                    expenseBtn.classList.remove('active-expense');
                }
                filterCategoriesByType(type);
                checkBalance();
            }
            
            function filterCategoriesByType(type) {
                const categorySelect = document.querySelector('select[name="category_id"]');
                if (!categorySelect) return;
                for (let i = 0; i < categorySelect.options.length; i++) {
                    const opt = categorySelect.options[i];
                    if (opt.value === '') continue;
                    const optType = opt.getAttribute('data-type');
                    opt.style.display = optType === type ? '' : 'none';
                }
                categorySelect.value = '';
            }
            
            function checkBalance() {
                const accountSelect = document.querySelector('select[name="account_id"]');
                const amountInput = document.querySelector('input[name="amount"]');
                const type = document.getElementById('transType').value;
                
                if (accountSelect && amountInput && type === 'expense') {
                    const selected = accountSelect.options[accountSelect.selectedIndex];
                    const balance = selected.getAttribute('data-balance');
                    const amount = parseFloat(amountInput.value);
                    if (balance && amount && !isNaN(amount) && amount > parseFloat(balance)) {
                        let warn = document.getElementById('balance_warning');
                        if (!warn) {
                            warn = document.createElement('small');
                            warn.id = 'balance_warning';
                            warn.className = 'text-danger mt-1 d-block';
                            amountInput.parentNode.insertBefore(warn, amountInput.nextSibling);
                        }
                        warn.textContent = `Недостаточно средств! Доступно: ${parseFloat(balance).toLocaleString('ru-RU', {minimumFractionDigits: 0})} ₽`;
                        warn.style.display = 'block';
                        return false;
                    } else {
                        const warn = document.getElementById('balance_warning');
                        if (warn) warn.style.display = 'none';
                        return true;
                    }
                }
                return true;
            }
            
            function resetForm() {
                document.getElementById('transactionForm').reset();
                document.getElementById('transId').value = '';
                document.getElementById('transType').value = 'expense';
                document.querySelector('input[name="transaction_date"]').value = '<?php echo date('Y-m-d'); ?>';
                document.getElementById('modalTitle').innerText = 'Добавить операцию';
                document.getElementById('submitBtn').name = 'add_transaction';
                document.getElementById('submitBtn').innerHTML = 'Добавить';
                setType('expense');
                const warn = document.getElementById('balance_warning');
                if (warn) warn.style.display = 'none';
            }
            
            const accountSelect = document.querySelector('select[name="account_id"]');
            const amountInput = document.querySelector('input[name="amount"]');
            if (accountSelect) accountSelect.addEventListener('change', checkBalance);
            if (amountInput) amountInput.addEventListener('input', checkBalance);
        });
        
        function editTransactionFromCard(btn) {
            const card = btn.closest('.transaction-card');
            const transaction = {
                id: card.dataset.id,
                type: card.dataset.type,
                account_id: card.dataset.account,
                category_id: card.dataset.category,
                amount: card.dataset.amount,
                transaction_date: card.dataset.date,
                description: card.dataset.desc,
                tags_text: card.dataset.tags
            };
            
            document.getElementById('modalTitle').innerText = 'Редактировать операцию';
            document.getElementById('transId').value = transaction.id;
            document.getElementById('transType').value = transaction.type;
            document.querySelector('select[name="account_id"]').value = transaction.account_id;
            document.querySelector('input[name="amount"]').value = transaction.amount;
            document.querySelector('input[name="transaction_date"]').value = transaction.transaction_date;
            document.querySelector('textarea[name="description"]').value = transaction.description || '';
            document.querySelector('input[name="tags_text"]').value = transaction.tags_text || '';
            
            // Устанавливаем тип
            const expenseBtn = document.getElementById('expenseBtn');
            const incomeBtn = document.getElementById('incomeBtn');
            if (transaction.type === 'expense') {
                expenseBtn.classList.add('active-expense');
                incomeBtn.classList.remove('active-income');
            } else {
                incomeBtn.classList.add('active-income');
                expenseBtn.classList.remove('active-expense');
            }
            document.getElementById('transType').value = transaction.type;
            
            // Фильтруем категории
            const categorySelect = document.querySelector('select[name="category_id"]');
            for (let i = 0; i < categorySelect.options.length; i++) {
                const opt = categorySelect.options[i];
                if (opt.value === '') continue;
                const optType = opt.getAttribute('data-type');
                opt.style.display = optType === transaction.type ? '' : 'none';
            }
            categorySelect.value = transaction.category_id;
            
            document.getElementById('submitBtn').name = 'edit_transaction';
            document.getElementById('submitBtn').innerHTML = 'Сохранить';
            transactionModal.show();
        }
        
        function deleteTransactionFromCard(btn) {
            const card = btn.closest('.transaction-card');
            const transactionId = card.dataset.id;
            document.getElementById('delete_transaction_id').value = transactionId;
            deleteModal.show();
        }
        
        function toggleShowTransfers() {
            const checkbox = document.getElementById('showTransfers');
            const currentUrl = new URL(window.location.href);
            if (checkbox.checked) {
                currentUrl.searchParams.set('show_transfers', '1');
            } else {
                currentUrl.searchParams.delete('show_transfers');
            }
            const currentType = '<?php echo $filter_type; ?>';
            if (currentType !== 'all') {
                currentUrl.searchParams.set('type', currentType);
            }
            window.location.href = currentUrl.toString();
        }
    </script>
</body>
</html>