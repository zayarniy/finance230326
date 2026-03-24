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

// Обработка POST
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
                header("Location: finances.php");
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Ошибка при добавлении";
            }
        } else {
            $error = "Заполните все поля";
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
        
        if ($transaction_id > 0) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND user_id = ?");
                $stmt->execute([$transaction_id, $user_id]);
                $old = $stmt->fetch();
                
                if ($old) {
                    $pdo->beginTransaction();
                    if ($old['type'] == 'income') {
                        $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ? AND user_id = ?");
                    } else {
                        $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ? AND user_id = ?");
                    }
                    $stmt->execute([$old['amount'], $old['account_id'], $user_id]);
                    
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
                $error = "Ошибка при редактировании";
            }
        }
    }
    
    elseif (isset($_POST['delete_transaction'])) {
        $transaction_id = $_POST['transaction_id'] ?? 0;
        
        if ($transaction_id > 0) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND user_id = ?");
                $stmt->execute([$transaction_id, $user_id]);
                $t = $stmt->fetch();
                
                if ($t) {
                    $pdo->beginTransaction();
                    if ($t['type'] == 'income') {
                        $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ? AND user_id = ?");
                    } else {
                        $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ? AND user_id = ?");
                    }
                    $stmt->execute([$t['amount'], $t['account_id'], $user_id]);
                    
                    $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?");
                    $stmt->execute([$transaction_id, $user_id]);
                    $pdo->commit();
                    header("Location: finances.php");
                    exit;
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Ошибка при удалении";
            }
        }
    }
}

// Получение данных
$sql = "SELECT t.*, c.name as category_name, c.color as category_color, a.bank_name FROM transactions t LEFT JOIN categories c ON t.category_id = c.id LEFT JOIN accounts a ON t.account_id = a.id WHERE t.user_id = ? AND t.transaction_date BETWEEN ? AND ?";
$params = [$user_id, $filter_date_from, $filter_date_to];
if ($filter_type != 'all') {
    $sql .= " AND t.type = ?";
    $params[] = $filter_type;
}
$sql .= " ORDER BY t.transaction_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Справочники
$stmt = $pdo->prepare("SELECT * FROM accounts WHERE user_id = ? AND is_active = 1");
$stmt->execute([$user_id]);
$accounts = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM categories WHERE user_id = ?");
$stmt->execute([$user_id]);
$categories = $stmt->fetchAll();

$total_income = array_sum(array_filter(array_column($transactions, 'amount', 'type'), function($k) { return $k == 'income'; }, ARRAY_FILTER_USE_KEY));
$total_expense = array_sum(array_filter(array_column($transactions, 'amount', 'type'), function($k) { return $k == 'expense'; }, ARRAY_FILTER_USE_KEY));
$balance = $total_income - $total_expense;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#667eea">
    <title>Финансы - Финансовый дневник</title>
    <link rel="icon" type="image/png" href="../favicon.png">
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
            border: none;
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
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .stat-value { font-size: 18px; font-weight: bold; }
        
        .filter-bar {
            background: white;
            margin: 0 16px 16px;
            border-radius: 20px;
            padding: 12px;
        }
        
        .transaction-card {
            background: white;
            border-radius: 16px;
            padding: 14px;
            margin: 0 16px 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            cursor: pointer;
        }
        
        .transaction-card:active { transform: scale(0.98); }
        
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
        
        .text-income { color: #28a745; }
        .text-expense { color: #dc3545; }
        
        .modal-content { border-radius: 24px 24px 0 0; }
        .form-control, .form-select { border-radius: 30px; padding: 12px 16px; }
        .type-selector { display: flex; gap: 12px; margin-bottom: 20px; }
        .type-btn {
            flex: 1; padding: 12px; border-radius: 30px; border: 2px solid #e9ecef;
            background: white; font-weight: 600; cursor: pointer;
        }
        .type-btn.active-income { background: #28a745; color: white; border-color: #28a745; }
        .type-btn.active-expense { background: #dc3545; color: white; border-color: #dc3545; }
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
        <div class="stat-card"><div class="text-success">↑</div><div class="stat-value text-success">+<?php echo number_format($total_income, 0, '.', ' '); ?></div><div class="small">Доходы</div></div>
        <div class="stat-card"><div class="text-danger">↓</div><div class="stat-value text-danger">-<?php echo number_format($total_expense, 0, '.', ' '); ?></div><div class="small">Расходы</div></div>
        <div class="stat-card"><div>💰</div><div class="stat-value"><?php echo number_format($balance, 0, '.', ' '); ?></div><div class="small">Баланс</div></div>
    </div>
    
    <div class="filter-bar">
        <div class="small text-muted mb-2">Период: <?php echo date('d.m.Y', strtotime($filter_date_from)); ?> - <?php echo date('d.m.Y', strtotime($filter_date_to)); ?></div>
        <div class="d-flex gap-2">
            <a href="?type=all" class="btn btn-sm <?php echo $filter_type == 'all' ? 'btn-primary' : 'btn-outline-secondary'; ?> rounded-pill">Все</a>
            <a href="?type=income" class="btn btn-sm <?php echo $filter_type == 'income' ? 'btn-success' : 'btn-outline-success'; ?> rounded-pill">Доходы</a>
            <a href="?type=expense" class="btn btn-sm <?php echo $filter_type == 'expense' ? 'btn-danger' : 'btn-outline-danger'; ?> rounded-pill">Расходы</a>
        </div>
    </div>
    
    <?php foreach ($transactions as $t): ?>
    <div class="transaction-card" data-id="<?php echo $t['id']; ?>" data-type="<?php echo $t['type']; ?>" data-account="<?php echo $t['account_id']; ?>" data-category="<?php echo $t['category_id']; ?>" data-amount="<?php echo $t['amount']; ?>" data-date="<?php echo $t['transaction_date']; ?>" data-desc="<?php echo htmlspecialchars($t['description'] ?? ''); ?>" data-tags="<?php echo htmlspecialchars($t['tags_text'] ?? ''); ?>">
        <div class="d-flex justify-content-between mb-2">
            <span class="small text-muted"><?php echo date('d.m.Y', strtotime($t['transaction_date'])); ?></span>
            <span class="badge <?php echo $t['type'] == 'income' ? 'bg-success' : 'bg-danger'; ?>"><?php echo $t['type'] == 'income' ? 'Доход' : 'Расход'; ?></span>
        </div>
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <span class="badge" style="background-color: <?php echo $t['category_color']; ?>"><?php echo htmlspecialchars($t['category_name']); ?></span>
                <div class="small text-muted mt-1">🏦 <?php echo htmlspecialchars($t['bank_name']); ?></div>
            </div>
            <div class="fw-bold <?php echo $t['type'] == 'income' ? 'text-income' : 'text-expense'; ?>">
                <?php echo ($t['type'] == 'income' ? '+' : '-') . number_format($t['amount'], 0, '.', ' '); ?> ₽
            </div>
        </div>
        <?php if (!empty($t['description'])): ?>
            <div class="small text-muted mt-2">📝 <?php echo htmlspecialchars(substr($t['description'], 0, 50)); ?></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    
    <?php if (empty($transactions)): ?>
        <div class="text-center text-muted py-5">Нет операций</div>
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
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header"><h5>Фильтры</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <form method="GET">
                    <div class="modal-body">
                        <select name="type" class="form-select mb-2">
                            <option value="all">Все типы</option>
                            <option value="income">Доходы</option>
                            <option value="expense">Расходы</option>
                        </select>
                        <input type="date" name="date_from" class="form-control mb-2" value="<?php echo $filter_date_from; ?>">
                        <input type="date" name="date_to" class="form-control mb-2" value="<?php echo $filter_date_to; ?>">
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
                    </div>
                    <div class="modal-footer"><button type="submit" class="btn btn-primary w-100 rounded-pill">Применить</button></div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Transaction Modal -->
    <div class="modal fade" id="transactionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header"><h5 id="modalTitle">Добавить операцию</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <form method="POST">
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
                                <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['bank_name']); ?></option>
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
                    <div class="modal-footer"><button type="submit" name="add_transaction" class="btn btn-primary w-100 rounded-pill" id="submitBtn">Добавить</button></div>
                </form>
            </div>
        </div>
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
                const categorySelect = document.querySelector('select[name="category_id"]');
                for (let i = 0; i < categorySelect.options.length; i++) {
                    const opt = categorySelect.options[i];
                    if (opt.value === '') continue;
                    opt.style.display = opt.getAttribute('data-type') === type ? '' : 'none';
                }
            }
            
            function resetForm() {
                document.getElementById('transactionForm')?.reset();
                document.getElementById('transId').value = '';
                document.getElementById('modalTitle').innerText = 'Добавить операцию';
                document.getElementById('submitBtn').name = 'add_transaction';
                document.getElementById('submitBtn').innerHTML = 'Добавить';
                setType('expense');
            }
            
            document.querySelectorAll('.transaction-card').forEach(card => {
                card.onclick = function() {
                    document.getElementById('modalTitle').innerText = 'Редактировать операцию';
                    document.getElementById('transId').value = this.dataset.id;
                    document.getElementById('transType').value = this.dataset.type;
                    document.querySelector('select[name="account_id"]').value = this.dataset.account;
                    document.querySelector('select[name="category_id"]').value = this.dataset.category;
                    document.querySelector('input[name="amount"]').value = this.dataset.amount;
                    document.querySelector('input[name="transaction_date"]').value = this.dataset.date;
                    document.querySelector('textarea[name="description"]').value = this.dataset.desc;
                    document.querySelector('input[name="tags_text"]').value = this.dataset.tags;
                    document.getElementById('submitBtn').name = 'edit_transaction';
                    document.getElementById('submitBtn').innerHTML = 'Сохранить';
                    setType(this.dataset.type);
                    transactionModal.show();
                };
            });
        });
    </script>
</body>
</html>