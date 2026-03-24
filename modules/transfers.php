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
                    $stmt = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = 'Перевод' AND type = 'transfer' LIMIT 1");
                    $stmt->execute([$user_id]);
                    $category = $stmt->fetch();
                    
                    if (!$category) {
                        $stmt = $pdo->prepare("INSERT INTO categories (user_id, name, color, type) VALUES (?, 'Перевод', '#6c757d', 'transfer')");
                        $stmt->execute([$user_id]);
                        $category_id = $pdo->lastInsertId();
                    } else {
                        $category_id = $category['id'];
                    }
                    
                    // Создаем запись о переводе
                    $stmt = $pdo->prepare("INSERT INTO transfers (user_id, from_account_id, to_account_id, amount, transfer_date, description, template_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$user_id, $from_account_id, $to_account_id, $amount, $transfer_date, $description, $template_id]);
                    
                    // Создаем две транзакции: расход и доход
                    // Расход со счета отправителя
                    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_id, category_id, type, amount, transaction_date, description) VALUES (?, ?, ?, 'expense', ?, ?, ?)");
                    $stmt->execute([$user_id, $from_account_id, $category_id, $amount, $transfer_date, "Перевод: " . $description]);
                    
                    // Доход на счет получателя
                    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_id, category_id, type, amount, transaction_date, description) VALUES (?, ?, ?, 'income', ?, ?, ?)");
                    $stmt->execute([$user_id, $to_account_id, $category_id, $amount, $transfer_date, "Перевод: " . $description]);
                    
                    $pdo->commit();
                    $message = "Перевод успешно выполнен";
                } else {
                    $error = "Недостаточно средств на счете отправителя";
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Ошибка при выполнении перевода: " . $e->getMessage();
            }
        } else {
            $error = "Заполните все поля корректно";
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
            try {
                $stmt = $pdo->prepare("INSERT INTO transfer_templates (user_id, from_account_id, to_account_id, template_name, amount, description) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $from_account_id, $to_account_id, $template_name, $amount, $description]);
                $message = "Шаблон успешно сохранен";
            } catch (PDOException $e) {
                $error = "Ошибка при сохранении шаблона: " . $e->getMessage();
            }
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
        
        if ($template_id > 0 && $from_account_id > 0 && $to_account_id > 0 && !empty($template_name) && $amount > 0) {
            $stmt = $pdo->prepare("UPDATE transfer_templates SET from_account_id = ?, to_account_id = ?, template_name = ?, amount = ?, description = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$from_account_id, $to_account_id, $template_name, $amount, $description, $template_id, $user_id]);
            $message = "Шаблон успешно обновлен";
        } else {
            $error = "Заполните все поля шаблона";
        }
    }
    
    // Удаление шаблона
    elseif (isset($_POST['delete_template'])) {
        $template_id = $_POST['template_id'] ?? 0;
        
        if ($template_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM transfer_templates WHERE id = ? AND user_id = ?");
            $stmt->execute([$template_id, $user_id]);
            $message = "Шаблон успешно удален";
        }
    }
}

// Получение списка счетов
$stmt = $pdo->prepare("SELECT * FROM accounts WHERE user_id = ? AND is_active = 1 ORDER BY bank_name");
$stmt->execute([$user_id]);
$accounts = $stmt->fetchAll();

// Получение шаблонов переводов
$stmt = $pdo->prepare("
    SELECT t.*, 
           a1.bank_name as from_bank, a1.account_number as from_account,
           a2.bank_name as to_bank, a2.account_number as to_account
    FROM transfer_templates t
    LEFT JOIN accounts a1 ON t.from_account_id = a1.id
    LEFT JOIN accounts a2 ON t.to_account_id = a2.id
    WHERE t.user_id = ?
    ORDER BY t.template_name
");
$stmt->execute([$user_id]);
$templates = $stmt->fetchAll();

// Получение истории переводов
$sql = "SELECT t.*, 
        a1.bank_name as from_bank, a1.account_number as from_account,
        a2.bank_name as to_bank, a2.account_number as to_account,
        tmp.template_name
        FROM transfers t
        LEFT JOIN accounts a1 ON t.from_account_id = a1.id
        LEFT JOIN accounts a2 ON t.to_account_id = a2.id
        LEFT JOIN transfer_templates tmp ON t.template_id = tmp.id
        WHERE t.user_id = ?";
$params = [$user_id];

if ($filter_date_from && $filter_date_to) {
    $sql .= " AND DATE(t.transfer_date) BETWEEN ? AND ?";
    $params[] = $filter_date_from;
    $params[] = $filter_date_to;
}

$sql .= " ORDER BY t.transfer_date DESC, t.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transfers = $stmt->fetchAll();

// Статистика переводов
$total_transferred = array_sum(array_column($transfers, 'amount'));
$transfer_count = count($transfers);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Переводы - Финансовый дневник</title>
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
        .transfer-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        .transfer-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .template-card {
            border-radius: 12px;
            border-left: 4px solid #667eea;
            transition: all 0.3s;
            cursor: pointer;
        }
        .template-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .amount-positive {
            color: #28a745;
            font-weight: bold;
        }
        .amount-negative {
            color: #dc3545;
            font-weight: bold;
        }
        .transfer-icon {
            font-size: 24px;
            color: #667eea;
        }
        .filter-btn {
            border-radius: 25px;
            padding: 8px 20px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 p-0">
                <div>
                    <div class="text-center py-4">
                        <i class="bi bi-wallet2" style="font-size: 48px; color: white;"></i>
                        <h5 class="text-white mt-2"><?php echo htmlspecialchars($_SESSION['username']); ?></h5>
                        <small class="text-white-50">Финансовый дневник</small>
                    </div>
                    
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-10 p-0">
                <div class="main-content">
                    <div class="p-4">
                        <!-- Header -->
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div>
                                <h2><i class="bi bi-arrow-left-right"></i> Переводы</h2>
                                <p class="text-muted">Переводы между счетами и шаблоны для быстрых операций</p>
                            </div>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#transferModal">
                                <i class="bi bi-plus-circle"></i> Новый перевод
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
                                <div class="card stat-card bg-primary text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="card-title">Всего переводов</h6>
                                                <h3 class="mb-0"><?php echo $transfer_count; ?></h3>
                                            </div>
                                            <i class="bi bi-arrow-left-right fs-1 opacity-50"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card stat-card bg-success text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="card-title">Общая сумма переводов</h6>
                                                <h3 class="mb-0"><?php echo number_format($total_transferred, 2, '.', ' '); ?> ₽</h3>
                                            </div>
                                            <i class="bi bi-calculator fs-1 opacity-50"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card stat-card bg-info text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="card-title">Средняя сумма</h6>
                                                <h3 class="mb-0"><?php echo $transfer_count > 0 ? number_format($total_transferred / $transfer_count, 2, '.', ' ') : 0; ?> ₽</h3>
                                            </div>
                                            <i class="bi bi-graph-up fs-1 opacity-50"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <!-- Quick Transfer Templates -->
                            <div class="col-md-4 mb-4">
                                <div class="card">
                                    <div class="card-header bg-white">
                                        <h5 class="mb-0"><i class="bi bi-star"></i> Шаблоны переводов</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if (count($templates) > 0): ?>
                                            <div class="mb-3">
                                                <?php foreach ($templates as $template): ?>
                                                    <div class="template-card card mb-2" onclick="useTemplate(<?php echo htmlspecialchars(json_encode($template)); ?>)">
                                                        <div class="card-body p-3">
                                                            <div class="d-flex justify-content-between align-items-center">
                                                                <div>
                                                                    <h6 class="mb-0"><?php echo htmlspecialchars($template['template_name']); ?></h6>
                                                                    <small class="text-muted">
                                                                        <?php echo htmlspecialchars($template['from_bank']); ?> → 
                                                                        <?php echo htmlspecialchars($template['to_bank']); ?>
                                                                    </small>
                                                                </div>
                                                                <div class="text-end">
                                                                    <div class="fw-bold"><?php echo number_format($template['amount'], 2, '.', ' '); ?> ₽</div>
                                                                    <div>
                                                                        <button class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation(); editTemplate(<?php echo htmlspecialchars(json_encode($template)); ?>)">
                                                                            <i class="bi bi-pencil"></i>
                                                                        </button>
                                                                        <button class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation(); deleteTemplate(<?php echo $template['id']; ?>, '<?php echo htmlspecialchars($template['template_name']); ?>')">
                                                                            <i class="bi bi-trash"></i>
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <?php if (!empty($template['description'])): ?>
                                                                <small class="text-muted d-block mt-1"><?php echo htmlspecialchars($template['description']); ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <button class="btn btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#templateModal">
                                                <i class="bi bi-plus-circle"></i> Создать шаблон
                                            </button>
                                        <?php else: ?>
                                            <p class="text-center text-muted my-3">Нет сохраненных шаблонов</p>
                                            <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#templateModal">
                                                <i class="bi bi-plus-circle"></i> Создать первый шаблон
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Transfer History -->
                            <div class="col-md-8 mb-4">
                                <div class="card">
                                    <div class="card-header bg-white">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h5 class="mb-0"><i class="bi bi-clock-history"></i> История переводов</h5>
                                            <form method="GET" action="" class="d-flex gap-2">
                                                <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo $filter_date_from; ?>" style="width: 130px;">
                                                <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo $filter_date_to; ?>" style="width: 130px;">
                                                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
                                                <a href="transfers.php" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-repeat"></i></a>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Дата</th>
                                                        <th>Откуда</th>
                                                        <th>Куда</th>
                                                        <th>Сумма</th>
                                                        <th>Описание</th>
                                                        <th>Шаблон</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (count($transfers) > 0): ?>
                                                        <?php foreach ($transfers as $transfer): ?>
                                                            <tr>
                                                                <td><?php echo date('d.m.Y H:i', strtotime($transfer['transfer_date'])); ?></td>
                                                                <td>
                                                                    <i class="bi bi-bank"></i>
                                                                    <?php echo htmlspecialchars($transfer['from_bank']); ?><br>
                                                                    <small class="text-muted"><?php echo htmlspecialchars($transfer['from_account']); ?></small>
                                                                </td>
                                                                <td>
                                                                    <i class="bi bi-bank"></i>
                                                                    <?php echo htmlspecialchars($transfer['to_bank']); ?><br>
                                                                    <small class="text-muted"><?php echo htmlspecialchars($transfer['to_account']); ?></small>
                                                                </td>
                                                                <td class="fw-bold"><?php echo number_format($transfer['amount'], 2, '.', ' '); ?> ₽</td>
                                                                <td><?php echo htmlspecialchars($transfer['description'] ?: '-'); ?></td>
                                                                <td>
                                                                    <?php if ($transfer['template_name']): ?>
                                                                        <span class="badge bg-info"><?php echo htmlspecialchars($transfer['template_name']); ?></span>
                                                                    <?php else: ?>
                                                                        <span class="text-muted">—</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <tr>
                                                            <td colspan="6" class="text-center py-4">
                                                                <i class="bi bi-inbox fs-1 text-muted"></i>
                                                                <p class="text-muted mt-2">Нет переводов за выбранный период</p>
                                                                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#transferModal">
                                                                    <i class="bi bi-plus-circle"></i> Сделать первый перевод
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
        </div>
    </div>
        <div class="mobile-nav">
        <div class="row g-0">
            <div class="col-2"><a href="../dashboard.php" class="nav-item"><i
                        class="bi bi-house-door"></i><span>Главная</span></a></div>
            <div class="col-2"><a href="finances.php" class="nav-item"><i
                        class="bi bi-calculator"></i><span>Финансы</span></a></div>
            <div class="col-2"><a href="accounts.php" class="nav-item"><i class="bi bi-bank"></i><span>Счета</span></a>
            </div>
            <div class="col-2"><a href="statistics.php" class="nav-item active"><i
                        class="bi bi-graph-up"></i><span>Статистика</span></a></div>
            <div class="col-2"> <a href="transfers.php" class="nav-item"><i
                        class="bi bi-arrow-left-right"></i><span>Переводы</span></a></div>
            <div class="col-2"> <a href="../profile.php" class="nav-item"><i
                        class="bi bi-person"></i><span>Профиль</span></a></div>
        </div>
    </div>
    <!-- Transfer Modal -->
    <div class="modal fade" id="transferModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-arrow-left-right"></i> Новый перевод</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" id="transferForm">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="from_account_id" class="form-label">Счет списания *</label>
                            <select class="form-select" id="from_account_id" name="from_account_id" required>
                                <option value="">Выберите счет</option>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?php echo $account['id']; ?>" data-balance="<?php echo $account['current_balance']; ?>">
                                        <?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_number'] . ' (' . number_format($account['current_balance'], 2, '.', ' ') . ' ₽)'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="to_account_id" class="form-label">Счет зачисления *</label>
                            <select class="form-select" id="to_account_id" name="to_account_id" required>
                                <option value="">Выберите счет</option>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?php echo $account['id']; ?>">
                                        <?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_number']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="amount" class="form-label">Сумма перевода *</label>
                            <input type="number" class="form-control" id="amount" name="amount" step="0.01" required>
                            <small class="text-muted" id="balance_warning" style="display: none; color: #dc3545;"></small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="transfer_date" class="form-label">Дата и время *</label>
                            <input type="datetime-local" class="form-control" id="transfer_date" name="transfer_date" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Описание</label>
                            <textarea class="form-control" id="description" name="description" rows="2" placeholder="Например: Пополнение счета, Перевод средств..."></textarea>
                        </div>
                        
                        <input type="hidden" name="template_id" id="template_id">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" name="make_transfer" class="btn btn-primary">Выполнить перевод</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Template Modal -->
    <div class="modal fade" id="templateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="templateModalTitle"><i class="bi bi-star"></i> Сохранить шаблон</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" id="templateForm">
                    <div class="modal-body">
                        <input type="hidden" name="template_id" id="template_id_field">
                        
                        <div class="mb-3">
                            <label for="template_name" class="form-label">Название шаблона *</label>
                            <input type="text" class="form-control" id="template_name" name="template_name" required>
                            <small class="text-muted">Например: "Пополнение сберкнижки", "Ежемесячный перевод"</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="template_from_account" class="form-label">Счет списания *</label>
                            <select class="form-select" id="template_from_account" name="template_from_account" required>
                                <option value="">Выберите счет</option>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?php echo $account['id']; ?>">
                                        <?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_number']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="template_to_account" class="form-label">Счет зачисления *</label>
                            <select class="form-select" id="template_to_account" name="template_to_account" required>
                                <option value="">Выберите счет</option>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?php echo $account['id']; ?>">
                                        <?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_number']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="template_amount" class="form-label">Сумма *</label>
                            <input type="number" class="form-control" id="template_amount" name="template_amount" step="0.01" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="template_description" class="form-label">Описание (необязательно)</label>
                            <textarea class="form-control" id="template_description" name="template_description" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" name="save_template" class="btn btn-primary" id="templateSubmitBtn">Сохранить шаблон</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Проверка баланса при выборе счета и вводе суммы
        const fromAccountSelect = document.getElementById('from_account_id');
        const amountInput = document.getElementById('amount');
        const balanceWarning = document.getElementById('balance_warning');
        
        function checkBalance() {
            if (fromAccountSelect && amountInput && balanceWarning) {
                const selectedOption = fromAccountSelect.options[fromAccountSelect.selectedIndex];
                const balance = selectedOption.getAttribute('data-balance');
                const amount = parseFloat(amountInput.value);
                
                if (balance && amount && amount > parseFloat(balance)) {
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
        
        if (fromAccountSelect) fromAccountSelect.addEventListener('change', checkBalance);
        if (amountInput) amountInput.addEventListener('input', checkBalance);
        
        // Использование шаблона
        function useTemplate(template) {
            const modal = new bootstrap.Modal(document.getElementById('transferModal'));
            
            document.getElementById('from_account_id').value = template.from_account_id;
            document.getElementById('to_account_id').value = template.to_account_id;
            document.getElementById('amount').value = template.amount;
            document.getElementById('description').value = template.description || '';
            document.getElementById('template_id').value = template.id;
            
            // Триггерим проверку баланса
            checkBalance();
            
            modal.show();
        }
        
        // Редактирование шаблона
        function editTemplate(template) {
            const modal = new bootstrap.Modal(document.getElementById('templateModal'));
            
            document.getElementById('templateModalTitle').innerHTML = '<i class="bi bi-pencil"></i> Редактировать шаблон';
            document.getElementById('template_id_field').value = template.id;
            document.getElementById('template_name').value = template.template_name;
            document.getElementById('template_from_account').value = template.from_account_id;
            document.getElementById('template_to_account').value = template.to_account_id;
            document.getElementById('template_amount').value = template.amount;
            document.getElementById('template_description').value = template.description || '';
            
            const submitBtn = document.getElementById('templateSubmitBtn');
            submitBtn.name = 'edit_template';
            submitBtn.innerHTML = '<i class="bi bi-save"></i> Сохранить изменения';
            
            modal.show();
        }
        
        // Удаление шаблона
        function deleteTemplate(templateId, templateName) {
            if (confirm(`Вы уверены, что хотите удалить шаблон "${templateName}"?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'template_id';
                input.value = templateId;
                
                const submit = document.createElement('input');
                submit.type = 'submit';
                submit.name = 'delete_template';
                
                form.appendChild(input);
                form.appendChild(submit);
                document.body.appendChild(form);
                submit.click();
            }
        }
        
        // Сброс формы шаблона при открытии
        const templateModal = document.getElementById('templateModal');
        if (templateModal) {
            templateModal.addEventListener('show.bs.modal', function() {
                if (!event.relatedTarget || !event.relatedTarget.getAttribute('data-edit')) {
                    document.getElementById('templateForm').reset();
                    document.getElementById('template_id_field').value = '';
                    document.getElementById('templateModalTitle').innerHTML = '<i class="bi bi-star"></i> Сохранить шаблон';
                    const submitBtn = document.getElementById('templateSubmitBtn');
                    submitBtn.name = 'save_template';
                    submitBtn.innerHTML = '<i class="bi bi-save"></i> Сохранить шаблон';
                }
            });
        }
        
        // Предотвращение перевода на тот же счет
        const toAccountSelect = document.getElementById('to_account_id');
        function preventSameAccount() {
            if (fromAccountSelect && toAccountSelect) {
                const fromId = fromAccountSelect.value;
                const toId = toAccountSelect.value;
                
                if (fromId && toId && fromId === toId) {
                    alert('Нельзя перевести деньги на тот же счет!');
                    toAccountSelect.value = '';
                }
            }
        }
        
        if (fromAccountSelect) fromAccountSelect.addEventListener('change', preventSameAccount);
        if (toAccountSelect) toAccountSelect.addEventListener('change', preventSameAccount);
    </script>
</body>
</html>