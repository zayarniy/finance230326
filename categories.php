<?php
//session_start();
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
$filter_type = $_GET['type'] ?? 'all'; // all, income, expense

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Добавление новой категории
    if (isset($_POST['add_category'])) {
        $name = trim($_POST['category_name'] ?? '');
        $color = $_POST['category_color'] ?? '#6c757d';
        $type = $_POST['category_type'] ?? 'expense';

        if (!empty($name)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO categories (user_id, name, color, type) VALUES (?, ?, ?, ?)");
                $stmt->execute([$user_id, $name, $color, $type]);
                header("Location: categories.php");
                exit;
            } catch (PDOException $e) {
                $error = "Категория с таким названием уже существует";
            }
        } else {
            $error = "Введите название категории";
        }
    }

    // Редактирование категории
    elseif (isset($_POST['edit_category'])) {
        $category_id = $_POST['category_id'] ?? 0;
        $name = trim($_POST['category_name'] ?? '');
        $color = $_POST['category_color'] ?? '#6c757d';
        $type = $_POST['category_type'] ?? 'expense';

        if (!empty($name) && $category_id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE categories SET name = ?, color = ?, type = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$name, $color, $type, $category_id, $user_id]);
                header("Location: categories.php");
                exit;
            } catch (PDOException $e) {
                $error = "Категория с таким названием уже существует";
            }
        } else {
            $error = "Введите название категории";
        }
    }

    // Удаление категории
    elseif (isset($_POST['delete_category'])) {
        $category_id = $_POST['category_id'] ?? 0;

        if ($category_id > 0) {
            // Проверяем, есть ли операции с этой категорией
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM transactions WHERE category_id = ? AND user_id = ?");
            $stmt->execute([$category_id, $user_id]);
            $count = $stmt->fetch()['count'];

            if ($count > 0) {
                $error = "Невозможно удалить. Категория используется в $count операциях";
                // Не делаем redirect, показываем ошибку
            } else {
                $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ? AND user_id = ?");
                $stmt->execute([$category_id, $user_id]);
                header("Location: categories.php");
                exit;
            }
        }
    }
}

// Получение списка категорий с фильтрацией
$sql = "SELECT * FROM categories WHERE user_id = ?";
$params = [$user_id];

if ($filter_type !== 'all') {
    $sql .= " AND type = ?";
    $params[] = $filter_type;
}

$sql .= " ORDER BY type DESC, name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$categories = $stmt->fetchAll();

// Получаем статистику использования категорий
$stmt = $pdo->prepare("
    SELECT category_id, COUNT(*) as count, SUM(amount) as total
    FROM transactions 
    WHERE user_id = ? 
    GROUP BY category_id
");
$stmt->execute([$user_id]);
$category_stats = [];
while ($row = $stmt->fetch()) {
    $category_stats[$row['category_id']] = $row;
}

// Подсчет статистики
$income_count = count(array_filter($categories, function ($cat) {
    return $cat['type'] == 'income'; }));
$expense_count = count(array_filter($categories, function ($cat) {
    return $cat['type'] == 'expense'; }));
$used_count = count($category_stats);
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#667eea">
    <title>Категории - Финансовый дневник</title>
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
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            padding: 0 16px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 12px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
            margin: 6px 0;
        }

        .stat-label {
            font-size: 11px;
            color: #6c757d;
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
            font-size: 14px;
            font-weight: 500;
            background: #f8f9fa;
            color: #6c757d;
        }

        .filter-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .categories-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            padding: 0 16px;
            margin-bottom: 20px;
        }

        .category-card {
            background: white;
            border-radius: 20px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            overflow: hidden;
        }

        .category-card:active {
            transform: scale(0.98);
        }

        .category-card.income {
            border-top: 3px solid #28a745;
        }

        .category-card.expense {
            border-top: 3px solid #dc3545;
        }

        .category-color {
            width: 40px;
            height: 40px;
            border-radius: 20px;
            margin-bottom: 12px;
        }

        .category-name {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 6px;
            word-break: break-word;
        }

        .category-stats {
            font-size: 11px;
            color: #6c757d;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #e9ecef;
        }

        .category-amount {
            font-weight: bold;
            font-size: 13px;
        }

        .category-actions {
            position: absolute;
            top: 12px;
            right: 12px;
            display: flex;
            gap: 6px;
        }

        .category-actions button {
            background: white;
            border: none;
            border-radius: 20px;
            padding: 4px 8px;
            font-size: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .type-badge {
            position: absolute;
            bottom: 12px;
            right: 12px;
            font-size: 10px;
            padding: 4px 8px;
            border-radius: 12px;
        }

        .type-badge.income {
            background: #d4edda;
            color: #155724;
        }

        .type-badge.expense {
            background: #f8d7da;
            color: #721c24;
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
            grid-column: span 2;
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .color-preview {
            width: 40px;
            height: 40px;
            border-radius: 20px;
            border: 2px solid #e9ecef;
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

        .alert {
            border-radius: 16px;
            margin: 0 16px 16px;
        }
    </style>
</head>

<body>
    <div class="mobile-header">
        <div class="d-flex justify-content-between align-items-center">
            <a href="dashboard.php" class="back-button">← Назад</a>
            <div></div>
        </div>
        <div class="page-title fs-3 fw-bold mt-2">Категории</div>
        <div class="small">Управление категориями доходов и расходов</div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div>📁</div>
            <div class="stat-value"><?php echo count($categories); ?></div>
            <div class="stat-label">Всего категорий</div>
        </div>
        <div class="stat-card">
            <div class="text-success">↑</div>
            <div class="stat-value text-success"><?php echo $income_count; ?></div>
            <div class="stat-label">Доходы</div>
        </div>
        <div class="stat-card">
            <div class="text-danger">↓</div>
            <div class="stat-value text-danger"><?php echo $expense_count; ?></div>
            <div class="stat-label">Расходы</div>
        </div>
    </div>

    <div class="filter-bar">
        <a href="?type=all" class="filter-btn <?php echo $filter_type == 'all' ? 'active' : ''; ?>">Все</a>
        <a href="?type=income" class="filter-btn <?php echo $filter_type == 'income' ? 'active' : ''; ?>">Доходы</a>
        <a href="?type=expense" class="filter-btn <?php echo $filter_type == 'expense' ? 'active' : ''; ?>">Расходы</a>
    </div>

    <div class="categories-grid">
        <?php if (count($categories) > 0): ?>
            <?php foreach ($categories as $cat): ?>
                <div class="category-card <?php echo $cat['type']; ?>" data-id="<?php echo $cat['id']; ?>"
                    data-name="<?php echo htmlspecialchars($cat['name']); ?>" data-color="<?php echo $cat['color']; ?>"
                    data-type="<?php echo $cat['type']; ?>">
                    <div class="category-color" style="background-color: <?php echo $cat['color']; ?>;"></div>
                    <div class="category-name"><?php echo htmlspecialchars($cat['name']); ?></div>
                    <div class="category-stats">
                        <?php if (isset($category_stats[$cat['id']])): ?>
                            <div class="category-amount">
                                <?php echo number_format($category_stats[$cat['id']]['total'], 0, '.', ' '); ?> ₽
                            </div>
                            <div><?php echo $category_stats[$cat['id']]['count']; ?> операций</div>
                        <?php else: ?>
                            <div class="text-muted">Нет операций</div>
                        <?php endif; ?>
                    </div>
                    <div class="category-actions">
                        <button class="edit-cat" data-id="<?php echo $cat['id']; ?>"
                            data-name="<?php echo htmlspecialchars($cat['name']); ?>" data-color="<?php echo $cat['color']; ?>"
                            data-type="<?php echo $cat['type']; ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="delete-cat" data-id="<?php echo $cat['id']; ?>"
                            data-name="<?php echo htmlspecialchars($cat['name']); ?>">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                    <div class="type-badge <?php echo $cat['type']; ?>">
                        <?php echo $cat['type'] == 'income' ? 'Доход' : 'Расход'; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="bi bi-grid"></i>
                <h5>Нет категорий</h5>
                <p class="small">Создайте первую категорию, нажав на кнопку +</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="fab" id="addCategoryBtn"><i class="bi bi-plus-lg"></i></div>

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
            <a href="debts.php" class="nav-item">
                <i class="bi bi-credit-card-2-front"></i>
                <span>Долги</span>
            </a>
            <a href="profile.php" class="nav-item active">
                <i class="bi bi-person"></i>
                <span>Профиль</span>
            </a>
        </div>
    </div>

    <!-- Add Category Modal -->
    <div class="modal fade" id="addCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Добавить категорию</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Название категории</label>
                            <input type="text" name="category_name" class="form-control" required maxlength="50"
                                placeholder="например: Продукты, Транспорт">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Тип</label>
                            <select name="category_type" class="form-select" required>
                                <option value="expense">Расход (траты)</option>
                                <option value="income">Доход (поступления)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Цвет</label>
                            <div class="d-flex align-items-center gap-3">
                                <input type="color" name="category_color" class="form-control form-control-color w-25"
                                    value="#6c757d">
                                <div class="color-preview" id="addColorPreview" style="background-color: #6c757d;">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="submit" name="add_category"
                            class="btn btn-primary w-100 rounded-pill">Добавить</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Category Modal -->
    <div class="modal fade" id="editCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Редактировать категорию</h5><button type="button" class="btn-close"
                        data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="category_id" id="editCatId">
                        <div class="mb-3">
                            <label class="form-label">Название категории</label>
                            <input type="text" name="category_name" id="editCatName" class="form-control" required
                                maxlength="50">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Тип</label>
                            <select name="category_type" id="editCatType" class="form-select" required>
                                <option value="expense">Расход (траты)</option>
                                <option value="income">Доход (поступления)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Цвет</label>
                            <div class="d-flex align-items-center gap-3">
                                <input type="color" name="category_color" id="editCatColor"
                                    class="form-control form-control-color w-25">
                                <div class="color-preview" id="editColorPreview"></div>
                            </div>
                        </div>
                        <div class="alert alert-warning small">
                            <i class="bi bi-exclamation-triangle"></i> Изменение типа может повлиять на существующие
                            операции
                        </div>
                    </div>
                    <div class="modal-footer"><button type="submit" name="edit_category"
                            class="btn btn-primary w-100 rounded-pill">Сохранить</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Category Modal -->
    <div class="modal fade" id="deleteCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Удалить категорию?</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="category_id" id="deleteCatId">
                        <p>Удалить категорию <strong id="deleteCatName"></strong>?</p>
                        <div class="alert alert-danger small">
                            <i class="bi bi-exclamation-triangle"></i> Категорию нельзя удалить, если есть операции
                        </div>
                    </div>
                    <div class="modal-footer"><button type="submit" name="delete_category"
                            class="btn btn-danger w-100 rounded-pill">Удалить</button></div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const addModal = new bootstrap.Modal(document.getElementById('addCategoryModal'));
            const editModal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteCategoryModal'));

            // Кнопка добавления
            document.getElementById('addCategoryBtn').onclick = () => addModal.show();

            // Предпросмотр цвета при добавлении
            const addColorInput = document.querySelector('#addCategoryModal input[name="category_color"]');
            const addColorPreview = document.getElementById('addColorPreview');
            if (addColorInput && addColorPreview) {
                addColorInput.oninput = function () {
                    addColorPreview.style.backgroundColor = this.value;
                };
            }

            // Редактирование категории
            document.querySelectorAll('.edit-cat').forEach(btn => {
                btn.onclick = function (e) {
                    e.stopPropagation();
                    document.getElementById('editCatId').value = this.dataset.id;
                    document.getElementById('editCatName').value = this.dataset.name;
                    document.getElementById('editCatColor').value = this.dataset.color;
                    document.getElementById('editCatType').value = this.dataset.type;
                    document.getElementById('editColorPreview').style.backgroundColor = this.dataset.color;
                    editModal.show();
                };
            });

            // Предпросмотр цвета при редактировании
            const editColorInput = document.querySelector('#editCategoryModal input[name="category_color"]');
            const editColorPreview = document.getElementById('editColorPreview');
            if (editColorInput && editColorPreview) {
                editColorInput.oninput = function () {
                    editColorPreview.style.backgroundColor = this.value;
                };
            }

            // Удаление категории
            document.querySelectorAll('.delete-cat').forEach(btn => {
                btn.onclick = function (e) {
                    e.stopPropagation();
                    document.getElementById('deleteCatId').value = this.dataset.id;
                    document.getElementById('deleteCatName').innerText = this.dataset.name;
                    deleteModal.show();
                };
            });

            // Клик по карточке для быстрого редактирования
            document.querySelectorAll('.category-card').forEach(card => {
                card.onclick = function (e) {
                    if (e.target.closest('.edit-cat') || e.target.closest('.delete-cat')) return;
                    const editBtn = this.querySelector('.edit-cat');
                    if (editBtn) editBtn.click();
                };
            });
        });
    </script>
</body>

</html>