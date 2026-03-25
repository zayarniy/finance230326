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
$filter_type = $_GET['type'] ?? 'all';
$filter_date_from = $_GET['date_from'] ?? date('Y-m-01');
$filter_date_to = $_GET['date_to'] ?? date('Y-m-t');
$filter_category = $_GET['category'] ?? 'all';
$filter_account = $_GET['account'] ?? 'all';
$filter_tag = $_GET['tag'] ?? '';

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
                    
                    // Возвращаем старый баланс
                    if ($old_transaction['type'] == 'income') {
                        $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ? AND user_id = ?");
                    } else {
                        $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ? AND user_id = ?");
                    }
                    $stmt->execute([$old_transaction['amount'], $old_transaction['account_id'], $user_id]);
                    
                    // Обновляем транзакцию
                    $stmt = $pdo->prepare("UPDATE transactions SET type = ?, account_id = ?, category_id = ?, amount = ?, transaction_date = ?, description = ?, tags_text = ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$type, $account_id, $category_id, $amount, $transaction_date, $description, $tags_text, $transaction_id, $user_id]);
                    
                    // Применяем новый баланс
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
    
    // Удаление операции
    elseif (isset($_POST['delete_transaction'])) {
        $transaction_id = $_POST['transaction_id'] ?? 0;
        
        if ($transaction_id > 0) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND user_id = ?");
                $stmt->execute([$transaction_id, $user_id]);
                $transaction = $stmt->fetch();
                
                if ($transaction) {
                    $pdo->beginTransaction();
                    
                    // Возвращаем баланс
                    if ($transaction['type'] == 'income') {
                        $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ? AND user_id = ?");
                    } else {
                        $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ? AND user_id = ?");
                    }
                    $stmt->execute([$transaction['amount'], $transaction['account_id'], $user_id]);
                    
                    // Удаляем транзакцию
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

// Получение списка операций с фильтрацией
// Исключаем переводы из выборки, если фильтр не на переводы
$sql = "SELECT t.*, c.name as category_name, c.color as category_color, a.account_number, a.bank_name, a.color as account_color 
        FROM transactions t 
        LEFT JOIN categories c ON t.category_id = c.id 
        LEFT JOIN accounts a ON t.account_id = a.id 
        WHERE t.user_id = ?";
$params = [$user_id];

// Фильтр по типу (исключаем переводы, если не выбран тип 'transfer')
if ($filter_type === 'transfer') {
    $sql .= " AND t.type = 'transfer'";
} elseif ($filter_type !== 'all') {
    $sql .= " AND t.type = ?";
    $params[] = $filter_type;
} else {
    // По умолчанию показываем все операции, кроме переводов
    $sql .= " AND t.type != 'transfer'";
}

if ($filter_date_from && $filter_date_to) {
    $sql .= " AND t.transaction_date BETWEEN ? AND ?";
    $params[] = $filter_date_from;
    $params[] = $filter_date_to;
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

$sql .= " ORDER BY t.transaction_date DESC, t.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Получение справочников (категории, счета, метки)
$stmt = $pdo->prepare("SELECT * FROM accounts WHERE user_id = ? AND is_active = 1 ORDER BY bank_name");
$stmt->execute([$user_id]);
$accounts = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM categories WHERE user_id = ? AND type != 'transfer' ORDER BY type DESC, name");
$stmt->execute([$user_id]);
$categories = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM tags WHERE user_id = ? ORDER BY name");
$stmt->execute([$user_id]);
$tags = $stmt->fetchAll();

// Статистика (исключаем переводы)
// Общая статистика за период
$stmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income,
        SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expense
    FROM transactions 
    WHERE user_id = ? 
    AND transaction_date BETWEEN ? AND ?
    AND type != 'transfer'
");
$stmt->execute([$user_id, $filter_date_from, $filter_date_to]);
$totals = $stmt->fetch();
$total_income = $totals['total_income'] ?? 0;
$total_expense = $totals['total_expense'] ?? 0;
$balance = $total_income - $total_expense;

// Статистика по дням для графика (если нужно)
$daily_stats = [];
$stmt = $pdo->prepare("
    SELECT 
        transaction_date,
        SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as daily_income,
        SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as daily_expense
    FROM transactions 
    WHERE user_id = ? 
    AND transaction_date BETWEEN ? AND ?
    AND type != 'transfer'
    GROUP BY transaction_date
    ORDER BY transaction_date
");
$stmt->execute([$user_id, $filter_date_from, $filter_date_to]);
$daily_stats = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#667eea">
    <title>Финансы - Финансовый дневник</title>
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
        
        .transaction-card {
            background: white;
            border-radius: 16px;
            padding: 14px;
            margin: 0 16px 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .transaction-card:active { transform: scale(0.98); }
        
        .transaction-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .transaction-date { font-size: 12px; color: #6c757d; }
        
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
        
        .mobile-nav .nav-item i { font-size: 22px; display: block; margin-bottom: 4px; }
        .mobile-nav .nav-item span { font-size: 11px; }
        
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
    </style>
</head>
<body>
    <div class="mobile-header">
        <div class="d-flex justify-content-between align-items-center">
            <a href="../dashboard.php" class="back-button">← Назад</a>
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
        </div>
        <div class="filter-chips">
            <a href="?type=all&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>" class="filter-chip <?php echo $filter_type == 'all' ? 'active' : ''; ?>">Все</a>
            <a href="?type=income&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>" class="filter-chip <?php echo $filter_type == 'income' ? 'active' : ''; ?>">Доходы</a>
            <a href="?type=expense&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>" class="filter-chip <?php echo $filter_type == 'expense' ? 'active' : ''; ?>">Расходы</a>
            <a href="?type=transfer&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>" class="filter-chip <?php echo $filter_type == 'transfer' ? 'active' : ''; ?>">Переводы</a>
        </div>
    </div>
    
    <?php if (count($transactions) > 0): ?>
        <?php foreach ($transactions as $t): ?>
            <div class="transaction-card" data-id="<?php echo $t['id']; ?>" data-type="<?php echo $t['type']; ?>" data-account="<?php echo $t['account_id']; ?>" data-category="<?php echo $t['category_id']; ?>" data-amount="<?php echo $t['amount']; ?>" data-date="<?php echo $t['transaction_date']; ?>" data-desc="<?php echo htmlspecialchars($t['description'] ?? ''); ?>" data-tags="<?php echo htmlspecialchars($t['tags_text'] ?? ''); ?>">
                <div class="transaction-header">
                    <span class="transaction-date">📅 <?php echo date('d.m.Y', strtotime($t['transaction_date'])); ?></span>
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
        <div class="row g-0">
            <div class="col-2"><a href="../dashboard.php" class="nav-item"><i
                        class="bi bi-house-door"></i><span>Главная</span></a></div>
            <div class="col-2"><a href="finances.php" class="nav-item active"><i
                        class="bi bi-calculator"></i><span>Финансы</span></a></div>
            <div class="col-2"><a href="accounts.php" class="nav-item"><i class="bi bi-bank"></i><span>Счета</span></a>
            </div>
            <div class="col-2"><a href="statistics.php" class="nav-item"><i
                        class="bi bi-graph-up"></i><span>Статистика</span></a></div>
            <div class="col-2"> <a href="transfers.php" class="nav-item"><i
                        class="bi bi-arrow-left-right"></i><span>Переводы</span></a></div>
            <div class="col-2"> <a href="../profile.php" class="nav-item"><i
                        class="bi bi-person"></i><span>Профиль</span></a></div>
        </div>
    </div>
    
    <!-- Filter Modal -->
    <div class="modal fade" id="filterModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h5>Фильтры</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="GET">
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
                    <input type="text" name="tag" class="form-control" placeholder="Метка" value="<?php echo htmlspecialchars($filter_tag); ?>">
                </div>
                <div class="modal-footer">
                    <a href="finances.php" class="btn btn-secondary">Сбросить</a>
                    <button type="submit" class="btn btn-primary">Применить</button>
                </div>
            </form>
        </div></div>
    </div>
    
    <!-- Transaction Modal -->
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const filterModal = new bootstrap.Modal(document.getElementById('filterModal'));
            const transactionModal = new bootstrap.Modal(document.getElementById('transactionModal'));
            
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
                const warning = document.getElementById('balance_warning');
                const type = document.getElementById('transType').value;
                
                if (accountSelect && amountInput && type === 'expense') {
                    const selected = accountSelect.options[accountSelect.selectedIndex];
                    const balance = selected.getAttribute('data-balance');
                    const amount = parseFloat(amountInput.value);
                    if (balance && amount && !isNaN(amount) && amount > parseFloat(balance)) {
                        if (!warning) {
                            const warn = document.createElement('small');
                            warn.id = 'balance_warning';
                            warn.className = 'text-danger mt-1 d-block';
                            amountInput.parentNode.insertBefore(warn, amountInput.nextSibling);
                        }
                        const warnEl = document.getElementById('balance_warning');
                        if (warnEl) {
                            warnEl.textContent = `Недостаточно средств! Доступно: ${parseFloat(balance).toLocaleString('ru-RU', {minimumFractionDigits: 0})} ₽`;
                            warnEl.style.display = 'block';
                        }
                        return false;
                    } else {
                        const warnEl = document.getElementById('balance_warning');
                        if (warnEl) warnEl.style.display = 'none';
                        return true;
                    }
                }
                return true;
            }
            
            function editTransaction(transaction) {
                document.getElementById('modalTitle').innerText = 'Редактировать операцию';
                document.getElementById('transId').value = transaction.id;
                document.getElementById('transType').value = transaction.type;
                document.querySelector('select[name="account_id"]').value = transaction.account_id;
                document.querySelector('input[name="amount"]').value = transaction.amount;
                document.querySelector('input[name="transaction_date"]').value = transaction.transaction_date;
                document.querySelector('textarea[name="description"]').value = transaction.description || '';
                document.querySelector('input[name="tags_text"]').value = transaction.tags_text || '';
                setType(transaction.type);
                const categorySelect = document.querySelector('select[name="category_id"]');
                for (let i = 0; i < categorySelect.options.length; i++) {
                    if (categorySelect.options[i].value == transaction.category_id) {
                        categorySelect.value = transaction.category_id;
                        break;
                    }
                }
                document.getElementById('submitBtn').name = 'edit_transaction';
                document.getElementById('submitBtn').innerHTML = 'Сохранить';
                transactionModal.show();
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
                const warnEl = document.getElementById('balance_warning');
                if (warnEl) warnEl.style.display = 'none';
            }
            
            document.querySelectorAll('.transaction-card').forEach(card => {
                card.onclick = function() {
                    const transaction = {
                        id: this.dataset.id,
                        type: this.dataset.type,
                        account_id: this.dataset.account,
                        category_id: this.dataset.category,
                        amount: this.dataset.amount,
                        transaction_date: this.dataset.date,
                        description: this.dataset.desc,
                        tags_text: this.dataset.tags
                    };
                    editTransaction(transaction);
                };
            });
            
            const accountSelect = document.querySelector('select[name="account_id"]');
            const amountInput = document.querySelector('input[name="amount"]');
            if (accountSelect) accountSelect.addEventListener('change', checkBalance);
            if (amountInput) amountInput.addEventListener('input', checkBalance);
        });
    </script>
</body>
</html>