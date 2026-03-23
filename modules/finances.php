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
                $message = "Операция успешно добавлена";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Ошибка при добавлении операции: " . $e->getMessage();
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
                    $message = "Операция успешно обновлена";
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Ошибка при обновлении операции: " . $e->getMessage();
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
                    
                    if ($transaction['type'] == 'income') {
                        $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ? AND user_id = ?");
                    } else {
                        $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ? AND user_id = ?");
                    }
                    $stmt->execute([$transaction['amount'], $transaction['account_id'], $user_id]);
                    
                    $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?");
                    $stmt->execute([$transaction_id, $user_id]);
                    
                    $pdo->commit();
                    $message = "Операция успешно удалена";
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Ошибка при удалении операции: " . $e->getMessage();
            }
        }
    }
}

// Получение списка операций с фильтрацией
$sql = "SELECT t.*, c.name as category_name, c.color as category_color, a.account_number, a.bank_name, a.color as account_color 
        FROM transactions t 
        LEFT JOIN categories c ON t.category_id = c.id 
        LEFT JOIN accounts a ON t.account_id = a.id 
        WHERE t.user_id = ?";
$params = [$user_id];

if ($filter_type !== 'all') {
    $sql .= " AND t.type = ?";
    $params[] = $filter_type;
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

// Получение справочников
$stmt = $pdo->prepare("SELECT * FROM accounts WHERE user_id = ? AND is_active = 1 ORDER BY bank_name");
$stmt->execute([$user_id]);
$accounts = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM categories WHERE user_id = ? ORDER BY type DESC, name");
$stmt->execute([$user_id]);
$categories = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM tags WHERE user_id = ? ORDER BY name");
$stmt->execute([$user_id]);
$tags = $stmt->fetchAll();

// Статистика
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
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Финансы - Финансовый дневник</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
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
        .transaction-row {
            cursor: pointer;
            transition: all 0.3s;
        }
        .transaction-row:hover {
            background-color: #f8f9fa;
            transform: translateX(5px);
        }
        .tag-badge {
            background-color: #e9ecef;
            color: #495057;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-right: 5px;
            margin-bottom: 5px;
            display: inline-block;
            cursor: pointer;
        }
        .tag-badge:hover {
            background-color: #dee2e6;
        }
        .tags-modal-container {
            max-height: 300px;
            overflow-y: auto;
        }
        .tag-selector {
            cursor: pointer;
            transition: all 0.2s;
        }
        .tag-selector:hover {
            transform: scale(1.02);
            background-color: #f8f9fa;
        }
        .tag-selector.selected {
            background: linear-gradient(135deg, #667eea20 0%, #764ba220 100%);
            border-color: #667eea !important;
        }
        .type-btn {
            border-radius: 25px;
            padding: 8px 20px;
            transition: all 0.3s;
        }
        .type-btn.active-income {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border-color: transparent;
        }
        .type-btn.active-expense {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            border-color: transparent;
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
                        <a class="nav-link active" href="finances.php">
                            <i class="bi bi-calculator"></i> Финансы
                        </a>
                        <a class="nav-link" href="transfers.php">
                            <i class="bi bi-arrow-left-right"></i> Переводы
                        </a>
                        <a class="nav-link" href="statistics.php">
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
                                <h2><i class="bi bi-calculator"></i> Финансы</h2>
                                <p class="text-muted">Управление доходами и расходами</p>
                            </div>
                            <button type="button" class="btn btn-primary" onclick="openAddTransactionModal()">
                                <i class="bi bi-plus-circle"></i> Добавить операцию
                            </button>
                        </div>
                        
                        <!-- Messages -->
                        <?php if ($message): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Statistics Cards -->
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <div class="card stat-card bg-success text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="card-title">Доходы</h6>
                                                <h3 class="mb-0">+<?php echo number_format($total_income, 2, '.', ' '); ?> ₽</h3>
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
                                            </div>
                                            <i class="bi bi-wallet2 fs-1 opacity-50"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Filter Card -->
                        <div class="card filter-card mb-4">
                            <div class="card-body">
                                <form method="GET" action="" class="row g-3">
                                    <div class="col-md-2">
                                        <label class="form-label">Тип</label>
                                        <select name="type" class="form-select">
                                            <option value="all" <?php echo $filter_type == 'all' ? 'selected' : ''; ?>>Все</option>
                                            <option value="income" <?php echo $filter_type == 'income' ? 'selected' : ''; ?>>Доходы</option>
                                            <option value="expense" <?php echo $filter_type == 'expense' ? 'selected' : ''; ?>>Расходы</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">С даты</label>
                                        <input type="date" name="date_from" class="form-control" value="<?php echo $filter_date_from; ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">По дату</label>
                                        <input type="date" name="date_to" class="form-control" value="<?php echo $filter_date_to; ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Категория</label>
                                        <select name="category" class="form-select">
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
                                        <select name="account" class="form-select">
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
                                        <input type="text" name="tag" class="form-control" placeholder="Поиск по меткам" value="<?php echo htmlspecialchars($filter_tag); ?>">
                                    </div>
                                    <div class="col-md-12">
                                        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Применить фильтр</button>
                                        <a href="finances.php" class="btn btn-secondary"><i class="bi bi-arrow-repeat"></i> Сбросить</a>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Transactions Table -->
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="bi bi-list-ul"></i> Список операций</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Дата</th>
                                                <th>Тип</th>
                                                <th>Категория</th>
                                                <th>Счет</th>
                                                <th>Сумма</th>
                                                <th>Описание</th>
                                                <th>Метки</th>
                                                <th>Действия</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($transactions) > 0): ?>
                                                <?php foreach ($transactions as $transaction): ?>
                                                    <tr>
                                                        <td><?php echo date('d.m.Y', strtotime($transaction['transaction_date'])); ?></td>
                                                        <td>
                                                            <?php if ($transaction['type'] == 'income'): ?>
                                                                <span class="badge bg-success">Доход</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-danger">Расход</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge" style="background-color: <?php echo htmlspecialchars($transaction['category_color']); ?>">
                                                                <?php echo htmlspecialchars($transaction['category_name']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <i class="bi bi-bank"></i>
                                                            <?php echo htmlspecialchars($transaction['bank_name']); ?>
                                                        </td>
                                                        <td class="<?php echo $transaction['type'] == 'income' ? 'text-success' : 'text-danger'; ?> fw-bold">
                                                            <?php echo ($transaction['type'] == 'income' ? '+' : '-') . number_format($transaction['amount'], 2, '.', ' '); ?> ₽
                                                        </td>
                                                        <td><?php echo htmlspecialchars($transaction['description'] ?: '-'); ?></td>
                                                        <td>
                                                            <?php if (!empty($transaction['tags_text'])): ?>
                                                                <?php $tags_array = explode(';', $transaction['tags_text']); ?>
                                                                <?php foreach ($tags_array as $tag): ?>
                                                                    <span class="tag-badge"><?php echo htmlspecialchars(trim($tag)); ?></span>
                                                                <?php endforeach; ?>
                                                            <?php else: ?>
                                                                <span class="text-muted">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <button class="btn btn-sm btn-outline-primary" onclick='editTransaction(<?php echo json_encode($transaction); ?>)'>
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                            <button class="btn btn-sm btn-outline-danger" onclick='deleteTransaction(<?php echo $transaction['id']; ?>, <?php echo json_encode($transaction['description'] ?: 'Операция'); ?>)'>
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="8" class="text-center py-4">
                                                        <i class="bi bi-inbox fs-1 text-muted"></i>
                                                        <p class="text-muted mt-2">Нет операций за выбранный период</p>
                                                        <button class="btn btn-sm btn-primary" onclick="openAddTransactionModal()">
                                                            <i class="bi bi-plus-circle"></i> Добавить первую операцию
                                                        </button>
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
    </div>
    
    <!-- Transaction Modal (Add/Edit) -->
    <div class="modal fade" id="transactionModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="transactionModalTitle">Добавить операцию</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" id="transactionForm">
                    <div class="modal-body">
                        <input type="hidden" name="transaction_id" id="transaction_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Тип операции *</label>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-danger type-btn" onclick="setTransactionType('expense')" id="expenseTypeBtn">
                                    <i class="bi bi-arrow-down-circle"></i> Расход
                                </button>
                                <button type="button" class="btn btn-outline-success type-btn" onclick="setTransactionType('income')" id="incomeTypeBtn">
                                    <i class="bi bi-arrow-up-circle"></i> Доход
                                </button>
                            </div>
                            <input type="hidden" name="transaction_type" id="transaction_type" value="expense">
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="account_id" class="form-label">Счет *</label>
                                <select class="form-select" id="account_id" name="account_id" required>
                                    <option value="">Выберите счет</option>
                                    <?php foreach ($accounts as $account): ?>
                                        <option value="<?php echo $account['id']; ?>" data-balance="<?php echo $account['current_balance']; ?>">
                                            <?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_number'] . ' (' . number_format($account['current_balance'], 2, '.', ' ') . ' ₽)'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="category_id" class="form-label">Категория *</label>
                                <select class="form-select" id="category_id" name="category_id" required>
                                    <option value="">Выберите категорию</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" data-type="<?php echo $category['type']; ?>">
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="amount" class="form-label">Сумма *</label>
                                <input type="number" class="form-control" id="amount" name="amount" step="0.01" required>
                                <small class="text-muted" id="balance_warning" style="display: none; color: #dc3545;"></small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="transaction_date" class="form-label">Дата *</label>
                                <input type="date" class="form-control" id="transaction_date" name="transaction_date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Описание</label>
                            <textarea class="form-control" id="description" name="description" rows="2"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Метки</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="tags_text" name="tags_text" placeholder="Метки через точку с запятой (например: еда;ресторан)">
                                <button type="button" class="btn btn-outline-secondary" onclick="openTagsModal()">
                                    <i class="bi bi-tags"></i> Выбрать метки
                                </button>
                            </div>
                            <small class="text-muted">Введите метки через точку с запятой или выберите из списка</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" name="add_transaction" class="btn btn-primary" id="submitBtn">Добавить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Tags Selection Modal -->
    <div class="modal fade" id="tagsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-tags"></i> Выбор меток</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (count($tags) > 0): ?>
                        <div class="tags-modal-container" id="tagsContainer">
                            <div class="row">
                                <?php foreach ($tags as $tag): ?>
                                    <div class="col-md-6 mb-2">
                                        <div class="tag-selector p-2 border rounded" data-tag-name="<?php echo htmlspecialchars($tag['name']); ?>" onclick="toggleTagSelection(this)">
                                            <div class="d-flex align-items-center">
                                                <div style="width: 20px; height: 20px; background-color: <?php echo htmlspecialchars($tag['color']); ?>; border-radius: 4px; margin-right: 10px;"></div>
                                                <span><?php echo htmlspecialchars($tag['name']); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-muted">Нет добавленных меток. Создайте метки в разделе "Банк Меток".</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" onclick="applySelectedTags()">Применить</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Transaction Modal -->
    <div class="modal fade" id="deleteTransactionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Удаление операции</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" id="deleteForm">
                    <div class="modal-body">
                        <input type="hidden" name="transaction_id" id="delete_transaction_id">
                        <p>Вы уверены, что хотите удалить операцию <strong id="delete_transaction_desc"></strong>?</p>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> Внимание! Удаление операции изменит баланс счета.
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
        let currentTransactionModal = null;
        let currentTagsModal = null;
        let currentDeleteModal = null;
        let selectedTags = [];
        
        // Инициализация модальных окон
        document.addEventListener('DOMContentLoaded', function() {
            currentTransactionModal = new bootstrap.Modal(document.getElementById('transactionModal'));
            currentTagsModal = new bootstrap.Modal(document.getElementById('tagsModal'));
            currentDeleteModal = new bootstrap.Modal(document.getElementById('deleteTransactionModal'));
        });
        
        // Открытие модального окна добавления
        function openAddTransactionModal() {
            resetTransactionForm();
            currentTransactionModal.show();
        }
        
        // Установка типа операции
        function setTransactionType(type) {
            document.getElementById('transaction_type').value = type;
            const expenseBtn = document.getElementById('expenseTypeBtn');
            const incomeBtn = document.getElementById('incomeTypeBtn');
            
            if (type === 'expense') {
                expenseBtn.classList.remove('btn-outline-danger');
                expenseBtn.classList.add('btn-danger', 'active-expense');
                incomeBtn.classList.remove('btn-success', 'active-income');
                incomeBtn.classList.add('btn-outline-success');
            } else {
                incomeBtn.classList.remove('btn-outline-success');
                incomeBtn.classList.add('btn-success', 'active-income');
                expenseBtn.classList.remove('btn-danger', 'active-expense');
                expenseBtn.classList.add('btn-outline-danger');
            }
            
            filterCategoriesByType(type);
            checkBalance();
        }
        
        // Фильтрация категорий
        function filterCategoriesByType(type) {
            const categorySelect = document.getElementById('category_id');
            const options = categorySelect.options;
            
            for (let i = 0; i < options.length; i++) {
                const option = options[i];
                if (option.value === '') continue;
                
                const categoryType = option.getAttribute('data-type');
                if (categoryType === type) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            }
            
            categorySelect.value = '';
        }
        
        // Проверка баланса
        function checkBalance() {
            const accountSelect = document.getElementById('account_id');
            const amountInput = document.getElementById('amount');
            const balanceWarning = document.getElementById('balance_warning');
            const transactionType = document.getElementById('transaction_type').value;
            
            if (accountSelect && amountInput && balanceWarning && transactionType === 'expense') {
                const selectedOption = accountSelect.options[accountSelect.selectedIndex];
                const balance = selectedOption.getAttribute('data-balance');
                const amount = parseFloat(amountInput.value);
                
                if (balance && amount && !isNaN(amount) && amount > parseFloat(balance)) {
                    balanceWarning.style.display = 'block';
                    balanceWarning.textContent = `Недостаточно средств! Доступно: ${parseFloat(balance).toLocaleString('ru-RU', {minimumFractionDigits: 2})} ₽`;
                    return false;
                } else {
                    balanceWarning.style.display = 'none';
                    return true;
                }
            }
            return true;
        }
        
        // Редактирование операции
        function editTransaction(transaction) {
            document.getElementById('transactionModalTitle').textContent = 'Редактировать операцию';
            document.getElementById('transaction_id').value = transaction.id;
            document.getElementById('transaction_type').value = transaction.type;
            document.getElementById('account_id').value = transaction.account_id;
            document.getElementById('amount').value = transaction.amount;
            document.getElementById('transaction_date').value = transaction.transaction_date;
            document.getElementById('description').value = transaction.description || '';
            document.getElementById('tags_text').value = transaction.tags_text || '';
            
            setTransactionType(transaction.type);
            
            const categorySelect = document.getElementById('category_id');
            for (let i = 0; i < categorySelect.options.length; i++) {
                if (categorySelect.options[i].value == transaction.category_id) {
                    categorySelect.value = transaction.category_id;
                    break;
                }
            }
            
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.name = 'edit_transaction';
            submitBtn.innerHTML = '<i class="bi bi-save"></i> Сохранить изменения';
            
            currentTransactionModal.show();
        }
        
        // Сброс формы
        function resetTransactionForm() {
            document.getElementById('transactionForm').reset();
            document.getElementById('transaction_id').value = '';
            document.getElementById('transaction_type').value = 'expense';
            document.getElementById('transaction_date').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('transactionModalTitle').textContent = 'Добавить операцию';
            
            setTransactionType('expense');
            
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.name = 'add_transaction';
            submitBtn.innerHTML = '<i class="bi bi-plus-circle"></i> Добавить';
            
            const balanceWarning = document.getElementById('balance_warning');
            if (balanceWarning) balanceWarning.style.display = 'none';
        }
        
        // Открытие модального окна с метками
        function openTagsModal() {
            // Сбрасываем выделение
            document.querySelectorAll('.tag-selector').forEach(el => {
                el.classList.remove('selected');
            });
            
            // Выделяем уже выбранные метки
            const currentTags = document.getElementById('tags_text').value.split(';').map(t => t.trim()).filter(t => t);
            document.querySelectorAll('.tag-selector').forEach(el => {
                const tagName = el.getAttribute('data-tag-name');
                if (currentTags.includes(tagName)) {
                    el.classList.add('selected');
                }
            });
            
            selectedTags = [...currentTags];
            currentTagsModal.show();
        }
        
        // Переключение выбора метки
        function toggleTagSelection(element) {
            const tagName = element.getAttribute('data-tag-name');
            if (element.classList.contains('selected')) {
                element.classList.remove('selected');
                selectedTags = selectedTags.filter(tag => tag !== tagName);
            } else {
                element.classList.add('selected');
                selectedTags.push(tagName);
            }
        }
        
        // Применить выбранные метки
        function applySelectedTags() {
            const tagsInput = document.getElementById('tags_text');
            tagsInput.value = selectedTags.join('; ');
            currentTagsModal.hide();
        }
        
        // Удаление операции
        function deleteTransaction(transactionId, description) {
            document.getElementById('delete_transaction_id').value = transactionId;
            document.getElementById('delete_transaction_desc').textContent = description;
            currentDeleteModal.show();
        }
        
        // Обработчики событий
        document.getElementById('account_id')?.addEventListener('change', checkBalance);
        document.getElementById('amount')?.addEventListener('input', checkBalance);
        
        // Предотвращение отправки формы при некорректных данных
        document.getElementById('transactionForm')?.addEventListener('submit', function(e) {
            if (!checkBalance()) {
                e.preventDefault();
                alert('Недостаточно средств на счете!');
                return false;
            }
            
            const accountId = document.getElementById('account_id').value;
            const categoryId = document.getElementById('category_id').value;
            const amount = document.getElementById('amount').value;
            
            if (!accountId || !categoryId || !amount || amount <= 0) {
                e.preventDefault();
                alert('Пожалуйста, заполните все обязательные поля!');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>