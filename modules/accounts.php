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
$filter_status = $_GET['status'] ?? 'all';

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Добавление счета
    if (isset($_POST['add_account'])) {
        $account_number = trim($_POST['account_number'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $opening_date = $_POST['opening_date'] ?? date('Y-m-d');
        $color = $_POST['account_color'] ?? '#6c757d';
        $icon = $_POST['account_icon'] ?? '💰';
        $initial_balance = floatval($_POST['initial_balance'] ?? 0);
        
        if (!empty($account_number) && !empty($bank_name)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO accounts (user_id, account_number, bank_name, note, opening_date, color, icon, initial_balance, current_balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $account_number, $bank_name, $note, $opening_date, $color, $icon, $initial_balance, $initial_balance]);
                $message = "Счет успешно добавлен";
                header("Location: accounts.php");
                exit;
            } catch (PDOException $e) {
                $error = "Ошибка при добавлении счета";
            }
        } else {
            $error = "Заполните обязательные поля";
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
        $icon = $_POST['account_icon'] ?? '💰';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (!empty($account_number) && !empty($bank_name) && $account_id > 0) {
            $stmt = $pdo->prepare("UPDATE accounts SET account_number = ?, bank_name = ?, note = ?, opening_date = ?, color = ?, icon = ?, is_active = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$account_number, $bank_name, $note, $opening_date, $color, $icon, $is_active, $account_id, $user_id]);
            $message = "Счет успешно обновлен";
            header("Location: accounts.php");
            exit;
        }
    }
    
    // Корректировка баланса
    elseif (isset($_POST['adjust_balance'])) {
        $account_id = $_POST['account_id'] ?? 0;
        $new_balance = floatval($_POST['new_balance'] ?? 0);
        
        if ($account_id > 0) {
            $stmt = $pdo->prepare("SELECT current_balance FROM accounts WHERE id = ? AND user_id = ?");
            $stmt->execute([$account_id, $user_id]);
            $account = $stmt->fetch();
            
            if ($account) {
                $old_balance = $account['current_balance'];
                $difference = $new_balance - $old_balance;
                
                $stmt = $pdo->prepare("UPDATE accounts SET current_balance = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$new_balance, $account_id, $user_id]);
                
                $message = "Баланс скорректирован";
                header("Location: accounts.php");
                exit;
            }
        }
    }
    
    // Удаление счета
    elseif (isset($_POST['delete_account'])) {
        $account_id = $_POST['account_id'] ?? 0;
        
        if ($account_id > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM transactions WHERE account_id = ? AND user_id = ?");
            $stmt->execute([$account_id, $user_id]);
            $count = $stmt->fetch()['count'];
            
            if ($count > 0) {
                $error = "Невозможно удалить счет. Есть операции.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM accounts WHERE id = ? AND user_id = ?");
                $stmt->execute([$account_id, $user_id]);
                $message = "Счет удален";
                header("Location: accounts.php");
                exit;
            }
        }
    }
}

// Получение списка счетов
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

$total_balance = array_sum(array_column($accounts, 'current_balance'));
$active_count = count(array_filter($accounts, function($a) { return $a['is_active']; }));

// Список иконок
$icons = ['💰', '🏦', '💳', '💵', '🏧', '💶', '💷', '💴', '👛', '💎'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#667eea">
    <title>Счета - Финансовый дневник</title>
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
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            padding: 0 16px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 14px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .stat-value {
            font-size: 20px;
            font-weight: bold;
            margin: 8px 0 4px;
        }
        
        .filter-bar {
            display: flex;
            gap: 8px;
            padding: 0 16px;
            margin-bottom: 16px;
        }
        
        .filter-btn {
            flex: 1;
            padding: 10px;
            border-radius: 30px;
            text-align: center;
            background: white;
            color: #6c757d;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .filter-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .accounts-list {
            margin: 0 16px;
        }
        
        .account-card {
            background: white;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            cursor: pointer;
            transition: transform 0.2s;
            position: relative;
        }
        
        .account-card:active {
            transform: scale(0.98);
        }
        
        .account-card.inactive {
            opacity: 0.7;
            background: #f8f9fa;
        }
        
        .account-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .account-icon {
            font-size: 32px;
            width: 50px;
            height: 50px;
            background: #f8f9fa;
            border-radius: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .account-name {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 4px;
        }
        
        .account-number {
            font-size: 12px;
            color: #6c757d;
        }
        
        .account-balance {
            font-size: 20px;
            font-weight: bold;
            text-align: right;
        }
        
        .balance-positive {
            color: #28a745;
        }
        
        .balance-negative {
            color: #dc3545;
        }
        
        .account-note {
            font-size: 12px;
            color: #6c757d;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #e9ecef;
        }
        
        .badge-status {
            position: absolute;
            top: 12px;
            right: 12px;
            background: #ffc107;
            color: #000;
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 12px;
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
        
        .fab:active {
            transform: scale(0.95);
        }
        
        .modal-content {
            border-radius: 24px 24px 0 0;
        }
        
        .form-control, .form-select {
            border-radius: 12px;
            padding: 12px;
            font-size: 15px;
        }
        
        .icon-selector {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            margin-top: 8px;
        }
        
        .icon-option {
            text-align: center;
            padding: 10px;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            cursor: pointer;
            font-size: 24px;
        }
        
        .icon-option.selected {
            background: linear-gradient(135deg, #667eea20 0%, #764ba220 100%);
            border-color: #667eea;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.5;
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
        
        .btn-danger {
            background: #dc3545;
            border: none;
            border-radius: 30px;
            padding: 12px;
        }
        
        .btn-secondary {
            border-radius: 30px;
            padding: 12px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 30px;
            padding: 12px;
        }
    </style>
</head>
<body>
    <div class="mobile-header">
        <div class="d-flex justify-content-between align-items-center">
            <a href="../dashboard.php" class="back-button">← Назад</a>
            <button class="back-button" id="filterBtn">📊 Фильтр</button>
        </div>
        <div class="page-title">Счета</div>
        <div class="small">Управление банковскими счетами</div>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div>💰</div>
            <div class="stat-value"><?php echo number_format($total_balance, 0, '.', ' '); ?> ₽</div>
            <div class="small">Общий баланс</div>
        </div>
        <div class="stat-card">
            <div>🏦</div>
            <div class="stat-value"><?php echo $active_count; ?> / <?php echo count($accounts); ?></div>
            <div class="small">Активных счетов</div>
        </div>
    </div>
    
    <div class="filter-bar">
        <a href="?status=all" class="filter-btn <?php echo $filter_status == 'all' ? 'active' : ''; ?>">Все</a>
        <a href="?status=active" class="filter-btn <?php echo $filter_status == 'active' ? 'active' : ''; ?>">Активные</a>
        <a href="?status=inactive" class="filter-btn <?php echo $filter_status == 'inactive' ? 'active' : ''; ?>">Неактивные</a>
    </div>
    
    <div class="accounts-list" id="accountsList">
        <?php if (count($accounts) > 0): ?>
            <?php foreach ($accounts as $acc): ?>
                <div class="account-card <?php echo !$acc['is_active'] ? 'inactive' : ''; ?>" 
                     data-id="<?php echo $acc['id']; ?>"
                     data-number="<?php echo htmlspecialchars($acc['account_number']); ?>"
                     data-bank="<?php echo htmlspecialchars($acc['bank_name']); ?>"
                     data-note="<?php echo htmlspecialchars($acc['note'] ?? ''); ?>"
                     data-date="<?php echo $acc['opening_date']; ?>"
                     data-color="<?php echo htmlspecialchars($acc['color']); ?>"
                     data-icon="<?php echo htmlspecialchars($acc['icon']); ?>"
                     data-active="<?php echo $acc['is_active']; ?>"
                     data-balance="<?php echo $acc['current_balance']; ?>">
                    <div class="account-header">
                        <div class="d-flex align-items-center gap-3">
                            <div class="account-icon" style="background: <?php echo htmlspecialchars($acc['color']); ?>20;">
                                <?php echo htmlspecialchars($acc['icon']); ?>
                            </div>
                            <div>
                                <div class="account-name"><?php echo htmlspecialchars($acc['bank_name']); ?></div>
                                <div class="account-number"><?php echo htmlspecialchars(substr($acc['account_number'], -8)); ?></div>
                            </div>
                        </div>
                        <div class="account-balance <?php echo $acc['current_balance'] >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                            <?php echo number_format($acc['current_balance'], 0, '.', ' '); ?> ₽
                        </div>
                    </div>
                    <?php if (!empty($acc['note'])): ?>
                        <div class="account-note">
                            📝 <?php echo htmlspecialchars(substr($acc['note'], 0, 50)); ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!$acc['is_active']): ?>
                        <div class="badge-status">Неактивен</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="fs-1">🏦</div>
                <h5>Нет счетов</h5>
                <p>Добавьте первый счет</p>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="fab" id="addBtn">
        <i>+</i>
    </div>
    
    <div class="mobile-nav">
        <div class="row g-0">
            <div class="col-3"><a href="../dashboard.php" class="nav-item">🏠<span>Главная</span></a></div>
            <div class="col-3"><a href="finances.php" class="nav-item">💰<span>Финансы</span></a></div>
            <div class="col-3"><a href="statistics.php" class="nav-item">📊<span>Статистика</span></a></div>
            <div class="col-3"><a href="../profile.php" class="nav-item">👤<span>Профиль</span></a></div>
        </div>
    </div>
    
    <!-- Add/Edit Modal -->
    <div class="modal fade" id="accountModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 id="modalTitle">Добавить счет</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="accountForm">
                    <div class="modal-body">
                        <input type="hidden" name="account_id" id="accountId">
                        
                        <input type="text" name="account_number" class="form-control mb-2" placeholder="Номер счета *" required>
                        <input type="text" name="bank_name" class="form-control mb-2" placeholder="Банк / Название *" required>
                        <input type="number" name="initial_balance" class="form-control mb-2" placeholder="Начальный баланс" step="0.01" value="0">
                        <input type="date" name="opening_date" class="form-control mb-2" value="<?php echo date('Y-m-d'); ?>">
                        <textarea name="note" class="form-control mb-2" rows="2" placeholder="Примечание"></textarea>
                        
                        <label class="form-label">Цвет</label>
                        <input type="color" name="account_color" class="form-control mb-2" value="#6c757d">
                        
                        <label class="form-label">Иконка</label>
                        <input type="hidden" name="account_icon" id="selectedIcon" value="💰">
                        <div class="icon-selector" id="iconSelector">
                            <?php foreach ($icons as $icon): ?>
                                <div class="icon-option" data-icon="<?php echo $icon; ?>"><?php echo $icon; ?></div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="form-check mt-3">
                            <input type="checkbox" name="is_active" class="form-check-input" id="isActive" checked>
                            <label class="form-check-label" for="isActive">Счет активен</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" name="add_account" class="btn btn-primary" id="submitBtn">Добавить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Adjust Balance Modal -->
    <div class="modal fade" id="balanceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Корректировка баланса</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="account_id" id="balanceAccountId">
                        <p id="balanceAccountName"></p>
                        <input type="number" name="new_balance" class="form-control" placeholder="Новый баланс" step="0.01" required>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="adjust_balance" class="btn btn-primary">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Удалить счет?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="account_id" id="deleteAccountId">
                        <p>Вы уверены, что хотите удалить этот счет?</p>
                        <div class="alert alert-warning">⚠️ Счет нельзя удалить, если есть операции</div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="delete_account" class="btn btn-danger">Удалить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Страница загружена');
            
            // Модальные окна
            const accountModal = new bootstrap.Modal(document.getElementById('accountModal'));
            const balanceModal = new bootstrap.Modal(document.getElementById('balanceModal'));
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            
            // Кнопка добавления
            document.getElementById('addBtn').onclick = function() {
                document.getElementById('modalTitle').innerText = 'Добавить счет';
                document.getElementById('accountForm').reset();
                document.getElementById('accountId').value = '';
                document.getElementById('isActive').checked = true;
                document.getElementById('selectedIcon').value = '💰';
                document.querySelectorAll('.icon-option').forEach(opt => opt.classList.remove('selected'));
                document.querySelector('.icon-option').classList.add('selected');
                document.getElementById('submitBtn').name = 'add_account';
                document.getElementById('submitBtn').innerHTML = 'Добавить';
                accountModal.show();
            };
            
            // Фильтр
            document.getElementById('filterBtn').onclick = function() {
                const filterModal = new bootstrap.Modal(document.getElementById('filterModal'));
                if (document.getElementById('filterModal')) {
                    filterModal.show();
                } else {
                    window.location.href = '?status=' + (document.querySelector('.filter-btn.active')?.getAttribute('href')?.split('=')[1] || 'all');
                }
            };
            
            // Выбор иконки
            document.querySelectorAll('.icon-option').forEach(icon => {
                icon.onclick = function() {
                    document.querySelectorAll('.icon-option').forEach(i => i.classList.remove('selected'));
                    this.classList.add('selected');
                    document.getElementById('selectedIcon').value = this.getAttribute('data-icon');
                };
            });
            
            // Карточки счетов
            document.querySelectorAll('.account-card').forEach(card => {
                card.onclick = function(e) {
                    e.stopPropagation();
                    const id = this.getAttribute('data-id');
                    const number = this.getAttribute('data-number');
                    const bank = this.getAttribute('data-bank');
                    const note = this.getAttribute('data-note');
                    const date = this.getAttribute('data-date');
                    const color = this.getAttribute('data-color');
                    const icon = this.getAttribute('data-icon');
                    const active = this.getAttribute('data-active') === '1';
                    const balance = this.getAttribute('data-balance');
                    
                    // Показываем меню действий
                    const action = confirm(`Выберите действие:\nОК - Редактировать\nОтмена - Корректировка баланса\nУдалить - в другой раз`);
                    
                    if (action) {
                        // Редактирование
                        document.getElementById('modalTitle').innerText = 'Редактировать счет';
                        document.getElementById('accountId').value = id;
                        document.querySelector('input[name="account_number"]').value = number;
                        document.querySelector('input[name="bank_name"]').value = bank;
                        document.querySelector('textarea[name="note"]').value = note;
                        document.querySelector('input[name="opening_date"]').value = date;
                        document.querySelector('input[name="account_color"]').value = color;
                        document.getElementById('selectedIcon').value = icon;
                        document.getElementById('isActive').checked = active;
                        
                        // Выделяем иконку
                        document.querySelectorAll('.icon-option').forEach(opt => {
                            if (opt.getAttribute('data-icon') === icon) {
                                opt.classList.add('selected');
                            } else {
                                opt.classList.remove('selected');
                            }
                        });
                        
                        document.getElementById('submitBtn').name = 'edit_account';
                        document.getElementById('submitBtn').innerHTML = 'Сохранить';
                        accountModal.show();
                    } else {
                        // Корректировка баланса
                        document.getElementById('balanceAccountId').value = id;
                        document.getElementById('balanceAccountName').innerHTML = `<strong>${bank}</strong><br>Текущий баланс: ${parseFloat(balance).toLocaleString()} ₽`;
                        document.querySelector('#balanceModal input[name="new_balance"]').value = balance;
                        balanceModal.show();
                    }
                };
                
                // Длинное нажатие для удаления
                let pressTimer;
                card.addEventListener('touchstart', function(e) {
                    pressTimer = setTimeout(() => {
                        const id = this.getAttribute('data-id');
                        const bank = this.getAttribute('data-bank');
                        document.getElementById('deleteAccountId').value = id;
                        deleteModal.show();
                    }, 500);
                });
                
                card.addEventListener('touchend', function() {
                    clearTimeout(pressTimer);
                });
                
                card.addEventListener('touchmove', function() {
                    clearTimeout(pressTimer);
                });
            });
        });
    </script>
</body>
</html>