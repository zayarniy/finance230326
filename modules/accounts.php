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
$edit_account = null;
$filter_status = $_GET['status'] ?? 'all'; // all, active, inactive

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Добавление нового счета
    if (isset($_POST['add_account'])) {
        $account_number = trim($_POST['account_number'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $opening_date = $_POST['opening_date'] ?? date('Y-m-d');
        $color = $_POST['account_color'] ?? '#6c757d';
        $icon = $_POST['account_icon'] ?? 'bi-bank';
        $initial_balance = floatval($_POST['initial_balance'] ?? 0);
        
        if (!empty($account_number) && !empty($bank_name)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO accounts (user_id, account_number, bank_name, note, opening_date, color, icon, initial_balance, current_balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $account_number, $bank_name, $note, $opening_date, $color, $icon, $initial_balance, $initial_balance]);
                $message = "Счет успешно добавлен";
            } catch (PDOException $e) {
                $error = "Ошибка при добавлении счета: " . $e->getMessage();
            }
        } else {
            $error = "Заполните обязательные поля (номер счета и банк)";
        }
    }
    
    // Редактирование счета
    elseif (isset($_POST['edit_account'])) {
        $account_id = $_POST['account_id'] ?? 0;
        $account_number = trim($_POST['account_number'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $opening_date = $_POST['opening_date'] ?? date('Y-m-d');
        $color = $_POST['account_color'] ?? '#6c757d';
        $icon = $_POST['account_icon'] ?? 'bi-bank';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (!empty($account_number) && !empty($bank_name) && $account_id > 0) {
            $stmt = $pdo->prepare("UPDATE accounts SET account_number = ?, bank_name = ?, note = ?, opening_date = ?, color = ?, icon = ?, is_active = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$account_number, $bank_name, $note, $opening_date, $color, $icon, $is_active, $account_id, $user_id]);
            $message = "Счет успешно обновлен";
        } else {
            $error = "Заполните обязательные поля";
        }
    }
    
    // Корректировка баланса
    elseif (isset($_POST['adjust_balance'])) {
        $account_id = $_POST['account_id'] ?? 0;
        $new_balance = floatval($_POST['new_balance'] ?? 0);
        $adjustment_note = trim($_POST['adjustment_note'] ?? 'Корректировка баланса');
        
        if ($account_id > 0) {
            // Получаем текущий баланс
            $stmt = $pdo->prepare("SELECT current_balance FROM accounts WHERE id = ? AND user_id = ?");
            $stmt->execute([$account_id, $user_id]);
            $account = $stmt->fetch();
            
            if ($account) {
                $old_balance = $account['current_balance'];
                $difference = $new_balance - $old_balance;
                
                // Обновляем баланс
                $stmt = $pdo->prepare("UPDATE accounts SET current_balance = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$new_balance, $account_id, $user_id]);
                
                // Создаем корректировочную транзакцию
                $transaction_type = $difference >= 0 ? 'income' : 'expense';
                $amount = abs($difference);
                
                // Получаем или создаем категорию для корректировки
                $stmt = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = 'Корректировка баланса' AND type = ? LIMIT 1");
                $stmt->execute([$user_id, $transaction_type]);
                $category = $stmt->fetch();
                
                if ($category) {
                    $category_id = $category['id'];
                } else {
                    // Создаем категорию для корректировки
                    $color = $transaction_type == 'income' ? '#28a745' : '#dc3545';
                    $stmt = $pdo->prepare("INSERT INTO categories (user_id, name, color, type) VALUES (?, 'Корректировка баланса', ?, ?)");
                    $stmt->execute([$user_id, $color, $transaction_type]);
                    $category_id = $pdo->lastInsertId();
                }
                
                $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_id, category_id, type, amount, transaction_date, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $account_id, $category_id, $transaction_type, $amount, date('Y-m-d'), $adjustment_note]);
                
                $message = "Баланс счета скорректирован. Изменение: " . ($difference >= 0 ? '+' : '') . number_format($difference, 2, '.', ' ') . " ₽";
            }
        }
    }
    
    // Удаление счета
    elseif (isset($_POST['delete_account'])) {
        $account_id = $_POST['account_id'] ?? 0;
        
        if ($account_id > 0) {
            // Проверяем, есть ли операции с этим счетом
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM transactions WHERE account_id = ? AND user_id = ?");
            $stmt->execute([$account_id, $user_id]);
            $count = $stmt->fetch()['count'];
            
            if ($count > 0) {
                $error = "Невозможно удалить счет. Он используется в $count операциях. Сначала удалите или переназначьте эти операции.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM accounts WHERE id = ? AND user_id = ?");
                $stmt->execute([$account_id, $user_id]);
                $message = "Счет успешно удален";
            }
        }
    }
}

// Получение списка счетов с фильтрацией
$sql = "SELECT * FROM accounts WHERE user_id = ?";
$params = [$user_id];

if ($filter_status !== 'all') {
    $is_active = $filter_status == 'active' ? 1 : 0;
    $sql .= " AND is_active = ?";
    $params[] = $is_active;
}

$sql .= " ORDER BY current_balance DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$accounts = $stmt->fetchAll();

// Получаем статистику по счетам
$total_balance = array_sum(array_column($accounts, 'current_balance'));
$active_accounts = count(array_filter($accounts, function($acc) { return $acc['is_active']; }));
$total_transactions = 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM transactions WHERE user_id = ?");
$stmt->execute([$user_id]);
$total_transactions = $stmt->fetch()['count'];

// Если нужно редактировать - получаем данные
if (isset($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = ? AND user_id = ?");
    $stmt->execute([$edit_id, $user_id]);
    $edit_account = $stmt->fetch();
}

// Список доступных иконок
$icons = [
    'bi-bank' => 'Банк',
    'bi-credit-card' => 'Кредитная карта',
    'bi-cash' => 'Наличные',
    'bi-wallet' => 'Кошелек',
    'bi-piggy-bank' => 'Копилка',
    'bi-building' => 'Здание',
    'bi-safe' => 'Сейф',
    'bi-coin' => 'Монеты',
    'bi-gem' => 'Драгоценность',
    'bi-box' => 'Коробка'
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Справочник Счетов - Финансовый дневник</title>
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
        .account-card {
            border-radius: 15px;
            transition: all 0.3s;
            border: none;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }
        .account-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .account-card.inactive {
            opacity: 0.7;
            background-color: #e9ecef;
        }
        .account-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin-right: 15px;
            transition: transform 0.3s;
        }
        .account-card:hover .account-icon {
            transform: scale(1.05);
        }
        .account-actions {
            opacity: 0;
            transition: opacity 0.3s;
        }
        .account-card:hover .account-actions {
            opacity: 1;
        }
        .balance-positive {
            color: #28a745;
            font-weight: bold;
        }
        .balance-negative {
            color: #dc3545;
            font-weight: bold;
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
        .icon-selector {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
            margin-top: 10px;
        }
        .icon-option {
            text-align: center;
            padding: 10px;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .icon-option:hover {
            border-color: #667eea;
            background-color: #f8f9fa;
        }
        .icon-option.selected {
            border-color: #667eea;
            background: linear-gradient(135deg, #667eea20 0%, #764ba220 100%);
        }
        .icon-option i {
            font-size: 24px;
        }
        .filter-btn {
            border-radius: 25px;
            padding: 8px 20px;
            margin: 0 5px;
            transition: all 0.3s;
        }
        .filter-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
                        <a class="nav-link active" href="accounts.php">
                            <i class="bi bi-bank"></i> Справочник Счетов
                        </a>
                        <a class="nav-link" href="finances.php">
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
                                <h2><i class="bi bi-bank"></i> Справочник Счетов</h2>
                                <p class="text-muted">Управление банковскими счетами и кошельками</p>
                            </div>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                                <i class="bi bi-plus-circle"></i> Добавить счет
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
                            <div class="col-md-3">
                                <div class="card stat-card bg-primary text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="card-title">Общий баланс</h6>
                                                <h3 class="mb-0"><?php echo number_format($total_balance, 2, '.', ' '); ?> ₽</h3>
                                            </div>
                                            <i class="bi bi-calculator fs-1 opacity-50"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card bg-success text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="card-title">Активных счетов</h6>
                                                <h3 class="mb-0"><?php echo $active_accounts; ?> / <?php echo count($accounts); ?></h3>
                                            </div>
                                            <i class="bi bi-check-circle fs-1 opacity-50"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card bg-info text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="card-title">Всего операций</h6>
                                                <h3 class="mb-0"><?php echo $total_transactions; ?></h3>
                                            </div>
                                            <i class="bi bi-arrow-left-right fs-1 opacity-50"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card bg-warning text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="card-title">Средний баланс</h6>
                                                <h3 class="mb-0"><?php echo count($accounts) > 0 ? number_format($total_balance / count($accounts), 2, '.', ' ') : 0; ?> ₽</h3>
                                            </div>
                                            <i class="bi bi-graph-up fs-1 opacity-50"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Filter Tabs -->
                        <div class="mb-4">
                            <div class="btn-group" role="group">
                                <a href="?status=all" class="btn btn-outline-secondary filter-btn <?php echo $filter_status == 'all' ? 'active' : ''; ?>">
                                    <i class="bi bi-grid"></i> Все счета
                                </a>
                                <a href="?status=active" class="btn btn-outline-success filter-btn <?php echo $filter_status == 'active' ? 'active' : ''; ?>">
                                    <i class="bi bi-check-circle"></i> Активные
                                </a>
                                <a href="?status=inactive" class="btn btn-outline-danger filter-btn <?php echo $filter_status == 'inactive' ? 'active' : ''; ?>">
                                    <i class="bi bi-x-circle"></i> Неактивные
                                </a>
                            </div>
                        </div>
                        
                        <!-- Accounts Grid -->
                        <div class="row">
                            <?php if (count($accounts) > 0): ?>
                                <?php foreach ($accounts as $account): ?>
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="card account-card <?php echo !$account['is_active'] ? 'inactive' : ''; ?>">
                                            <div class="card-body">
                                                <div class="d-flex align-items-start mb-3">
                                                    <div class="account-icon" style="background: <?php echo htmlspecialchars($account['color']); ?>20; color: <?php echo htmlspecialchars($account['color']); ?>;">
                                                        <i class="<?php echo htmlspecialchars($account['icon']); ?>"></i>
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <h6 class="mb-0"><?php echo htmlspecialchars($account['bank_name']); ?></h6>
                                                        <small class="text-muted"><?php echo htmlspecialchars($account['account_number']); ?></small>
                                                        <?php if (!$account['is_active']): ?>
                                                            <span class="badge bg-secondary ms-2">Неактивен</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <div class="text-center">
                                                        <h4 class="<?php echo $account['current_balance'] >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                                            <?php echo number_format($account['current_balance'], 2, '.', ' '); ?> ₽
                                                        </h4>
                                                        <small class="text-muted">Текущий баланс</small>
                                                    </div>
                                                </div>
                                                
                                                <?php if (!empty($account['note'])): ?>
                                                    <div class="mb-2">
                                                        <small class="text-muted">
                                                            <i class="bi bi-info-circle"></i> <?php echo htmlspecialchars(substr($account['note'], 0, 50)); ?>
                                                        </small>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <div class="account-actions mt-3">
                                                    <div class="btn-group w-100">
                                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editAccountModal" 
                                                                data-account-id="<?php echo $account['id']; ?>"
                                                                data-account-number="<?php echo htmlspecialchars($account['account_number']); ?>"
                                                                data-bank-name="<?php echo htmlspecialchars($account['bank_name']); ?>"
                                                                data-note="<?php echo htmlspecialchars($account['note']); ?>"
                                                                data-opening-date="<?php echo $account['opening_date']; ?>"
                                                                data-account-color="<?php echo htmlspecialchars($account['color']); ?>"
                                                                data-account-icon="<?php echo htmlspecialchars($account['icon']); ?>"
                                                                data-is-active="<?php echo $account['is_active']; ?>">
                                                            <i class="bi bi-pencil"></i> Редактировать
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#adjustBalanceModal"
                                                                data-account-id="<?php echo $account['id']; ?>"
                                                                data-bank-name="<?php echo htmlspecialchars($account['bank_name']); ?>"
                                                                data-current-balance="<?php echo $account['current_balance']; ?>">
                                                            <i class="bi bi-calculator"></i> Корректировка
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteAccountModal"
                                                                data-account-id="<?php echo $account['id']; ?>"
                                                                data-account-name="<?php echo htmlspecialchars($account['bank_name'] . ' (' . $account['account_number'] . ')'); ?>">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="col-12">
                                    <div class="alert alert-info text-center">
                                        <i class="bi bi-info-circle fs-1"></i>
                                        <h5>Нет добавленных счетов</h5>
                                        <p>Нажмите кнопку "Добавить счет" чтобы создать первый счет</p>
                                        <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                                            <i class="bi bi-plus-circle"></i> Добавить счет
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Account Modal -->
    <div class="modal fade" id="addAccountModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Добавить счет</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="account_number" class="form-label">Номер счета *</label>
                                <input type="text" class="form-control" id="account_number" name="account_number" required>
                                <small class="text-muted">Номер карты или счета</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="bank_name" class="form-label">Банк / Название *</label>
                                <input type="text" class="form-control" id="bank_name" name="bank_name" required>
                                <small class="text-muted">Например: Сбербанк, Тинькофф, Наличные</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="initial_balance" class="form-label">Начальный баланс</label>
                                <input type="number" class="form-control" id="initial_balance" name="initial_balance" step="0.01" value="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="opening_date" class="form-label">Дата открытия</label>
                                <input type="date" class="form-control" id="opening_date" name="opening_date" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="note" class="form-label">Примечание</label>
                            <textarea class="form-control" id="note" name="note" rows="2"></textarea>
                            <small class="text-muted">Дополнительная информация о счете</small>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="account_color" class="form-label">Цвет счета</label>
                                <input type="color" class="form-control form-control-color" id="account_color" name="account_color" value="#6c757d">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Иконка счета</label>
                                <input type="hidden" id="account_icon" name="account_icon" value="bi-bank">
                                <div class="icon-selector">
                                    <?php foreach ($icons as $icon_code => $icon_name): ?>
                                        <div class="icon-option" data-icon="<?php echo $icon_code; ?>" onclick="selectIcon(this, '<?php echo $icon_code; ?>')">
                                            <i class="<?php echo $icon_code; ?>"></i>
                                            <small class="d-block"><?php echo $icon_name; ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" name="add_account" class="btn btn-primary">Добавить счет</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Account Modal -->
    <div class="modal fade" id="editAccountModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Редактировать счет</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" id="edit_account_id" name="account_id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_account_number" class="form-label">Номер счета *</label>
                                <input type="text" class="form-control" id="edit_account_number" name="account_number" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_bank_name" class="form-label">Банк / Название *</label>
                                <input type="text" class="form-control" id="edit_bank_name" name="bank_name" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_opening_date" class="form-label">Дата открытия</label>
                                <input type="date" class="form-control" id="edit_opening_date" name="opening_date">
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check mt-4">
                                    <input type="checkbox" class="form-check-input" id="edit_is_active" name="is_active">
                                    <label class="form-check-label" for="edit_is_active">Счет активен</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="edit_note" class="form-label">Примечание</label>
                            <textarea class="form-control" id="edit_note" name="note" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_account_color" class="form-label">Цвет счета</label>
                                <input type="color" class="form-control form-control-color" id="edit_account_color" name="account_color">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Иконка счета</label>
                                <input type="hidden" id="edit_account_icon" name="account_icon">
                                <div class="icon-selector" id="edit_icon_selector">
                                    <?php foreach ($icons as $icon_code => $icon_name): ?>
                                        <div class="icon-option" data-icon="<?php echo $icon_code; ?>" onclick="selectEditIcon(this, '<?php echo $icon_code; ?>')">
                                            <i class="<?php echo $icon_code; ?>"></i>
                                            <small class="d-block"><?php echo $icon_name; ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" name="edit_account" class="btn btn-primary">Сохранить изменения</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Adjust Balance Modal -->
    <div class="modal fade" id="adjustBalanceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calculator"></i> Корректировка баланса</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" id="adjust_account_id" name="account_id">
                        <div class="mb-3">
                            <label class="form-label">Счет</label>
                            <p class="fw-bold" id="adjust_bank_name"></p>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Текущий баланс</label>
                            <p class="fw-bold" id="current_balance_display"></p>
                        </div>
                        <div class="mb-3">
                            <label for="new_balance" class="form-label">Новый баланс *</label>
                            <input type="number" class="form-control" id="new_balance" name="new_balance" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label for="adjustment_note" class="form-label">Причина корректировки</label>
                            <textarea class="form-control" id="adjustment_note" name="adjustment_note" rows="2" placeholder="Например: Исправление ошибки, Обнаружение неучтенных средств..."></textarea>
                        </div>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Корректировка создаст автоматическую транзакцию для учета изменения баланса.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" name="adjust_balance" class="btn btn-warning">Выполнить корректировку</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Account Modal -->
    <div class="modal fade" id="deleteAccountModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Удаление счета</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" id="delete_account_id" name="account_id">
                        <p>Вы уверены, что хотите удалить счет <strong id="delete_account_name"></strong>?</p>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i> 
                            <strong>Внимание!</strong> Счет нельзя удалить, если на нем есть операции. Сначала удалите или переназначьте все операции с этого счета.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" name="delete_account" class="btn btn-danger">Удалить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Выбор иконки для добавления
        function selectIcon(element, iconCode) {
            document.querySelectorAll('.icon-option').forEach(opt => opt.classList.remove('selected'));
            element.classList.add('selected');
            document.getElementById('account_icon').value = iconCode;
        }
        
        // Выбор иконки для редактирования
        function selectEditIcon(element, iconCode) {
            document.querySelectorAll('#edit_icon_selector .icon-option').forEach(opt => opt.classList.remove('selected'));
            element.classList.add('selected');
            document.getElementById('edit_account_icon').value = iconCode;
        }
        
        // Обработка модального окна редактирования
        const editAccountModal = document.getElementById('editAccountModal');
        if (editAccountModal) {
            editAccountModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const accountId = button.getAttribute('data-account-id');
                const accountNumber = button.getAttribute('data-account-number');
                const bankName = button.getAttribute('data-bank-name');
                const note = button.getAttribute('data-note');
                const openingDate = button.getAttribute('data-opening-date');
                const accountColor = button.getAttribute('data-account-color');
                const accountIcon = button.getAttribute('data-account-icon');
                const isActive = button.getAttribute('data-is-active') === '1';
                
                document.getElementById('edit_account_id').value = accountId;
                document.getElementById('edit_account_number').value = accountNumber;
                document.getElementById('edit_bank_name').value = bankName;
                document.getElementById('edit_note').value = note || '';
                document.getElementById('edit_opening_date').value = openingDate;
                document.getElementById('edit_account_color').value = accountColor;
                document.getElementById('edit_account_icon').value = accountIcon;
                document.getElementById('edit_is_active').checked = isActive;
                
                // Выделяем выбранную иконку
                document.querySelectorAll('#edit_icon_selector .icon-option').forEach(opt => {
                    const icon = opt.getAttribute('data-icon');
                    if (icon === accountIcon) {
                        opt.classList.add('selected');
                    } else {
                        opt.classList.remove('selected');
                    }
                });
            });
        }
        
        // Обработка модального окна корректировки баланса
        const adjustBalanceModal = document.getElementById('adjustBalanceModal');
        if (adjustBalanceModal) {
            adjustBalanceModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const accountId = button.getAttribute('data-account-id');
                const bankName = button.getAttribute('data-bank-name');
                const currentBalance = button.getAttribute('data-current-balance');
                
                document.getElementById('adjust_account_id').value = accountId;
                document.getElementById('adjust_bank_name').textContent = bankName;
                document.getElementById('current_balance_display').textContent = parseFloat(currentBalance).toLocaleString('ru-RU', {minimumFractionDigits: 2}) + ' ₽';
                document.getElementById('new_balance').value = currentBalance;
            });
        }
        
        // Обработка модального окна удаления
        const deleteAccountModal = document.getElementById('deleteAccountModal');
        if (deleteAccountModal) {
            deleteAccountModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const accountId = button.getAttribute('data-account-id');
                const accountName = button.getAttribute('data-account-name');
                
                document.getElementById('delete_account_id').value = accountId;
                document.getElementById('delete_account_name').textContent = accountName;
            });
        }
        
        // Выбор первой иконки по умолчанию при добавлении
        document.addEventListener('DOMContentLoaded', function() {
            const firstIcon = document.querySelector('#addAccountModal .icon-option');
            if (firstIcon) {
                firstIcon.classList.add('selected');
            }
        });
    </script>
</body>
</html>