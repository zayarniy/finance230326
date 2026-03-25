<?php
//session_start();
require_once '../config/session.php';
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';
$filter_date_from = $_GET['date_from'] ?? date('Y-m-01');
$filter_date_to = $_GET['date_to'] ?? date('Y-m-t');

// Функция для логирования
function logTransfer($message, $data = []) {
    $logFile = __DIR__ . '/transfer_log.txt';
    $logEntry = date('Y-m-d H:i:s') . " - " . $message;
    if (!empty($data)) {
        $logEntry .= " - " . json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    $logEntry .= PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Обработка действий
// Обработка действий
// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Выполнение перевода
    if (isset($_POST['make_transfer'])) {
        logTransfer("Начало обработки перевода", $_POST);
        
        $from_account_id = intval($_POST['from_account_id'] ?? 0);
        $to_account_id = intval($_POST['to_account_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $transfer_date = $_POST['transfer_date'] ?? date('Y-m-d H:i:s');
        $description = trim($_POST['description'] ?? '');
        $template_id = !empty($_POST['template_id']) ? intval($_POST['template_id']) : null;
        
        logTransfer("Параметры перевода", [
            'from_account' => $from_account_id,
            'to_account' => $to_account_id,
            'amount' => $amount,
            'transfer_date' => $transfer_date,
            'description' => $description,
            'template_id' => $template_id
        ]);
        
        if ($from_account_id > 0 && $to_account_id > 0 && $amount > 0 && $from_account_id != $to_account_id) {
            try {
                $pdo->beginTransaction();
                logTransfer("Транзакция начата");
                
                // Проверяем достаточно ли средств
                $stmt = $pdo->prepare("SELECT current_balance FROM accounts WHERE id = ? AND user_id = ? FOR UPDATE");
                $stmt->execute([$from_account_id, $user_id]);
                $from_account = $stmt->fetch();
                logTransfer("Баланс счета отправителя", ['balance' => $from_account['current_balance'] ?? 'null']);
                
                if ($from_account && $from_account['current_balance'] >= $amount) {
                    // Списываем со счета отправителя
                    $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$amount, $from_account_id, $user_id]);
                    logTransfer("Списано со счета $from_account_id: $amount");
                    
                    // Зачисляем на счет получателя
                    $stmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$amount, $to_account_id, $user_id]);
                    logTransfer("Зачислено на счет $to_account_id: $amount");
                    
                    // Получаем или создаем категорию "Перевод" (как расход)
                    $stmt = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = 'Перевод' AND type = 'expense' LIMIT 1");
                    $stmt->execute([$user_id]);
                    $category = $stmt->fetch();
                    
                    if (!$category) {
                        // Проверяем, существует ли категория с таким названием любого типа
                        $stmt = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = 'Перевод'");
                        $stmt->execute([$user_id]);
                        $existing = $stmt->fetch();
                        
                        if ($existing) {
                            $category_id = $existing['id'];
                            logTransfer("Используем существующую категорию 'Перевод' с ID: $category_id");
                        } else {
                            // Создаем новую категорию с типом 'expense'
                            $stmt = $pdo->prepare("INSERT INTO categories (user_id, name, color, type) VALUES (?, 'Перевод', '#6c757d', 'expense')");
                            $stmt->execute([$user_id]);
                            $category_id = $pdo->lastInsertId();
                            logTransfer("Создана новая категория 'Перевод' (expense) с ID: $category_id");
                        }
                    } else {
                        $category_id = $category['id'];
                        logTransfer("Найдена категория 'Перевод' (expense) с ID: $category_id");
                    }
                    
                    // Проверяем, существует ли шаблон, если указан
                    $valid_template_id = null;
                    if ($template_id) {
                        $stmt = $pdo->prepare("SELECT id FROM transfer_templates WHERE id = ? AND user_id = ?");
                        $stmt->execute([$template_id, $user_id]);
                        if ($stmt->fetch()) {
                            $valid_template_id = $template_id;
                            logTransfer("Шаблон ID $template_id найден");
                        } else {
                            logTransfer("Шаблон ID $template_id не найден, сохраняем как NULL");
                        }
                    }
                    
                    // Создаем запись о переводе
                    $stmt = $pdo->prepare("INSERT INTO transfers (user_id, from_account_id, to_account_id, amount, transfer_date, description, template_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$user_id, $from_account_id, $to_account_id, $amount, $transfer_date, $description, $valid_template_id]);
                    $transfer_id = $pdo->lastInsertId();
                    logTransfer("Создана запись перевода ID: $transfer_id");
                    
                    // Создаем транзакцию расхода со счета отправителя
                    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_id, category_id, type, amount, transaction_date, description) VALUES (?, ?, ?, 'expense', ?, ?, ?)");
                    $stmt->execute([$user_id, $from_account_id, $category_id, $amount, $transfer_date, "Перевод: " . $description]);
                    logTransfer("Создана транзакция расхода для счета $from_account_id");
                    
                    // Создаем транзакцию дохода на счет получателя
                    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_id, category_id, type, amount, transaction_date, description) VALUES (?, ?, ?, 'income', ?, ?, ?)");
                    $stmt->execute([$user_id, $to_account_id, $category_id, $amount, $transfer_date, "Перевод: " . $description]);
                    logTransfer("Создана транзакция дохода для счета $to_account_id");
                    
                    $pdo->commit();
                    logTransfer("Транзакция успешно завершена");
                    header("Location: transfers.php?success=1");
                    exit;
                } else {
                    $pdo->rollBack();
                    $error = "Недостаточно средств на счете отправителя";
                    logTransfer("ОШИБКА: Недостаточно средств", [
                        'balance' => $from_account['current_balance'] ?? 'unknown',
                        'amount' => $amount
                    ]);
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Ошибка при выполнении перевода: " . $e->getMessage();
                logTransfer("ОШИБКА PDO: " . $e->getMessage(), [
                    'code' => $e->getCode(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        } else {
            $error = "Заполните все поля корректно";
            logTransfer("ОШИБКА валидации", [
                'from_account' => $from_account_id,
                'to_account' => $to_account_id,
                'amount' => $amount,
                'same_account' => $from_account_id == $to_account_id
            ]);
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
            $error = "Заполните все поля шаблона";
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

// Проверка успешного перевода
if (isset($_GET['success'])) {
    $message = "Перевод успешно выполнен";
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
$sql = "SELECT t.*, a1.bank_name as from_bank, a2.bank_name as to_bank, tmp.template_name 
        FROM transfers t 
        LEFT JOIN accounts a1 ON t.from_account_id = a1.id 
        LEFT JOIN accounts a2 ON t.to_account_id = a2.id 
        LEFT JOIN transfer_templates tmp ON t.template_id = tmp.id 
        WHERE t.user_id = ? AND DATE(t.transfer_date) BETWEEN ? AND ? 
        ORDER BY t.transfer_date DESC";
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
            cursor: pointer;
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
        
        .template-card {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 14px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s;
            border-left: 4px solid #667eea;
        }
        
        .template-card:active { transform: scale(0.98); }
        
        .transfer-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .transfer-item:last-child { border-bottom: none; }
        
        .transfer-amount { font-size: 18px; font-weight: bold; color: #ffc107; }
        
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
        
        .filter-bar {
            background: white;
            margin: 0 16px 16px;
            border-radius: 20px;
            padding: 12px;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .alert { border-radius: 16px; margin: 0 16px 16px; }
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
                            <button class="btn btn-sm btn-outline-primary edit-template" data-id="<?php echo $t['id']; ?>" data-name="<?php echo htmlspecialchars($t['template_name']); ?>" data-from="<?php echo $t['from_account_id']; ?>" data-to="<?php echo $t['to_account_id']; ?>" data-amount="<?php echo $t['amount']; ?>" data-desc="<?php echo htmlspecialchars($t['description'] ?? ''); ?>">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger delete-template" data-id="<?php echo $t['id']; ?>" data-name="<?php echo htmlspecialchars($t['template_name']); ?>">
                                <i class="bi bi-trash"></i>
                            </button>
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
                <div class="transfer-item">
                    <div>
                        <div class="small text-muted"><?php echo date('d.m.Y H:i', strtotime($t['transfer_date'])); ?></div>
                        <div class="fw-semibold">
                            <?php echo htmlspecialchars($t['from_bank']); ?> → <?php echo htmlspecialchars($t['to_bank']); ?>
                        </div>
                        <?php if (!empty($t['description'])): ?>
                            <div class="small text-muted">📝 <?php echo htmlspecialchars(substr($t['description'], 0, 30)); ?></div>
                        <?php endif; ?>
                        <?php if ($t['template_name']): ?>
                            <span class="badge bg-info mt-1">⚡ <?php echo htmlspecialchars($t['template_name']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="transfer-amount"><?php echo number_format($t['amount'], 0, '.', ' '); ?> ₽</div>
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
        <div class="row g-0">
            <div class="col-2"><a href="../dashboard.php" class="nav-item"><i
                        class="bi bi-house-door"></i><span>Главная</span></a></div>
            <div class="col-2"><a href="finances.php" class="nav-item"><i
                        class="bi bi-calculator"></i><span>Финансы</span></a></div>
            <div class="col-2"><a href="accounts.php" class="nav-item"><i class="bi bi-bank"></i><span>Счета</span></a>
            </div>
            <div class="col-2"><a href="statistics.php" class="nav-item"><i
                        class="bi bi-graph-up"></i><span>Статистика</span></a></div>
            <div class="col-2"> <a href="transfers.php" class="nav-item active"><i
                        class="bi bi-arrow-left-right"></i><span>Переводы</span></a></div>
            <div class="col-2"> <a href="../profile.php" class="nav-item"><i
                        class="bi bi-person"></i><span>Профиль</span></a></div>
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
    
    <!-- Transfer Modal -->
    <div class="modal fade" id="transferModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h5>Новый перевод</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST" id="transferForm">
                <div class="modal-body">
                    <input type="hidden" name="template_id" id="templateId">
                    <select name="from_account_id" class="form-select mb-2" id="fromAccount" required>
                        <option value="">Счет списания *</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?php echo $a['id']; ?>" data-balance="<?php echo $a['current_balance']; ?>">
                                <?php echo htmlspecialchars($a['bank_name']); ?> (<?php echo number_format($a['current_balance'], 0, '.', ' '); ?> ₽)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="to_account_id" class="form-select mb-2" id="toAccount" required>
                        <option value="">Счет зачисления *</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['bank_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="amount" class="form-control mb-2" id="transferAmount" placeholder="Сумма *" step="0.01" required>
                    <input type="datetime-local" name="transfer_date" class="form-control mb-2" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                    <textarea name="description" class="form-control mb-2" rows="2" placeholder="Описание"></textarea>
                    <div class="text-danger small" id="balanceWarning" style="display: none;"></div>
                </div>
                <div class="modal-footer"><button type="submit" name="make_transfer" class="btn btn-primary w-100 rounded-pill">Выполнить перевод</button></div>
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
                    <input type="text" name="template_name" class="form-control mb-2" placeholder="Название шаблона *" required>
                    <select name="template_from_account" class="form-select mb-2" required>
                        <option value="">Счет списания *</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['bank_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="template_to_account" class="form-select mb-2" required>
                        <option value="">Счет зачисления *</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['bank_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="template_amount" class="form-control mb-2" placeholder="Сумма *" step="0.01" required>
                    <textarea name="template_description" class="form-control mb-2" rows="2" placeholder="Описание"></textarea>
                </div>
                <div class="modal-footer"><button type="submit" name="save_template" class="btn btn-primary w-100 rounded-pill" id="templateSubmitBtn">Сохранить</button></div>
            </form>
        </div></div>
    </div>
    
    <!-- Delete Template Modal -->
    <div class="modal fade" id="deleteTemplateModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h5>Удалить шаблон?</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="template_id" id="deleteTemplateId">
                    <p>Удалить шаблон <strong id="deleteTemplateName"></strong>?</p>
                </div>
                <div class="modal-footer"><button type="submit" name="delete_template" class="btn btn-danger w-100 rounded-pill">Удалить</button></div>
            </form>
        </div></div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const filterModal = new bootstrap.Modal(document.getElementById('filterModal'));
            const transferModal = new bootstrap.Modal(document.getElementById('transferModal'));
            const templateModal = new bootstrap.Modal(document.getElementById('templateModal'));
            const deleteTemplateModal = new bootstrap.Modal(document.getElementById('deleteTemplateModal'));
            
            document.getElementById('filterBtn').onclick = () => filterModal.show();
            document.getElementById('addTransferBtn').onclick = () => { resetTransferForm(); transferModal.show(); };
            document.getElementById('addTemplateBtn').onclick = () => { resetTemplateForm(); templateModal.show(); };
            
            // Использование шаблона
            document.querySelectorAll('.template-card').forEach(card => {
                card.onclick = function(e) {
                    if (e.target.closest('.edit-template') || e.target.closest('.delete-template')) return;
                    const template = JSON.parse(this.dataset.template);
                    document.querySelector('#transferModal select[name="from_account_id"]').value = template.from_account_id;
                    document.querySelector('#transferModal select[name="to_account_id"]').value = template.to_account_id;
                    document.querySelector('#transferModal input[name="amount"]').value = template.amount;
                    document.querySelector('#transferModal textarea[name="description"]').value = template.description || '';
                    document.getElementById('templateId').value = template.id;
                    checkBalance();
                    transferModal.show();
                };
            });
            
            // Редактирование шаблона
            document.querySelectorAll('.edit-template').forEach(btn => {
                btn.onclick = function(e) {
                    e.stopPropagation();
                    document.getElementById('templateModalTitle').innerText = 'Редактировать шаблон';
                    document.getElementById('templateIdField').value = this.dataset.id;
                    document.querySelector('#templateModal input[name="template_name"]').value = this.dataset.name;
                    document.querySelector('#templateModal select[name="template_from_account"]').value = this.dataset.from;
                    document.querySelector('#templateModal select[name="template_to_account"]').value = this.dataset.to;
                    document.querySelector('#templateModal input[name="template_amount"]').value = this.dataset.amount;
                    document.querySelector('#templateModal textarea[name="template_description"]').value = this.dataset.desc;
                    document.getElementById('templateSubmitBtn').name = 'edit_template';
                    document.getElementById('templateSubmitBtn').innerHTML = 'Сохранить изменения';
                    templateModal.show();
                };
            });
            
            // Удаление шаблона
            document.querySelectorAll('.delete-template').forEach(btn => {
                btn.onclick = function(e) {
                    e.stopPropagation();
                    document.getElementById('deleteTemplateId').value = this.dataset.id;
                    document.getElementById('deleteTemplateName').innerText = this.dataset.name;
                    deleteTemplateModal.show();
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
                document.getElementById('templateId').value = '';
                document.getElementById('balanceWarning').style.display = 'none';
            }
            
            function resetTemplateForm() {
                document.querySelector('#templateModal form').reset();
                document.getElementById('templateIdField').value = '';
                document.getElementById('templateModalTitle').innerText = 'Сохранить шаблон';
                document.getElementById('templateSubmitBtn').name = 'save_template';
                document.getElementById('templateSubmitBtn').innerHTML = 'Сохранить';
            }
            
            // Обработчики для проверки баланса
            const fromSelect = document.querySelector('#transferModal select[name="from_account_id"]');
            const amountInput = document.querySelector('#transferModal input[name="amount"]');
            if (fromSelect) fromSelect.addEventListener('change', checkBalance);
            if (amountInput) amountInput.addEventListener('input', checkBalance);
            
            // Запрет перевода на тот же счет
            const fromAccount = document.getElementById('fromAccount');
            const toAccount = document.getElementById('toAccount');
            
            function preventSameAccount() {
                if (fromAccount && toAccount && fromAccount.value && toAccount.value && fromAccount.value === toAccount.value) {
                    alert('Нельзя перевести деньги на тот же счет!');
                    toAccount.value = '';
                }
            }
            
            if (fromAccount) fromAccount.addEventListener('change', preventSameAccount);
            if (toAccount) toAccount.addEventListener('change', preventSameAccount);
            
            // Отправка формы с проверкой
            const transferForm = document.getElementById('transferForm');
            if (transferForm) {
                transferForm.addEventListener('submit', function(e) {
                    if (!checkBalance()) {
                        e.preventDefault();
                        alert('Недостаточно средств на счете!');
                        return false;
                    }
                    
                    const fromVal = fromAccount ? fromAccount.value : '';
                    const toVal = toAccount ? toAccount.value : '';
                    const amount = document.querySelector('#transferModal input[name="amount"]')?.value;
                    
                    if (!fromVal || !toVal || !amount || amount <= 0) {
                        e.preventDefault();
                        alert('Пожалуйста, заполните все поля!');
                        return false;
                    }
                    
                    return true;
                });
            }
        });

        function resetTransferForm() {
    const form = document.querySelector('#transferModal form');
    if (form) form.reset();
    const templateIdField = document.getElementById('templateId');
    if (templateIdField) templateIdField.value = '';
    const warning = document.getElementById('balanceWarning');
    if (warning) warning.style.display = 'none';
}
    </script>
</body>
</html>