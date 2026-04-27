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

// Фильтры
$filter_status = $_GET['status'] ?? 'active';    // active, paid, all
$filter_category = $_GET['category'] ?? 'all';
$filter_tag = $_GET['tag'] ?? '';
$filter_period = $_GET['period'] ?? 'month';     // month, all
$filter_date_from = $_GET['date_from'] ?? date('Y-m-01');
$filter_date_to = $_GET['date_to'] ?? date('Y-m-t');

// Обработка POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Добавление долга
    if (isset($_POST['add_debt'])) {
        $creditor = trim($_POST['creditor'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $category_id = $_POST['category_id'] ?? null;
        $tags_text = trim($_POST['tags_text'] ?? '');
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $created_at = $_POST['created_at'] ?? date('Y-m-d');

        if (!empty($creditor) && $amount > 0) {
            $stmt = $pdo->prepare("INSERT INTO debts (user_id, creditor, amount, remaining_amount, description, category_id, tags_text, due_date, created_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            $stmt->execute([$user_id, $creditor, $amount, $amount, $description, $category_id ?: null, $tags_text, $due_date, $created_at]);
            header("Location: debts.php");
            exit;
        } else {
            $error = "Заполните обязательные поля";
        }
    }

    // Добавление оплаты
    if (isset($_POST['add_payment'])) {
        $debt_id = $_POST['debt_id'] ?? 0;
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $amount = floatval($_POST['amount'] ?? 0);
        $account_id = $_POST['account_id'] ?? 0;
        $comment = trim($_POST['comment'] ?? '');

        if ($debt_id > 0 && $amount > 0 && $account_id > 0) {
            // Получаем текущий остаток долга
            $stmt = $pdo->prepare("SELECT remaining_amount, creditor FROM debts WHERE id = ? AND user_id = ? AND status = 'active'");
            $stmt->execute([$debt_id, $user_id]);
            $debt = $stmt->fetch();
            if (!$debt) {
                $error = "Долг не найден или уже оплачен";
            } elseif ($amount > $debt['remaining_amount']) {
                $error = "Сумма оплаты превышает остаток долга";
            } else {
                try {
                    $pdo->beginTransaction();
                    // Записываем платеж
                    $stmt = $pdo->prepare("INSERT INTO debt_payments (debt_id, user_id, payment_date, amount, account_id, comment) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$debt_id, $user_id, $payment_date, $amount, $account_id, $comment]);

                    // Обновляем остаток долга
                    $new_remaining = $debt['remaining_amount'] - $amount;
                    $new_status = ($new_remaining <= 0) ? 'paid' : 'active';
                    $stmt = $pdo->prepare("UPDATE debts SET remaining_amount = ?, status = ? WHERE id = ?");
                    $stmt->execute([$new_remaining, $new_status, $debt_id]);

                    // Создаем транзакцию в финансах (расход)
                    // Находим или создаем категорию "Погашение долга"
                    $stmt = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = 'Погашение долга' AND type = 'expense' LIMIT 1");
                    $stmt->execute([$user_id]);
                    $cat = $stmt->fetch();
                    if (!$cat) {
                        $stmt = $pdo->prepare("INSERT INTO categories (user_id, name, color, type) VALUES (?, 'Погашение долга', '#6c757d', 'expense')");
                        $stmt->execute([$user_id]);
                        $cat_id = $pdo->lastInsertId();
                    } else {
                        $cat_id = $cat['id'];
                    }

                    $desc = "Оплата долга: " . $debt['creditor'] . " (" . $comment . ")";
                    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_id, category_id, type, amount, transaction_date, description) VALUES (?, ?, ?, 'expense', ?, ?, ?)");
                    $stmt->execute([$user_id, $account_id, $cat_id, $amount, $payment_date, $desc]);

                    // Обновляем баланс счета
                    $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$amount, $account_id, $user_id]);

                    $pdo->commit();
                    header("Location: debts.php");
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "Ошибка при сохранении оплаты";
                }
            }
        } else {
            $error = "Заполните все поля";
        }
    }

    // Редактирование долга (только неоплаченные)
    if (isset($_POST['edit_debt'])) {
        $debt_id = $_POST['debt_id'] ?? 0;
        $creditor = trim($_POST['creditor'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $category_id = $_POST['category_id'] ?? null;
        $tags_text = trim($_POST['tags_text'] ?? '');
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $created_at = $_POST['created_at'] ?? date('Y-m-d');

        if ($debt_id > 0 && !empty($creditor) && $amount > 0) {
            // Проверяем, что долг активен (не оплачен)
            $stmt = $pdo->prepare("SELECT status, remaining_amount FROM debts WHERE id = ? AND user_id = ?");
            $stmt->execute([$debt_id, $user_id]);
            $debt = $stmt->fetch();
            if ($debt && $debt['status'] != 'paid') {
                // При изменении суммы, остаток корректируем (разница)
                $old_amount = $debt['remaining_amount'] + ($debt['remaining_amount'] - $debt['remaining_amount']); // не нужно
                $new_remaining = $debt['remaining_amount'] + ($amount - $debt['remaining_amount'] - $debt['remaining_amount']); // сложно
                // Проще: новая сумма - уже оплаченная часть = новый остаток
                $paid_portion = $debt['remaining_amount'] - $debt['remaining_amount']; // нет, надо считать оплаченное
                // Рассчитаем оплаченную сумму: изначальная сумма - текущий остаток
                $stmt_old = $pdo->prepare("SELECT amount FROM debts WHERE id = ?");
                $stmt_old->execute([$debt_id]);
                $old_full = $stmt_old->fetchColumn();
                $paid_amount = $old_full - $debt['remaining_amount'];
                $new_remaining = $amount - $paid_amount;
                if ($new_remaining < 0)
                    $new_remaining = 0;
                $new_status = ($new_remaining <= 0) ? 'paid' : 'active';

                $stmt = $pdo->prepare("UPDATE debts SET creditor = ?, amount = ?, remaining_amount = ?, description = ?, category_id = ?, tags_text = ?, due_date = ?, created_at = ?, status = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$creditor, $amount, $new_remaining, $description, $category_id ?: null, $tags_text, $due_date, $created_at, $new_status, $debt_id, $user_id]);
                header("Location: debts.php");
                exit;
            } else {
                $error = "Нельзя редактировать оплаченный долг";
            }
        } else {
            $error = "Заполните обязательные поля";
        }
    }

    // Удаление долга (только если нет платежей)
    if (isset($_POST['delete_debt'])) {
        $debt_id = $_POST['debt_id'] ?? 0;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM debt_payments WHERE debt_id = ?");
        $stmt->execute([$debt_id]);
        if ($stmt->fetchColumn() == 0) {
            $stmt = $pdo->prepare("DELETE FROM debts WHERE id = ? AND user_id = ?");
            $stmt->execute([$debt_id, $user_id]);
            header("Location: debts.php");
            exit;
        } else {
            $error = "Нельзя удалить долг, по которому были платежи";
        }
    }

    // Удаление платежа (с возвратом остатка долга и отменой транзакции)
    if (isset($_POST['delete_payment'])) {
        $payment_id = $_POST['payment_id'] ?? 0;
        $stmt = $pdo->prepare("SELECT debt_id, amount, account_id, payment_date FROM debt_payments WHERE id = ? AND user_id = ?");
        $stmt->execute([$payment_id, $user_id]);
        $payment = $stmt->fetch();
        if ($payment) {
            try {
                $pdo->beginTransaction();
                // Возвращаем остаток долга
                $stmt = $pdo->prepare("UPDATE debts SET remaining_amount = remaining_amount + ?, status = 'active' WHERE id = ?");
                $stmt->execute([$payment['amount'], $payment['debt_id']]);
                // Удаляем платеж
                $stmt = $pdo->prepare("DELETE FROM debt_payments WHERE id = ?");
                $stmt->execute([$payment_id]);
                // Удаляем соответствующую транзакцию (ищем по описанию и дате)
                $desc_search = "Оплата долга: %";
                $stmt = $pdo->prepare("SELECT id FROM transactions WHERE user_id = ? AND account_id = ? AND amount = ? AND transaction_date = ? AND description LIKE ? LIMIT 1");
                $stmt->execute([$user_id, $payment['account_id'], $payment['amount'], $payment['payment_date'], $desc_search]);
                $trans = $stmt->fetch();
                if ($trans) {
                    $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ?");
                    $stmt->execute([$trans['id']]);
                    // Возвращаем баланс счета
                    $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$payment['amount'], $payment['account_id'], $user_id]);
                }
                $pdo->commit();
                header("Location: debts.php");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Ошибка удаления платежа";
            }
        } else {
            $error = "Платеж не найден";
        }
    }
}

// Построение запроса для списка долгов
$sql = "SELECT d.*, c.name as category_name, c.color as category_color 
        FROM debts d 
        LEFT JOIN categories c ON d.category_id = c.id 
        WHERE d.user_id = ?";
$params = [$user_id];

if ($filter_status !== 'all') {
    $sql .= " AND d.status = ?";
    $params[] = $filter_status;
}
if ($filter_category !== 'all') {
    $sql .= " AND d.category_id = ?";
    $params[] = $filter_category;
}
if (!empty($filter_tag)) {
    $sql .= " AND d.tags_text LIKE ?";
    $params[] = "%$filter_tag%";
}
if ($filter_period !== 'all') {
    // Период: показываем долги, созданные или имеющие срок в указанном диапазоне
    $sql .= " AND (d.created_at BETWEEN ? AND ? OR d.due_date BETWEEN ? AND ?)";
    $params[] = $filter_date_from;
    $params[] = $filter_date_to;
    $params[] = $filter_date_from;
    $params[] = $filter_date_to;
}
$sql .= " ORDER BY d.due_date ASC, d.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$debts = $stmt->fetchAll();

// Получаем все платежи для отображения (для каждого долга)
$payments = [];
foreach ($debts as $debt) {
    $stmt = $pdo->prepare("SELECT p.*, a.bank_name FROM debt_payments p LEFT JOIN accounts a ON p.account_id = a.id WHERE p.debt_id = ? ORDER BY p.payment_date DESC");
    $stmt->execute([$debt['id']]);
    $payments[$debt['id']] = $stmt->fetchAll();
}

// Справочники для фильтров
$stmt = $pdo->prepare("SELECT * FROM categories WHERE user_id = ? ORDER BY name");
$stmt->execute([$user_id]);
$categories = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM accounts WHERE user_id = ? AND is_active = 1 ORDER BY bank_name");
$stmt->execute([$user_id]);
$accounts = $stmt->fetchAll();

// Получаем все метки из долгов (для фильтрации)
$tags_list = [];
$stmt = $pdo->prepare("SELECT tags_text FROM debts WHERE user_id = ? AND tags_text IS NOT NULL AND tags_text != ''");
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
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#667eea">
    <title>Долги - Финансовый дневник</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="manifest" href="manifest.json">
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
            display: inline-block;
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
            padding: 12px;
        }

        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 8px;
        }

        .filter-btn {
            flex: 1;
            padding: 8px;
            border-radius: 30px;
            text-align: center;
            text-decoration: none;
            background: #f8f9fa;
            color: #6c757d;
            font-size: 13px;
        }

        .filter-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .debt-card {
            background: white;
            border-radius: 20px;
            margin: 0 16px 16px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .debt-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .debt-creditor {
            font-size: 18px;
            font-weight: bold;
        }

        .debt-amount {
            font-size: 20px;
            font-weight: bold;
        }

        .debt-remaining {
            font-size: 14px;
            color: #ffc107;
        }

        .debt-due {
            font-size: 12px;
            color: #6c757d;
            margin-top: 4px;
        }

        .debt-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 8px;
        }

        .tag-badge {
            background: #e9ecef;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
        }

        .payment-list {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e9ecef;
        }

        .payment-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            padding: 8px 0;
            border-bottom: 1px solid #f1f3f5;
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

        .fab:active {
            transform: scale(0.95);
        }

        .fab i {
            font-size: 24px;
            color: white;
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

        .btn-sm {
            padding: 4px 10px;
            border-radius: 20px;
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
    </style>
</head>

<body>
    <div class="mobile-header">
        <div class="d-flex justify-content-between align-items-center">
            <a href="dashboard.php" class="back-button">← Назад</a>
            <button class="back-button" id="filterBtn">📊 Фильтр</button>
        </div>
        <div class="page-title fs-3 fw-bold mt-2">Долги</div>
        <div class="small">Управление задолженностями</div>
    </div>

    <div class="stats-grid">
        <?php
        $total_active = 0;
        $total_remaining = 0;
        foreach ($debts as $d) {
            if ($d['status'] == 'active') {
                $total_active++;
                $total_remaining += $d['remaining_amount'];
            }
        }
        ?>
        <div class="stat-card">
            <div>📋</div>
            <div class="stat-value"><?php echo $total_active; ?></div>
            <div class="small">Активных долгов</div>
        </div>
        <div class="stat-card">
            <div>💰</div>
            <div class="stat-value"><?php echo number_format($total_remaining, 0, '.', ' '); ?> ₽</div>
            <div class="small">Остаток к оплате</div>
        </div>
    </div>

    <!-- Фильтры (отображаются компактно) -->
    <div class="filter-bar" id="filterBar">
        <div class="filter-row">
            <a href="?status=active&period=month"
                class="filter-btn <?php echo ($filter_status == 'active' && $filter_period == 'month') ? 'active' : ''; ?>">Активные
                (месяц)</a>
            <a href="?status=active&period=all"
                class="filter-btn <?php echo ($filter_status == 'active' && $filter_period == 'all') ? 'active' : ''; ?>">Активные
                (все)</a>
            <a href="?status=all&period=all"
                class="filter-btn <?php echo ($filter_status == 'all' && $filter_period == 'all') ? 'active' : ''; ?>">Все
                долги</a>
        </div>
        <div class="filter-row">
            <a href="?status=paid&period=all"
                class="filter-btn <?php echo ($filter_status == 'paid') ? 'active' : ''; ?>">Погашенные</a>
        </div>
    </div>

    <?php if (empty($debts)): ?>
        <div class="empty-state">
            <i class="bi bi-archive"></i>
            <h5>Нет долгов</h5>
            <p>Добавьте первый долг, нажав на кнопку +</p>
        </div>
    <?php else: ?>
        <?php foreach ($debts as $debt): ?>
            <div class="debt-card" data-id="<?php echo $debt['id']; ?>">
                <div class="debt-header">
                    <div>
                        <div class="debt-creditor"><?php echo htmlspecialchars($debt['creditor']); ?></div>
                        <?php if ($debt['due_date']): ?>
                            <div class="debt-due">Срок: <?php echo date('d.m.Y', strtotime($debt['due_date'])); ?></div>
                        <?php endif; ?>
                        <?php if ($debt['category_name']): ?>
                            <span class="badge"
                                style="background-color: <?php echo $debt['category_color']; ?>"><?php echo htmlspecialchars($debt['category_name']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="text-end">
                        <div class="debt-amount <?php echo $debt['status'] == 'paid' ? 'text-success' : ''; ?>">
                            <?php echo number_format($debt['amount'], 0, '.', ' '); ?> ₽
                        </div>
                        <?php if ($debt['status'] != 'paid'): ?>
                            <div class="debt-remaining">Осталось:
                                <?php echo number_format($debt['remaining_amount'], 0, '.', ' '); ?> ₽</div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($debt['description'])): ?>
                    <div class="small text-muted mb-2"><?php echo htmlspecialchars($debt['description']); ?></div>
                <?php endif; ?>

                <?php if (!empty($debt['tags_text'])): ?>
                    <div class="debt-tags">
                        <?php foreach (explode(';', $debt['tags_text']) as $tag): ?>
                            <?php $tag = trim($tag);
                            if ($tag): ?>
                                <span class="tag-badge">#<?php echo htmlspecialchars($tag); ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- История платежей -->
                <?php if (!empty($payments[$debt['id']])): ?>
                    <div class="payment-list">
                        <div class="small fw-semibold mb-1">Оплаты:</div>
                        <?php foreach ($payments[$debt['id']] as $p): ?>
                            <div class="payment-item">
                                <div>
                                    <span class="small"><?php echo date('d.m.Y', strtotime($p['payment_date'])); ?></span>
                                    <span class="small text-muted ms-2"><?php echo htmlspecialchars($p['bank_name']); ?></span>
                                    <?php if (!empty($p['comment'])): ?>
                                        <span class="small text-muted ms-2"><?php echo htmlspecialchars($p['comment']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-bold">-<?php echo number_format($p['amount'], 0, '.', ' '); ?> ₽</span>
                                    <form method="POST" style="display:inline;"
                                        onsubmit="return confirm('Удалить платеж? Это восстановит остаток долга.');">
                                        <input type="hidden" name="payment_id" value="<?php echo $p['id']; ?>">
                                        <button type="submit" name="delete_payment" class="btn btn-sm btn-outline-danger p-0 px-2"
                                            style="font-size: 12px;">✖</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Кнопки действий -->
                <div class="d-flex justify-content-end gap-2 mt-3 pt-2 border-top">
                    <?php if ($debt['status'] != 'paid'): ?>
                        <button class="btn btn-sm btn-outline-primary pay-debt" data-id="<?php echo $debt['id']; ?>"
                            data-creditor="<?php echo htmlspecialchars($debt['creditor']); ?>"
                            data-remaining="<?php echo $debt['remaining_amount']; ?>">
                            <i class="bi bi-cash"></i> Оплатить
                        </button>
                        <button class="btn btn-sm btn-outline-secondary edit-debt" data-id="<?php echo $debt['id']; ?>"
                            data-creditor="<?php echo htmlspecialchars($debt['creditor']); ?>"
                            data-amount="<?php echo $debt['amount']; ?>"
                            data-description="<?php echo htmlspecialchars($debt['description']); ?>"
                            data-category="<?php echo $debt['category_id']; ?>"
                            data-tags="<?php echo htmlspecialchars($debt['tags_text']); ?>"
                            data-due="<?php echo $debt['due_date']; ?>" data-created="<?php echo $debt['created_at']; ?>">
                            <i class="bi bi-pencil"></i> Редактировать
                        </button>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-outline-danger delete-debt" data-id="<?php echo $debt['id']; ?>"
                        data-name="<?php echo htmlspecialchars($debt['creditor']); ?>">
                        <i class="bi bi-trash"></i> Удалить
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="fab" id="addDebtBtn"><i class="bi bi-plus-lg"></i></div>

    <div class="mobile-nav">
        <div class="nav-scroll">
            <a href="dashboard.php" class="nav-item">
                <i class="bi bi-house-door"></i>
                <span>Главная</span>
            </a>
            <a href="finances.php" class="nav-item">
                <i class="bi bi-calculator"></i>
                <span>Финансы</span>
            </a>
            <a href="accounts.php" class="nav-item">
                <i class="bi bi-bank"></i>
                <span>Счета</span>
            </a>
            <a href="statistics.php" class="nav-item">
                <i class="bi bi-graph-up"></i>
                <span>Статистика</span>
            </a>
            <a href="transfers.php" class="nav-item">
                <i class="bi bi-arrow-left-right"></i>
                <span>Переводы</span>
            </a>
            <a href="debts.php" class="nav-item active">
                <i class="bi bi-credit-card-2-front"></i>
                <span>Долги</span>
            </a>
            <a href="profile.php" class="nav-item">
                <i class="bi bi-person"></i>
                <span>Профиль</span>
            </a>
        </div>
    </div>

    <!-- Modal добавления долга -->
    <div class="modal fade" id="addDebtModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Новый долг</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="text" name="creditor" class="form-control mb-2" placeholder="Кому должны *"
                            required>
                        <input type="number" name="amount" class="form-control mb-2" placeholder="Сумма *" step="0.01"
                            required>
                        <textarea name="description" class="form-control mb-2" rows="2"
                            placeholder="Описание"></textarea>
                        <select name="category_id" class="form-select mb-2">
                            <option value="">Без категории</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="tags_text" class="form-control mb-2" placeholder="Метки (через ;)">
                        <input type="date" name="due_date" class="form-control mb-2" placeholder="Срок оплаты">
                        <input type="date" name="created_at" class="form-control mb-2"
                            value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="modal-footer"><button type="submit" name="add_debt"
                            class="btn btn-primary w-100 rounded-pill">Добавить долг</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal редактирования долга -->
    <div class="modal fade" id="editDebtModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Редактировать долг</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="debt_id" id="editDebtId">
                    <div class="modal-body">
                        <input type="text" name="creditor" id="editCreditor" class="form-control mb-2"
                            placeholder="Кому должны *" required>
                        <input type="number" name="amount" id="editAmount" class="form-control mb-2"
                            placeholder="Сумма *" step="0.01" required>
                        <textarea name="description" id="editDescription" class="form-control mb-2" rows="2"
                            placeholder="Описание"></textarea>
                        <select name="category_id" id="editCategory" class="form-select mb-2">
                            <option value="">Без категории</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="tags_text" id="editTags" class="form-control mb-2"
                            placeholder="Метки (через ;)">
                        <input type="date" name="due_date" id="editDueDate" class="form-control mb-2"
                            placeholder="Срок оплаты">
                        <input type="date" name="created_at" id="editCreatedAt" class="form-control mb-2" required>
                        <div class="alert alert-warning small">Внимание: изменение суммы изменит остаток долга.</div>
                    </div>
                    <div class="modal-footer"><button type="submit" name="edit_debt"
                            class="btn btn-primary w-100 rounded-pill">Сохранить</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal оплаты долга -->
    <div class="modal fade" id="payDebtModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Оплата долга</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="debt_id" id="payDebtId">
                    <div class="modal-body">
                        <p>Долг: <strong id="payCreditor"></strong></p>
                        <p>Остаток: <strong id="payRemaining"></strong> ₽</p>
                        <input type="number" name="amount" class="form-control mb-2" placeholder="Сумма оплаты *"
                            step="0.01" required>
                        <input type="date" name="payment_date" class="form-control mb-2"
                            value="<?php echo date('Y-m-d'); ?>" required>
                        <select name="account_id" class="form-select mb-2" required>
                            <option value="">Счет списания</option>
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['bank_name']); ?>
                                    (<?php echo number_format($a['current_balance'], 0, '.', ' '); ?> ₽)</option>
                            <?php endforeach; ?>
                        </select>
                        <textarea name="comment" class="form-control mb-2" rows="2"
                            placeholder="Комментарий к оплате"></textarea>
                    </div>
                    <div class="modal-footer"><button type="submit" name="add_payment"
                            class="btn btn-success w-100 rounded-pill">Оплатить</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal удаления долга -->
    <div class="modal fade" id="deleteDebtModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Удалить долг?</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="debt_id" id="deleteDebtId">
                    <div class="modal-body">
                        <p>Удалить долг <strong id="deleteDebtName"></strong>?</p>
                        <div class="alert alert-warning">Если есть платежи, удаление невозможно.</div>
                    </div>
                    <div class="modal-footer"><button type="submit" name="delete_debt"
                            class="btn btn-danger w-100 rounded-pill">Удалить</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal фильтров (расширенный) -->
    <div class="modal fade" id="filterModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Фильтры</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="GET" id="filterForm">
                    <div class="modal-body">
                        <div class="mb-2">
                            <label class="form-label">Статус</label>
                            <select name="status" class="form-select">
                                <option value="active" <?php echo $filter_status == 'active' ? 'selected' : ''; ?>>
                                    Активные</option>
                                <option value="paid" <?php echo $filter_status == 'paid' ? 'selected' : ''; ?>>Погашенные
                                </option>
                                <option value="all" <?php echo $filter_status == 'all' ? 'selected' : ''; ?>>Все</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Период</label>
                            <select name="period" class="form-select">
                                <option value="month" <?php echo $filter_period == 'month' ? 'selected' : ''; ?>>Текущий
                                    месяц</option>
                                <option value="all" <?php echo $filter_period == 'all' ? 'selected' : ''; ?>>Все время
                                </option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Категория</label>
                            <select name="category" class="form-select">
                                <option value="all">Все категории</option>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo $filter_category == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Метка</label>
                            <select name="tag" class="form-select">
                                <option value="">Все метки</option>
                                <?php foreach ($tags_list as $tag): ?>
                                    <option value="<?php echo htmlspecialchars($tag); ?>" <?php echo $filter_tag == $tag ? 'selected' : ''; ?>><?php echo htmlspecialchars($tag); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($filter_period == 'month'): ?>
                            <div class="row g-2">
                                <div class="col-6">
                                    <input type="date" name="date_from" class="form-control"
                                        value="<?php echo $filter_date_from; ?>" placeholder="С даты">
                                </div>
                                <div class="col-6">
                                    <input type="date" name="date_to" class="form-control"
                                        value="<?php echo $filter_date_to; ?>" placeholder="По дату">
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <a href="debts.php" class="btn btn-secondary">Сбросить</a>
                        <button type="submit" class="btn btn-primary">Применить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const addModal = new bootstrap.Modal(document.getElementById('addDebtModal'));
            const editModal = new bootstrap.Modal(document.getElementById('editDebtModal'));
            const payModal = new bootstrap.Modal(document.getElementById('payDebtModal'));
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteDebtModal'));
            const filterModal = new bootstrap.Modal(document.getElementById('filterModal'));

            document.getElementById('addDebtBtn').onclick = () => addModal.show();
            document.getElementById('filterBtn').onclick = () => filterModal.show();

            // Редактирование
            document.querySelectorAll('.edit-debt').forEach(btn => {
                btn.onclick = function (e) {
                    e.stopPropagation();
                    document.getElementById('editDebtId').value = this.dataset.id;
                    document.getElementById('editCreditor').value = this.dataset.creditor;
                    document.getElementById('editAmount').value = this.dataset.amount;
                    document.getElementById('editDescription').value = this.dataset.description;
                    document.getElementById('editCategory').value = this.dataset.category;
                    document.getElementById('editTags').value = this.dataset.tags;
                    document.getElementById('editDueDate').value = this.dataset.due || '';
                    document.getElementById('editCreatedAt').value = this.dataset.created;
                    editModal.show();
                };
            });

            // Оплата
            document.querySelectorAll('.pay-debt').forEach(btn => {
                btn.onclick = function (e) {
                    e.stopPropagation();
                    document.getElementById('payDebtId').value = this.dataset.id;
                    document.getElementById('payCreditor').innerText = this.dataset.creditor;
                    document.getElementById('payRemaining').innerText = parseFloat(this.dataset.remaining).toLocaleString('ru-RU');
                    payModal.show();
                };
            });

            // Удаление
            document.querySelectorAll('.delete-debt').forEach(btn => {
                btn.onclick = function (e) {
                    e.stopPropagation();
                    document.getElementById('deleteDebtId').value = this.dataset.id;
                    document.getElementById('deleteDebtName').innerText = this.dataset.name;
                    deleteModal.show();
                };
            });
        });
    </script>
</body>

</html>