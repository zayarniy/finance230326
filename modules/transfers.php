<?php
//session_start();
require_once '../config/session.php';
requireAuth();
require_once '../config/database.php';


$user_id = $_SESSION['user_id'];
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';
$filter_date_from = $_GET['date_from'] ?? date('Y-m-01');
$filter_date_to = $_GET['date_to'] ?? date('Y-m-t');

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Выполнение перевода
    if (isset($_POST['make_transfer'])) {
        $from_account_id = $_POST['from_account_id'] ?? 0;
        $to_account_id = $_POST['to_account_id'] ?? 0;
        $amount = floatval($_POST['amount'] ?? 0);
        $transfer_date = $_POST['transfer_date'] ?? date('Y-m-d H:i:s');
        $description = trim($_POST['description'] ?? '');
        $template_id = $_POST['template_id'] ?? null;
        
        if ($from_account_id > 0 && $to_account_id > 0 && $amount > 0 && $from_account_id != $to_account_id) {
            try {
                $pdo->beginTransaction();
                
                // Проверяем достаточно ли средств
                $stmt = $pdo->prepare("SELECT current_balance FROM accounts WHERE id = ? AND user_id = ? FOR UPDATE");
                $stmt->execute([$from_account_id, $user_id]);
                $from_account = $stmt->fetch();
                
                if ($from_account && $from_account['current_balance'] >= $amount) {
                    // Списываем со счета отправителя
                    $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$amount, $from_account_id, $user_id]);
                    
                    // Зачисляем на счет получателя
                    $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$amount, $to_account_id, $user_id]);
                    
                    // Получаем категорию "Перевод"
                    $stmt = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = 'Перевод' LIMIT 1");
                    $stmt->execute([$user_id]);
                    $category = $stmt->fetch();
                    
                    if (!$category) {
                        $stmt = $pdo->prepare("INSERT INTO categories (user_id, name, color) VALUES (?, 'Перевод', '#6c757d')");
                        $stmt->execute([$user_id]);
                        $category_id = $pdo->lastInsertId();
                    } else {
                        $category_id = $category['id'];
                    }
                    
                    // Создаем запись о переводе
                    $stmt = $pdo->prepare("INSERT INTO transfers (user_id, from_account_id, to_account_id, amount, transfer_date, description, template_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$user_id, $from_account_id, $to_account_id, $amount, $transfer_date, $description, $template_id]);
                    $transfer_id = $pdo->lastInsertId();
                    
                    // Создаем транзакцию типа 'transfer'
                    $transfer_desc = "Перевод: " . $description . " (счета #" . $from_account_id . " → #" . $to_account_id . ")";
                    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_id, category_id, type, amount, transaction_date, description) VALUES (?, ?, ?, 'transfer', ?, ?, ?)");
                    $stmt->execute([$user_id, $from_account_id, $category_id, $amount, $transfer_date, $transfer_desc]);
                    
                    $pdo->commit();
                    $message = "Перевод успешно выполнен";
                    header("Location: transfers.php");
                    exit;
                } else {
                    $error = "Недостаточно средств";
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Ошибка при переводе";
            }
        } else {
            $error = "Заполните все поля";
        }
    }
    
    // Редактирование перевода
    elseif (isset($_POST['edit_transfer'])) {
        $transfer_id = $_POST['transfer_id'] ?? 0;
        
        if ($transfer_id > 0) {
            // Получаем старые данные перевода
            $stmt = $pdo->prepare("SELECT * FROM transfers WHERE id = ? AND user_id = ?");
            $stmt->execute([$transfer_id, $user_id]);
            $old_transfer = $stmt->fetch();
            
            if ($old_transfer) {
                $new_amount = floatval($_POST['amount'] ?? $old_transfer['amount']);
                $new_transfer_date = $_POST['transfer_date'] ?? $old_transfer['transfer_date'];
                $new_description = trim($_POST['description'] ?? $old_transfer['description']);
                
                $from_account_id = $old_transfer['from_account_id'];
                $to_account_id = $old_transfer['to_account_id'];
                
                try {
                    $pdo->beginTransaction();
                    
                    // Возвращаем старые суммы на счета
                    $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$old_transfer['amount'], $from_account_id, $user_id]);
                    
                    $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$old_transfer['amount'], $to_account_id, $user_id]);
                    
                    // Проверяем достаточно ли средств для новой суммы
                    $stmt = $pdo->prepare("SELECT current_balance FROM accounts WHERE id = ? AND user_id = ?");
                    $stmt->execute([$from_account_id, $user_id]);
                    $current_balance = $stmt->fetchColumn();
                    
                    if ($current_balance >= $new_amount) {
                        // Применяем новые суммы
                        $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ? AND user_id = ?");
                        $stmt->execute([$new_amount, $from_account_id, $user_id]);
                        
                        $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ? AND user_id = ?");
                        $stmt->execute([$new_amount, $to_account_id, $user_id]);
                        
                        // Обновляем запись о переводе
                        $stmt = $pdo->prepare("UPDATE transfers SET amount = ?, transfer_date = ?, description = ? WHERE id = ? AND user_id = ?");
                        $stmt->execute([$new_amount, $new_transfer_date, $new_description, $transfer_id, $user_id]);
                        
                        // Обновляем транзакцию
                        $stmt = $pdo->prepare("SELECT id FROM transactions WHERE user_id = ? AND description LIKE ? AND amount = ? AND transaction_date = ? LIMIT 1");
                        $stmt->execute([$user_id, "%Перевод: %" . $old_transfer['description'] . "%", $old_transfer['amount'], $old_transfer['transfer_date']]);
                        $transaction = $stmt->fetch();
                        
                        if ($transaction) {
                            $new_transfer_desc = "Перевод: " . $new_description . " (счета #" . $from_account_id . " → #" . $to_account_id . ")";
                            $stmt = $pdo->prepare("UPDATE transactions SET amount = ?, transaction_date = ?, description = ? WHERE id = ?");
                            $stmt->execute([$new_amount, $new_transfer_date, $new_transfer_desc, $transaction['id']]);
                        }
                        
                        $pdo->commit();
                        $message = "Перевод успешно обновлен";
                        header("Location: transfers.php");
                        exit;
                    } else {
                        $pdo->rollBack();
                        $error = "Недостаточно средств для обновления суммы перевода";
                    }
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $error = "Ошибка при редактировании перевода";
                }
            }
        }
    }
    
    // Удаление перевода
    elseif (isset($_POST['delete_transfer'])) {
        $transfer_id = $_POST['transfer_id'] ?? 0;
        
        if ($transfer_id > 0) {
            try {
                $pdo->beginTransaction();
                
                // Получаем данные перевода
                $stmt = $pdo->prepare("SELECT * FROM transfers WHERE id = ? AND user_id = ?");
                $stmt->execute([$transfer_id, $user_id]);
                $transfer = $stmt->fetch();
                
                if ($transfer) {
                    // Возвращаем средства на счета
                    $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$transfer['amount'], $transfer['from_account_id'], $user_id]);
                    
                    $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$transfer['amount'], $transfer['to_account_id'], $user_id]);
                    
                    // Удаляем транзакцию
                    $stmt = $pdo->prepare("DELETE FROM transactions WHERE user_id = ? AND description LIKE ? AND amount = ? AND transaction_date = ?");
                    $stmt->execute([$user_id, "%Перевод: %" . $transfer['description'] . "%", $transfer['amount'], $transfer['transfer_date']]);
                    
                    // Удаляем запись о переводе
                    $stmt = $pdo->prepare("DELETE FROM transfers WHERE id = ? AND user_id = ?");
                    $stmt->execute([$transfer_id, $user_id]);
                    
                    $pdo->commit();
                    $message = "Перевод успешно удален";
                    header("Location: transfers.php");
                    exit;
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Ошибка при удалении перевода";
            }
        }
    }
    
    // Создание шаблона
    elseif (isset($_POST['save_template'])) {
        $from_account_id = $_POST['template_from_account'] ?? 0;
        $to_account_id = $_POST['template_to_account'] ?? 0;
        $template_name = trim($_POST['template_name'] ?? '');
        $amount = floatval($_POST['template_amount'] ?? 0);
        $description = trim($_POST['template_description'] ?? '');
        
        if ($from_account_id > 0 && $to_account_id > 0 && !empty($template_name) && $amount > 0) {
            $stmt = $pdo->prepare("INSERT INTO transfer_templates (user_id, from_account_id, to_account_id, template_name, amount, description) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $from_account_id, $to_account_id, $template_name, $amount, $description]);
            header("Location: transfers.php");
            exit;
        } else {
            $error = "Заполните все поля";
        }
    }
    
    // Редактирование шаблона
    elseif (isset($_POST['edit_template'])) {
        $template_id = $_POST['template_id'] ?? 0;
        $from_account_id = $_POST['template_from_account'] ?? 0;
        $to_account_id = $_POST['template_to_account'] ?? 0;
        $template_name = trim($_POST['template_name'] ?? '');
        $amount = floatval($_POST['template_amount'] ?? 0);
        $description = trim($_POST['template_description'] ?? '');
        
        if ($template_id > 0) {
            $stmt = $pdo->prepare("UPDATE transfer_templates SET from_account_id = ?, to_account_id = ?, template_name = ?, amount = ?, description = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$from_account_id, $to_account_id, $template_name, $amount, $description, $template_id, $user_id]);
            header("Location: transfers.php");
            exit;
        }
    }
    
    // Удаление шаблона
    elseif (isset($_POST['delete_template'])) {
        $template_id = $_POST['template_id'] ?? 0;
        if ($template_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM transfer_templates WHERE id = ? AND user_id = ?");
            $stmt->execute([$template_id, $user_id]);
            header("Location: transfers.php");
            exit;
        }
    }
}

// Получение списка счетов
$stmt = $pdo->prepare("SELECT * FROM accounts WHERE user_id = ? AND is_active = 1 ORDER BY bank_name");
$stmt->execute([$user_id]);
$accounts = $stmt->fetchAll();

// Получение шаблонов
$stmt = $pdo->prepare("SELECT t.*, a1.bank_name as from_bank, a2.bank_name as to_bank FROM transfer_templates t LEFT JOIN accounts a1 ON t.from_account_id = a1.id LEFT JOIN accounts a2 ON t.to_account_id = a2.id WHERE t.user_id = ? ORDER BY t.template_name");
$stmt->execute([$user_id]);
$templates = $stmt->fetchAll();

// Получение истории переводов
$sql = "SELECT t.*, a1.bank_name as from_bank, a2.bank_name as to_bank, tmp.template_name FROM transfers t LEFT JOIN accounts a1 ON t.from_account_id = a1.id LEFT JOIN accounts a2 ON t.to_account_id = a2.id LEFT JOIN transfer_templates tmp ON t.template_id = tmp.id WHERE t.user_id = ? AND DATE(t.transfer_date) BETWEEN ? AND ? ORDER BY t.transfer_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id, $filter_date_from, $filter_date_to]);
$transfers = $stmt->fetchAll();

$total_transferred = array_sum(array_column($transfers, 'amount'));
$transfer_count = count($transfers);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#667eea">
    <title>Переводы - Финансовый дневник</title>
    <link rel="icon" type="image/png" href="../favicon.png">
    <link rel="manifest" href="../manifest.json">
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
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .stat-value { font-size: 24px; font-weight: bold; margin: 8px 0; }
        
        .section-card {
            background: white;
            border-radius: 20px;
            margin: 0 16px 16px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .section-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .transfer-item {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 14px;
            margin-bottom: 10px;
            transition: all 0.2s;
        }
        
        .transfer-actions {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #e9ecef;
        }
        
        .transfer-actions button {
            flex: 1;
            padding: 8px;
            border-radius: 20px;
            border: none;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .transfer-actions button:active { transform: scale(0.95); }
        .btn-edit-transfer { background: #667eea20; color: #667eea; }
        .btn-delete-transfer { background: #dc354520; color: #dc3545; }
        
        .transfer-amount { font-size: 18px; font-weight: bold; color: #ffc107; }
        
        .template-card {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 14px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s;
            border-left: 4px solid #667eea;
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
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .btn-sm { padding: 6px 12px; border-radius: 20px; }
    </style>
</head>
<body>
    <div class="mobile-header">
        <div class="d-flex justify-content-between align-items-center">
            <a href="../dashboard.php" class="back-button">← Назад</a>
            <button class="back-button" id="filterBtn">📅 Фильтр</button>
        </div>
        <div class="page-title fs-3 fw-bold mt-2">Переводы</div>
        <div class="small">Переводы между счетами</div>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div>🔄</div>
            <div class="stat-value"><?php echo $transfer_count; ?></div>
            <div class="small text-muted">Всего переводов</div>
        </div>
        <div class="stat-card">
            <div>💰</div>
            <div class="stat-value"><?php echo number_format($total_transferred, 0, '.', ' '); ?> ₽</div>
            <div class="small text-muted">Сумма переводов</div>
        </div>
    </div>
    
    <!-- Шаблоны переводов -->
    <div class="section-card">
        <div class="section-title">
            <i class="bi bi-star"></i> Быстрые шаблоны
            <button class="btn btn-sm btn-outline-primary ms-auto rounded-pill" id="addTemplateBtn">+ Добавить</button>
        </div>
        <?php if (count($templates) > 0): ?>
            <?php foreach ($templates as $t): ?>
                <div class="template-card" data-template='<?php echo json_encode($t); ?>'>
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($t['template_name']); ?></div>
                            <div class="small text-muted">
                                <?php echo htmlspecialchars($t['from_bank']); ?> → <?php echo htmlspecialchars($t['to_bank']); ?>
                            </div>
                            <div class="small fw-bold mt-1"><?php echo number_format($t['amount'], 0, '.', ' '); ?> ₽</div>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-primary edit-template" data-id="<?php echo $t['id']; ?>">✏️</button>
                            <button class="btn btn-sm btn-outline-danger delete-template" data-id="<?php echo $t['id']; ?>">🗑️</button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state py-3">
                <div>📋</div>
                <div class="small">Нет сохраненных шаблонов</div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- История переводов -->
    <div class="section-card">
        <div class="section-title">
            <i class="bi bi-clock-history"></i> История переводов
            <span class="ms-auto small text-muted"><?php echo date('d.m.Y', strtotime($filter_date_from)); ?> - <?php echo date('d.m.Y', strtotime($filter_date_to)); ?></span>
        </div>
        <?php if (count($transfers) > 0): ?>
            <?php foreach ($transfers as $t): ?>
                <div class="transfer-item" data-transfer='<?php echo json_encode($t); ?>'>
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="small text-muted"><?php echo date('d.m.Y H:i', strtotime($t['transfer_date'])); ?></div>
                            <div class="fw-semibold">
                                <?php echo htmlspecialchars($t['from_bank']); ?> → <?php echo htmlspecialchars($t['to_bank']); ?>
                            </div>
                            <?php if (!empty($t['description'])): ?>
                                <div class="small text-muted mt-1">📝 <?php echo htmlspecialchars(substr($t['description'], 0, 30)); ?></div>
                            <?php endif; ?>
                            <?php if ($t['template_name']): ?>
                                <span class="badge bg-info mt-1">⚡ <?php echo htmlspecialchars($t['template_name']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="transfer-amount"><?php echo number_format($t['amount'], 0, '.', ' '); ?> ₽</div>
                    </div>
                    <div class="transfer-actions">
                        <button class="edit-transfer" data-id="<?php echo $t['id']; ?>" data-amount="<?php echo $t['amount']; ?>" data-date="<?php echo $t['transfer_date']; ?>" data-desc="<?php echo htmlspecialchars($t['description'] ?? ''); ?>">
                            ✏️ Редактировать
                        </button>
                        <button class="delete-transfer" data-id="<?php echo $t['id']; ?>">
                            🗑️ Удалить
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state py-3">
                <div>📭</div>
                <div class="small">Нет переводов за выбранный период</div>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="fab" id="addTransferBtn"><i class="bi bi-plus-lg"></i></div>
    
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
            <a href="accounts.php" class="nav-item">
                <i class="bi bi-bank"></i>
                <span>Счета</span>
            </a>
            <a href="statistics.php" class="nav-item">
                <i class="bi bi-graph-up"></i>
                <span>Статистика</span>
            </a>
            <a href="transfers.php" class="nav-item active">
                <i class="bi bi-arrow-left-right"></i>
                <span>Переводы</span>
            </a>
            <a href="debts.php" class="nav-item">
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
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h5>Фильтр по дате</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="GET">
                <div class="modal-body">
                    <input type="date" name="date_from" class="form-control mb-2" value="<?php echo $filter_date_from; ?>">
                    <input type="date" name="date_to" class="form-control mb-2" value="<?php echo $filter_date_to; ?>">
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-primary w-100 rounded-pill">Применить</button></div>
            </form>
        </div></div>
    </div>
    
    <!-- Transfer Modal (Add) -->
    <div class="modal fade" id="transferModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h5>Новый перевод</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <div class="modal-body">
                    <select name="from_account_id" class="form-select mb-2" required>
                        <option value="">Счет списания</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?php echo $a['id']; ?>" data-balance="<?php echo $a['current_balance']; ?>">
                                <?php echo htmlspecialchars($a['bank_name']); ?> (<?php echo number_format($a['current_balance'], 0, '.', ' '); ?> ₽)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="to_account_id" class="form-select mb-2" required>
                        <option value="">Счет зачисления</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['bank_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="amount" class="form-control mb-2" placeholder="Сумма" step="0.01" required>
                    <input type="datetime-local" name="transfer_date" class="form-control mb-2" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                    <textarea name="description" class="form-control mb-2" rows="2" placeholder="Описание"></textarea>
                    <div class="text-danger small" id="balanceWarning" style="display: none;"></div>
                </div>
                <div class="modal-footer"><button type="submit" name="make_transfer" class="btn btn-primary w-100 rounded-pill">Выполнить перевод</button></div>
            </form>
        </div></div>
    </div>
    
    <!-- Edit Transfer Modal -->
    <div class="modal fade" id="editTransferModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h5>Редактировать перевод</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="transfer_id" id="edit_transfer_id">
                    <input type="number" name="amount" id="edit_amount" class="form-control mb-2" placeholder="Сумма" step="0.01" required>
                    <input type="datetime-local" name="transfer_date" id="edit_transfer_date" class="form-control mb-2" required>
                    <textarea name="description" id="edit_description" class="form-control mb-2" rows="2" placeholder="Описание"></textarea>
                    <div class="alert alert-warning small">
                        <i class="bi bi-exclamation-triangle"></i> Изменение суммы может повлиять на баланс счетов
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" name="edit_transfer" class="btn btn-primary">Сохранить изменения</button>
                </div>
            </form>
        </div></div>
    </div>
    
    <!-- Delete Transfer Modal -->
    <div class="modal fade" id="deleteTransferModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h5>Удалить перевод?</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="transfer_id" id="delete_transfer_id">
                    <p>Вы уверены, что хотите удалить этот перевод?</p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> Это действие восстановит баланс на счетах
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" name="delete_transfer" class="btn btn-danger">Удалить</button>
                </div>
            </form>
        </div></div>
    </div>
    
    <!-- Template Modal -->
    <div class="modal fade" id="templateModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h5 id="templateModalTitle">Сохранить шаблон</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="template_id" id="templateIdField">
                    <input type="text" name="template_name" class="form-control mb-2" placeholder="Название шаблона" required>
                    <select name="template_from_account" class="form-select mb-2" required>
                        <option value="">Счет списания</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['bank_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="template_to_account" class="form-select mb-2" required>
                        <option value="">Счет зачисления</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['bank_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="template_amount" class="form-control mb-2" placeholder="Сумма" step="0.01" required>
                    <textarea name="template_description" class="form-control mb-2" rows="2" placeholder="Описание"></textarea>
                </div>
                <div class="modal-footer"><button type="submit" name="save_template" class="btn btn-primary w-100 rounded-pill" id="templateSubmitBtn">Сохранить</button></div>
            </form>
        </div></div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const filterModal = new bootstrap.Modal(document.getElementById('filterModal'));
            const transferModal = new bootstrap.Modal(document.getElementById('transferModal'));
            const editTransferModal = new bootstrap.Modal(document.getElementById('editTransferModal'));
            const deleteTransferModal = new bootstrap.Modal(document.getElementById('deleteTransferModal'));
            const templateModal = new bootstrap.Modal(document.getElementById('templateModal'));
            
            document.getElementById('filterBtn').onclick = () => filterModal.show();
            document.getElementById('addTransferBtn').onclick = () => { resetTransferForm(); transferModal.show(); };
            document.getElementById('addTemplateBtn').onclick = () => { resetTemplateForm(); templateModal.show(); };
            
            // Редактирование перевода
            document.querySelectorAll('.edit-transfer').forEach(btn => {
                btn.onclick = function(e) {
                    e.stopPropagation();
                    const id = this.dataset.id;
                    const amount = this.dataset.amount;
                    const date = this.dataset.date;
                    const desc = this.dataset.desc;
                    
                    document.getElementById('edit_transfer_id').value = id;
                    document.getElementById('edit_amount').value = amount;
                    document.getElementById('edit_transfer_date').value = date.replace(' ', 'T');
                    document.getElementById('edit_description').value = desc;
                    editTransferModal.show();
                };
            });
            
            // Удаление перевода
            document.querySelectorAll('.delete-transfer').forEach(btn => {
                btn.onclick = function(e) {
                    e.stopPropagation();
                    document.getElementById('delete_transfer_id').value = this.dataset.id;
                    deleteTransferModal.show();
                };
            });
            
            // Проверка баланса
            function checkBalance() {
                const fromSelect = document.querySelector('#transferModal select[name="from_account_id"]');
                const amountInput = document.querySelector('#transferModal input[name="amount"]');
                const warning = document.getElementById('balanceWarning');
                
                if (fromSelect && amountInput && warning) {
                    const selected = fromSelect.options[fromSelect.selectedIndex];
                    const balance = selected.getAttribute('data-balance');
                    const amount = parseFloat(amountInput.value);
                    
                    if (balance && amount && !isNaN(amount) && amount > parseFloat(balance)) {
                        warning.style.display = 'block';
                        warning.textContent = `Недостаточно средств! Доступно: ${parseFloat(balance).toLocaleString('ru-RU', {minimumFractionDigits: 0})} ₽`;
                        return false;
                    } else {
                        warning.style.display = 'none';
                        return true;
                    }
                }
                return true;
            }
            
            function resetTransferForm() {
                document.querySelector('#transferModal form').reset();
                document.getElementById('balanceWarning').style.display = 'none';
            }
            
            function resetTemplateForm() {
                document.querySelector('#templateModal form').reset();
                document.getElementById('templateIdField').value = '';
                document.getElementById('templateModalTitle').innerText = 'Сохранить шаблон';
                document.getElementById('templateSubmitBtn').name = 'save_template';
                document.getElementById('templateSubmitBtn').innerHTML = 'Сохранить';
            }
            
            // Использование шаблона
            document.querySelectorAll('.template-card').forEach(card => {
                card.onclick = function(e) {
                    if (e.target.closest('.edit-template') || e.target.closest('.delete-template')) return;
                    const template = JSON.parse(this.dataset.template);
                    document.querySelector('#transferModal select[name="from_account_id"]').value = template.from_account_id;
                    document.querySelector('#transferModal select[name="to_account_id"]').value = template.to_account_id;
                    document.querySelector('#transferModal input[name="amount"]').value = template.amount;
                    document.querySelector('#transferModal textarea[name="description"]').value = template.description || '';
                    checkBalance();
                    transferModal.show();
                };
            });
            
            document.querySelector('#transferModal select[name="from_account_id"]').addEventListener('change', checkBalance);
            document.querySelector('#transferModal input[name="amount"]').addEventListener('input', checkBalance);
            
            // Запрет перевода на тот же счет
            const fromSelect = document.querySelector('#transferModal select[name="from_account_id"]');
            const toSelect = document.querySelector('#transferModal select[name="to_account_id"]');
            
            function preventSameAccount() {
                if (fromSelect && toSelect && fromSelect.value && toSelect.value && fromSelect.value === toSelect.value) {
                    alert('Нельзя перевести на тот же счет!');
                    toSelect.value = '';
                }
            }
            
            if (fromSelect) fromSelect.addEventListener('change', preventSameAccount);
            if (toSelect) toSelect.addEventListener('change', preventSameAccount);
        });
    </script>
</body>
</html>













