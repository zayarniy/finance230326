<?php
//session_start();
require_once '../config/session.php';
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$filter_status = $_GET['status'] ?? 'all';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_account'])) {
        $account_number = trim($_POST['account_number'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $opening_date = $_POST['opening_date'] ?? date('Y-m-d');
        $color = $_POST['account_color'] ?? '#6c757d';
        $icon = $_POST['account_icon'] ?? '🏦';
        $initial_balance = floatval($_POST['initial_balance'] ?? 0);

        if (!empty($account_number) && !empty($bank_name)) {
            $stmt = $pdo->prepare("INSERT INTO accounts (user_id, account_number, bank_name, note, opening_date, color, icon, initial_balance, current_balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $account_number, $bank_name, $note, $opening_date, $color, $icon, $initial_balance, $initial_balance]);
            header("Location: accounts.php");
            exit;
        }
    } elseif (isset($_POST['edit_account'])) {
        $account_id = $_POST['account_id'] ?? 0;
        $account_number = trim($_POST['account_number'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $opening_date = $_POST['opening_date'] ?? date('Y-m-d');
        $color = $_POST['account_color'] ?? '#6c757d';
        $icon = $_POST['account_icon'] ?? '🏦';
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($account_id > 0) {
            $stmt = $pdo->prepare("UPDATE accounts SET account_number = ?, bank_name = ?, note = ?, opening_date = ?, color = ?, icon = ?, is_active = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$account_number, $bank_name, $note, $opening_date, $color, $icon, $is_active, $account_id, $user_id]);
            header("Location: accounts.php");
            exit;
        }
    } elseif (isset($_POST['adjust_balance'])) {
        $account_id = $_POST['account_id'] ?? 0;
        $new_balance = floatval($_POST['new_balance'] ?? 0);

        if ($account_id > 0) {
            $stmt = $pdo->prepare("UPDATE accounts SET current_balance = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$new_balance, $account_id, $user_id]);
            header("Location: accounts.php");
            exit;
        }
    } elseif (isset($_POST['delete_account'])) {
        $account_id = $_POST['account_id'] ?? 0;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE account_id = ? AND user_id = ?");
        $stmt->execute([$account_id, $user_id]);
        if ($stmt->fetchColumn() == 0) {
            $stmt = $pdo->prepare("DELETE FROM accounts WHERE id = ? AND user_id = ?");
            $stmt->execute([$account_id, $user_id]);
            header("Location: accounts.php");
            exit;
        }
    }
}

$sql = "SELECT * FROM accounts WHERE user_id = ?";
$params = [$user_id];
if ($filter_status == 'active')
    $sql .= " AND is_active = 1";
if ($filter_status == 'inactive')
    $sql .= " AND is_active = 0";
$sql .= " ORDER BY current_balance DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$accounts = $stmt->fetchAll();

$total_balance = array_sum(array_column($accounts, 'current_balance'));
$active_count = count(array_filter($accounts, fn($a) => $a['is_active']));
$icons = ['🏦', '💳', '💰', '👛', '🐷', '🏢', '🔒', '🪙'];
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#667eea">
    <title>Счета - Финансовый дневник</title>
    <link rel="icon" type="image/png" href="../favicon.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
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
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            padding: 0 16px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 16px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
            margin: 8px 0;
        }

        .filter-bar {
            background: white;
            margin: 0 16px 16px;
            border-radius: 20px;
            padding: 8px;
            display: flex;
            gap: 8px;
        }

        .filter-btn {
            flex: 1;
            padding: 10px;
            border-radius: 30px;
            text-align: center;
            text-decoration: none;
            background: #f8f9fa;
            color: #6c757d;
        }

        .filter-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .account-card {
            background: white;
            border-radius: 16px;
            padding: 16px;
            margin: 0 16px 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            cursor: pointer;
        }

        .account-card.inactive {
            opacity: 0.6;
            background: #f8f9fa;
        }

        .account-icon {
            width: 48px;
            height: 48px;
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
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
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
            cursor: pointer;
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
        }

        .nav-scroll .nav-item.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
        }

        .nav-scroll .nav-item i {
            font-size: 20px;
            display: block;
            margin-bottom: 4px;
        }

        .nav-scroll .nav-item span {
            font-size: 10px;
            white-space: nowrap;
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

        .balance-positive {
            color: #28a745;
        }

        .balance-negative {
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

        .icon-selector {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-top: 8px;
        }

        .icon-option {
            text-align: center;
            padding: 10px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            cursor: pointer;
            font-size: 24px;
        }

        .icon-option.selected {
            border-color: #667eea;
            background: #f0f0ff;
        }
    </style>
</head>

<body>
    <div class="mobile-header">
        <div class="d-flex justify-content-between align-items-center">
            <a href="../dashboard.php" class="back-button">← Назад</a>
            <button class="back-button" id="filterBtn">📊 Фильтр</button>
        </div>
        <div class="page-title fs-3 fw-bold mt-2">Счета</div>
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
        <a href="?status=active"
            class="filter-btn <?php echo $filter_status == 'active' ? 'active' : ''; ?>">Активные</a>
        <a href="?status=inactive"
            class="filter-btn <?php echo $filter_status == 'inactive' ? 'active' : ''; ?>">Неактивные</a>
    </div>

    <?php foreach ($accounts as $acc): ?>
        <div class="account-card <?php echo !$acc['is_active'] ? 'inactive' : ''; ?>" data-id="<?php echo $acc['id']; ?>"
            data-number="<?php echo htmlspecialchars($acc['account_number']); ?>"
            data-bank="<?php echo htmlspecialchars($acc['bank_name']); ?>"
            data-note="<?php echo htmlspecialchars($acc['note'] ?? ''); ?>" data-date="<?php echo $acc['opening_date']; ?>"
            data-color="<?php echo $acc['color']; ?>" data-icon="<?php echo $acc['icon']; ?>"
            data-active="<?php echo $acc['is_active']; ?>" data-balance="<?php echo $acc['current_balance']; ?>">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-3">
                    <!--
                <div class="account-icon" style="background: <?php echo $acc['color']; ?>20; color: <?php echo $acc['color']; ?>"><?php echo $acc['icon']; ?></div>-->
                    <div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($acc['bank_name']); ?></div>
                        <div class="small text-muted"><?php echo htmlspecialchars(substr($acc['account_number'], -8)); ?>
                        </div>
                    </div>
                </div>
                <div class="fw-bold <?php echo $acc['current_balance'] >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                    <?php echo number_format($acc['current_balance'], 0, '.', ' '); ?> ₽</div>
            </div>
            <?php if (!empty($acc['note'])): ?>
                <div class="small text-muted mt-2">📝 <?php echo htmlspecialchars(substr($acc['note'], 0, 40)); ?></div>
            <?php endif; ?>
            <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                <span class="badge bg-secondary"><?php echo $acc['is_active'] ? '✅ Активен' : '⛔ Неактивен'; ?></span>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-primary edit-btn">✏️</button>
                    <button class="btn btn-sm btn-outline-warning adjust-btn">💰</button>
                    <button class="btn btn-sm btn-outline-danger delete-btn">🗑️</button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (empty($accounts)): ?>
        <div class="text-center text-muted py-5">Нет счетов</div>
    <?php endif; ?>

    <div class="fab" id="addBtn"><i class="bi bi-plus-lg"></i></div>

    <div class="mobile-nav">
        <div class="nav-scroll">
            <a href="../dashboard.php" class="nav-item">
                <i class="bi bi-house-door"></i>
                <span>Главная</span>
            </a>
            <a href="finances.php" class="nav-item">
                <i class="bi bi-calculator"></i>
                <span>Финансы</span>
            </a>
            <a href="accounts.php" class="nav-item active">
                <i class="bi bi-bank"></i>
                <span>Счета</span>
            </a>
            <a href="statistics.php" class="nav-item">
                <i class="bi bi-graph-up"></i>
                <span>Статистика</span>
            </a>
            <a href="modules/transfers.php" class="nav-item">
                <i class="bi bi-arrow-left-right"></i>
                <span>Переводы</span>
            </a>
            <a href="modules/debts.php" class="nav-item">
                <i class="bi bi-credit-card-2-front"></i>
                <span>Долги</span>
            </a>
            <a href="../profile.php" class="nav-item">
                <i class="bi bi-person"></i>
                <span>Профиль</span>
            </a>
        </div>
    </div>

    <!-- Filter Modal -->
    <div class="modal fade" id="filterModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Фильтр</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <a href="?status=all" class="btn btn-outline-secondary w-100 mb-2 rounded-pill">Все счета</a>
                    <a href="?status=active" class="btn btn-outline-success w-100 mb-2 rounded-pill">Активные</a>
                    <a href="?status=inactive" class="btn btn-outline-danger w-100 rounded-pill">Неактивные</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Account Modal -->
    <div class="modal fade" id="accountModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 id="modalTitle">Добавить счет</h5><button type="button" class="btn-close"
                        data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="account_id" id="accountId">
                        <input type="text" name="bank_name" id="bankName" class="form-control mb-2"
                            placeholder="Название банка" required>
                        <input type="text" name="account_number" id="accountNumber" class="form-control mb-2"
                            placeholder="Номер счета" required>
                        <input type="number" name="initial_balance" id="initialBalance" class="form-control mb-2"
                            placeholder="Начальный баланс" step="0.01" value="0">
                        <input type="date" name="opening_date" id="openingDate" class="form-control mb-2"
                            value="<?php echo date('Y-m-d'); ?>">
                        <textarea name="note" id="note" class="form-control mb-2" rows="2"
                            placeholder="Примечание"></textarea>
                        <input type="color" name="account_color" id="accountColor" class="form-control mb-2"
                            value="#6c757d">
                        <input type="hidden" name="account_icon" id="accountIcon" value="🏦">
                        <div class="icon-selector" id="iconSelector">
                            <?php foreach ($icons as $icon): ?>
                                <div class="icon-option" data-icon="<?php echo $icon; ?>"><?php echo $icon; ?></div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-check mt-3">
                            <input type="checkbox" name="is_active" id="isActive" class="form-check-input" value="1"
                                checked>
                            <label class="form-check-label">Счет активен</label>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="submit" name="add_account"
                            class="btn btn-primary w-100 rounded-pill" id="submitBtn">Добавить</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Adjust Modal -->
    <div class="modal fade" id="adjustModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Корректировка баланса</h5><button type="button" class="btn-close"
                        data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="account_id" id="adjustId">
                        <p id="adjustBank" class="fw-bold"></p>
                        <p>Текущий баланс: <span id="currentBalance"></span> ₽</p>
                        <input type="number" name="new_balance" class="form-control" step="0.01" required
                            placeholder="Новый баланс">
                    </div>
                    <div class="modal-footer"><button type="submit" name="adjust_balance"
                            class="btn btn-warning w-100 rounded-pill">Скорректировать</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Удалить счет?</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="account_id" id="deleteId">
                        <p>Удалить счет <strong id="deleteName"></strong>?</p>
                    </div>
                    <div class="modal-footer"><button type="submit" name="delete_account"
                            class="btn btn-danger w-100 rounded-pill">Удалить</button></div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const filterModal = new bootstrap.Modal(document.getElementById('filterModal'));
            const accountModal = new bootstrap.Modal(document.getElementById('accountModal'));
            const adjustModal = new bootstrap.Modal(document.getElementById('adjustModal'));
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));

            document.getElementById('filterBtn').onclick = () => filterModal.show();
            document.getElementById('addBtn').onclick = () => { resetForm(); accountModal.show(); };

            function resetForm() {
                document.getElementById('accountForm')?.reset();
                document.getElementById('accountId').value = '';
                document.getElementById('bankName').value = '';
                document.getElementById('accountNumber').value = '';
                document.getElementById('initialBalance').value = '0';
                document.getElementById('note').value = '';
                document.getElementById('accountColor').value = '#6c757d';
                document.getElementById('accountIcon').value = '🏦';
                document.getElementById('isActive').checked = true;
                document.getElementById('modalTitle').innerText = 'Добавить счет';
                document.getElementById('submitBtn').name = 'add_account';
                document.getElementById('submitBtn').innerHTML = 'Добавить';
                document.querySelectorAll('.icon-option').forEach(opt => opt.classList.remove('selected'));
                document.querySelector('.icon-option[data-icon="🏦"]').classList.add('selected');
            }

            document.querySelectorAll('.icon-option').forEach(opt => {
                opt.onclick = function () {
                    document.querySelectorAll('.icon-option').forEach(o => o.classList.remove('selected'));
                    this.classList.add('selected');
                    document.getElementById('accountIcon').value = this.dataset.icon;
                };
            });

            document.querySelectorAll('.edit-btn').forEach(btn => {
                btn.onclick = function (e) {
                    e.stopPropagation();
                    const card = this.closest('.account-card');
                    document.getElementById('accountId').value = card.dataset.id;
                    document.getElementById('bankName').value = card.dataset.bank;
                    document.getElementById('accountNumber').value = card.dataset.number;
                    document.getElementById('initialBalance').value = '0';
                    document.getElementById('openingDate').value = card.dataset.date;
                    document.getElementById('note').value = card.dataset.note;
                    document.getElementById('accountColor').value = card.dataset.color;
                    document.getElementById('accountIcon').value = card.dataset.icon;
                    document.getElementById('isActive').checked = card.dataset.active === '1';
                    document.getElementById('modalTitle').innerText = 'Редактировать счет';
                    document.getElementById('submitBtn').name = 'edit_account';
                    document.getElementById('submitBtn').innerHTML = 'Сохранить';
                    document.querySelectorAll('.icon-option').forEach(opt => {
                        opt.classList.remove('selected');
                        if (opt.dataset.icon === card.dataset.icon) opt.classList.add('selected');
                    });
                    accountModal.show();
                };
            });

            document.querySelectorAll('.adjust-btn').forEach(btn => {
                btn.onclick = function (e) {
                    e.stopPropagation();
                    const card = this.closest('.account-card');
                    document.getElementById('adjustId').value = card.dataset.id;
                    document.getElementById('adjustBank').innerText = card.dataset.bank;
                    document.getElementById('currentBalance').innerText = parseFloat(card.dataset.balance).toLocaleString();
                    adjustModal.show();
                };
            });

            document.querySelectorAll('.delete-btn').forEach(btn => {
                btn.onclick = function (e) {
                    e.stopPropagation();
                    const card = this.closest('.account-card');
                    document.getElementById('deleteId').value = card.dataset.id;
                    document.getElementById('deleteName').innerText = card.dataset.bank;
                    deleteModal.show();
                };
            });
        });
    </script>
</body>

</html>