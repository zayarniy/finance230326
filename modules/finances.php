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

// Обработка POST запросов (оставляем как есть)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                    header("Location: finances.php");
                    exit;
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Ошибка при обновлении операции";
            }
        }
    }
    
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

// Получение данных
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#667eea">
    <title>Финансы - Финансовый дневник</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        * {
            -webkit-tap-highlight-color: transparent;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
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
            background: rgba(255,255,255,0.2);
            border: none;
            border-radius: 30px;
            padding: 8px 16px;
            color: white;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            cursor: pointer;
        }
        
        .page-title {
            font-size: 24px;
            font-weight: bold;
            margin: 12px 0 4px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            padding: 0 16px;
            margin-bottom: 20px;
        }
        
        .stat-card-mobile {
            background: white;
            border-radius: 16px;
            padding: 12px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .stat-value {
            font-size: 18px;
            font-weight: bold;
        }
        
        .filter-bar {
            background: white;
            margin: 0 16px 16px;
            border-radius: 20px;
            padding: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f8f9fa;
            border-radius: 30px;
            padding: 8px 14px;
            font-size: 13px;
            margin: 0 4px 8px 0;
            text-decoration: none;
        }
        
        .transaction-list {
            margin: 0 16px;
        }
        
        .transaction-card {
            background: white;
            border-radius: 16px;
            padding: 14px;
            margin-bottom: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            cursor: pointer;
        }
        
        .transaction-card:active {
            transform: scale(0.98);
        }
        
        .transaction-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .transaction-date {
            font-size: 12px;
            color: #6c757d;
        }
        
        .transaction-type {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .transaction-type.income {
            background: #d4edda;
            color: #155724;
        }
        
        .transaction-type.expense {
            background: #f8d7da;
            color: #721c24;
        }
        
        .transaction-body {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .transaction-amount {
            font-size: 18px;
            font-weight: bold;
        }
        
        .transaction-amount.income {
            color: #28a745;
        }
        
        .transaction-amount.expense {
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
            box-shadow: 0 4px 12px rgba(102,126,234,0.4);
            cursor: pointer;
            z-index: 1000;
        }
        
        .fab i {
            font-size: 24px;
            color: white;
        }
        
        .modal-content {
            border-radius: 24px 24px 0 0;
        }
        
        .type-selector {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .type-btn {
            flex: 1;
            padding: 12px;
            border-radius: 30px;
            border: 2px solid #e9ecef;
            background: white;
            font-weight: 600;
            cursor: pointer;
        }
        
        .type-btn.active-income {
            background: #28a745;
            color: white;
        }
        
        .type-btn.active-expense {
            background: #dc3545;
            color: white;
        }
        
        .tags-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .tag-option {
            padding: 10px;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
        }
        
        .tag-option.selected {
            background: #e9ecef;
            border-color: #667eea;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
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
        
        .mobile-nav .nav-item i {
            font-size: 22px;
            display: block;
            margin-bottom: 4px;
        }
        
        .mobile-nav .nav-item span {
            font-size: 11px;
        }
        
        .text-success {
            color: #28a745 !important;
        }
        
        .text-danger {
            color: #dc3545 !important;
        }
        
        .text-warning {
            color: #ffc107 !important;
        }
        
        .text-primary {
            color: #667eea !important;
        }
    </style>
</head>
<body>
    <div class="mobile-header">
        <div class="d-flex justify-content-between align-items-center">
            <a href="../dashboard.php" class="back-button">← Назад</a>
            <button class="back-button" id="filterBtn">📊 Фильтр</button>
        </div>
        <div class="page-title">Финансы</div>
        <div class="small">Управление доходами и расходами</div>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card-mobile">
            <div class="text-success fs-4">↑</div>
            <div class="stat-value text-success">+<?php echo number_format($total_income, 0, '.', ' '); ?></div>
            <div class="small">Доходы</div>
        </div>
        <div class="stat-card-mobile">
            <div class="text-danger fs-4">↓</div>
            <div class="stat-value text-danger">-<?php echo number_format($total_expense, 0, '.', ' '); ?></div>
            <div class="small">Расходы</div>
        </div>
        <div class="stat-card-mobile">
            <div class="fs-4">💰</div>
            <div class="stat-value"><?php echo number_format($balance, 0, '.', ' '); ?></div>
            <div class="small">Баланс</div>
        </div>
    </div>
    
    <?php if ($filter_type != 'all' || $filter_category != 'all' || $filter_account != 'all' || !empty($filter_tag)): ?>
    <div class="filter-bar">
        <div class="small mb-2">Активные фильтры:</div>
        <div>
            <a href="finances.php" class="filter-chip text-danger">✖ Сбросить</a>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="transaction-list" id="transactionList">
        <?php if (count($transactions) > 0): ?>
            <?php foreach ($transactions as $t): ?>
                <div class="transaction-card" data-id="<?php echo $t['id']; ?>" data-type="<?php echo $t['type']; ?>" data-account="<?php echo $t['account_id']; ?>" data-category="<?php echo $t['category_id']; ?>" data-amount="<?php echo $t['amount']; ?>" data-date="<?php echo $t['transaction_date']; ?>" data-desc="<?php echo htmlspecialchars($t['description'] ?? ''); ?>" data-tags="<?php echo htmlspecialchars($t['tags_text'] ?? ''); ?>">
                    <div class="transaction-header">
                        <span class="transaction-date">📅 <?php echo date('d.m.Y', strtotime($t['transaction_date'])); ?></span>
                        <span class="transaction-type <?php echo $t['type']; ?>"><?php echo $t['type'] == 'income' ? 'Доход' : 'Расход'; ?></span>
                    </div>
                    <div class="transaction-body">
                        <div>
                            <div><strong><?php echo htmlspecialchars($t['category_name']); ?></strong></div>
                            <div class="small">🏦 <?php echo htmlspecialchars($t['bank_name']); ?></div>
                        </div>
                        <div class="transaction-amount <?php echo $t['type']; ?>">
                            <?php echo ($t['type'] == 'income' ? '+' : '-') . number_format($t['amount'], 0, '.', ' '); ?> ₽
                        </div>
                    </div>
                    <?php if (!empty($t['description'])): ?>
                        <div class="small text-muted mt-2">📝 <?php echo htmlspecialchars(substr($t['description'], 0, 50)); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="fs-1">📭</div>
                <h5>Нет операций</h5>
                <p>Добавьте первую операцию</p>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="fab" id="addBtn">
        <i>+</i>
    </div>
    
    <div class="mobile-nav">
        <div class="row g-0">
            <div class="col-3"><a href="../dashboard.php" class="nav-item">🏠<span>Главная</span></a></div>
            <div class="col-3"><a href="finances.php" class="nav-item active">💰<span>Финансы</span></a></div>
            <div class="col-3"><a href="statistics.php" class="nav-item">📊<span>Статистика</span></a></div>
            <div class="col-3"><a href="../profile.php" class="nav-item">👤<span>Профиль</span></a></div>
        </div>
    </div>
    
    <!-- Filter Modal -->
    <div class="modal fade" id="filterModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Фильтры</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="GET">
                    <div class="modal-body">
                        <select name="type" class="form-select mb-2">
                            <option value="all">Все типы</option>
                            <option value="income">Доходы</option>
                            <option value="expense">Расходы</option>
                        </select>
                        <select name="category" class="form-select mb-2">
                            <option value="all">Все категории</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="account" class="form-select mb-2">
                            <option value="all">Все счета</option>
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['bank_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="date" name="date_from" class="form-control mb-2" value="<?php echo $filter_date_from; ?>">
                        <input type="date" name="date_to" class="form-control mb-2" value="<?php echo $filter_date_to; ?>">
                        <input type="text" name="tag" class="form-control" placeholder="Метка" value="<?php echo htmlspecialchars($filter_tag); ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">Применить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Transaction Modal -->
    <div class="modal fade" id="transactionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 id="modalTitle">Добавить операцию</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="transactionForm">
                    <div class="modal-body">
                        <input type="hidden" name="transaction_id" id="transId">
                        <div class="type-selector">
                            <button type="button" class="type-btn" id="expenseBtn">Расход</button>
                            <button type="button" class="type-btn" id="incomeBtn">Доход</button>
                        </div>
                        <input type="hidden" name="transaction_type" id="transType" value="expense">
                        
                        <select name="account_id" class="form-select mb-2" required>
                            <option value="">Выберите счет</option>
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?php echo $a['id']; ?>" data-balance="<?php echo $a['current_balance']; ?>">
                                    <?php echo htmlspecialchars($a['bank_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select name="category_id" class="form-select mb-2" required>
                            <option value="">Выберите категорию</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?php echo $c['id']; ?>" data-type="<?php echo $c['type']; ?>">
                                    <?php echo htmlspecialchars($c['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <input type="number" name="amount" class="form-control mb-2" placeholder="Сумма" step="0.01" required>
                        <input type="date" name="transaction_date" class="form-control mb-2" value="<?php echo date('Y-m-d'); ?>" required>
                        <textarea name="description" class="form-control mb-2" rows="2" placeholder="Описание"></textarea>
                        <input type="text" name="tags_text" id="tagsInput" class="form-control" placeholder="Метки (через ;)">
                        <button type="button" class="btn btn-outline-secondary mt-2 w-100" id="selectTagsBtn">Выбрать метки</button>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="add_transaction" class="btn btn-primary" id="submitBtn">Добавить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Tags Modal -->
    <div class="modal fade" id="tagsSelectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Выберите метки</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="tags-grid" id="tagsGrid"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" id="confirmTagsBtn">Применить</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Удалить операцию?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="transaction_id" id="deleteId">
                        <p>Вы уверены?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="delete_transaction" class="btn btn-danger">Удалить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Ждем загрузки страницы
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Страница загружена');
            
            // Модальные окна
            const filterModal = new bootstrap.Modal(document.getElementById('filterModal'));
            const transactionModal = new bootstrap.Modal(document.getElementById('transactionModal'));
            const tagsModal = new bootstrap.Modal(document.getElementById('tagsSelectModal'));
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            
            // Кнопки
            document.getElementById('filterBtn').onclick = function() {
                filterModal.show();
            };
            
            document.getElementById('addBtn').onclick = function() {
                document.getElementById('modalTitle').innerText = 'Добавить операцию';
                document.getElementById('transactionForm').reset();
                document.getElementById('transId').value = '';
                document.getElementById('submitBtn').name = 'add_transaction';
                document.getElementById('submitBtn').innerHTML = 'Добавить';
                setType('expense');
                transactionModal.show();
            };
            
            document.getElementById('selectTagsBtn').onclick = function() {
                loadTags();
                tagsModal.show();
            };
            
            document.getElementById('confirmTagsBtn').onclick = function() {
                const selected = Array.from(document.querySelectorAll('#tagsGrid .tag-option.selected'))
                    .map(el => el.getAttribute('data-name'));
                document.getElementById('tagsInput').value = selected.join('; ');
                tagsModal.hide();
            };
            
            // Кнопки типа
            document.getElementById('expenseBtn').onclick = function() { setType('expense'); };
            document.getElementById('incomeBtn').onclick = function() { setType('income'); };
            
            // Карточки транзакций
            document.querySelectorAll('.transaction-card').forEach(card => {
                card.onclick = function(e) {
                    e.stopPropagation();
                    const id = this.getAttribute('data-id');
                    const type = this.getAttribute('data-type');
                    const account = this.getAttribute('data-account');
                    const category = this.getAttribute('data-category');
                    const amount = this.getAttribute('data-amount');
                    const date = this.getAttribute('data-date');
                    const desc = this.getAttribute('data-desc');
                    const tags = this.getAttribute('data-tags');
                    
                    document.getElementById('modalTitle').innerText = 'Редактировать операцию';
                    document.getElementById('transId').value = id;
                    document.getElementById('transType').value = type;
                    document.querySelector('select[name="account_id"]').value = account;
                    document.querySelector('select[name="category_id"]').value = category;
                    document.querySelector('input[name="amount"]').value = amount;
                    document.querySelector('input[name="transaction_date"]').value = date;
                    document.querySelector('textarea[name="description"]').value = desc;
                    document.querySelector('input[name="tags_text"]').value = tags;
                    document.getElementById('submitBtn').name = 'edit_transaction';
                    document.getElementById('submitBtn').innerHTML = 'Сохранить';
                    setType(type);
                    transactionModal.show();
                };
            });
            
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
                
                // Фильтруем категории
                const categorySelect = document.querySelector('select[name="category_id"]');
                for (let i = 0; i < categorySelect.options.length; i++) {
                    const opt = categorySelect.options[i];
                    if (opt.value === '') continue;
                    const optType = opt.getAttribute('data-type');
                    opt.style.display = optType === type ? '' : 'none';
                }
                categorySelect.value = '';
            }
            
            function loadTags() {
                const tags = <?php echo json_encode(array_column($tags, 'name')); ?>;
                const currentTags = document.getElementById('tagsInput').value.split(';').map(t => t.trim()).filter(t => t);
                
                const grid = document.getElementById('tagsGrid');
                grid.innerHTML = '';
                
                tags.forEach(tag => {
                    const div = document.createElement('div');
                    div.className = 'tag-option';
                    if (currentTags.includes(tag)) div.classList.add('selected');
                    div.setAttribute('data-name', tag);
                    div.innerHTML = tag;
                    div.onclick = function() {
                        this.classList.toggle('selected');
                    };
                    grid.appendChild(div);
                });
            }
            
            // Проверка баланса
            const accountSelect = document.querySelector('select[name="account_id"]');
            const amountInput = document.querySelector('input[name="amount"]');
            
            function checkBalance() {
                const type = document.getElementById('transType').value;
                if (type === 'expense' && accountSelect.value) {
                    const balance = accountSelect.options[accountSelect.selectedIndex].getAttribute('data-balance');
                    const amount = parseFloat(amountInput.value);
                    if (amount && amount > parseFloat(balance)) {
                        alert('Недостаточно средств!');
                        amountInput.value = '';
                    }
                }
            }
            
            if (accountSelect) accountSelect.onchange = checkBalance;
            if (amountInput) amountInput.oninput = checkBalance;
        });
    </script>
</body>
</html>