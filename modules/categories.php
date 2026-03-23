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
$edit_category = null;
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
                $message = "Категория успешно добавлена";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = "Категория с таким названием и типом уже существует";
                } else {
                    $error = "Ошибка при добавлении категории: " . $e->getMessage();
                }
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
                $message = "Категория успешно обновлена";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = "Категория с таким названием и типом уже существует";
                } else {
                    $error = "Ошибка при обновлении категории";
                }
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
                $error = "Невозможно удалить категорию. Она используется в $count операциях. Сначала удалите или переназначьте эти операции.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ? AND user_id = ?");
                $stmt->execute([$category_id, $user_id]);
                $message = "Категория успешно удалена";
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

// Если нужно редактировать - получаем данные
if (isset($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ? AND user_id = ?");
    $stmt->execute([$edit_id, $user_id]);
    $edit_category = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Банк Категорий - Финансовый дневник</title>
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
        .category-card {
            border-radius: 12px;
            transition: all 0.3s;
            cursor: pointer;
            border: none;
            position: relative;
            overflow: hidden;
        }
        .category-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .category-card.income {
            border-left: 4px solid #28a745;
        }
        .category-card.expense {
            border-left: 4px solid #dc3545;
        }
        .category-color {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            margin-right: 15px;
            transition: transform 0.3s;
        }
        .category-card:hover .category-color {
            transform: scale(1.05);
        }
        .category-actions {
            opacity: 0;
            transition: opacity 0.3s;
        }
        .category-card:hover .category-actions {
            opacity: 1;
        }
        .stats-badge {
            background: #f8f9fa;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            margin-right: 8px;
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
        .stat-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .type-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 12px;
            padding: 4px 12px;
            border-radius: 20px;
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
                        <a class="nav-link active" href="categories.php">
                            <i class="bi bi-grid"></i> Банк Категорий
                        </a>
                        <a class="nav-link" href="accounts.php">
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
                                <h2><i class="bi bi-grid"></i> Банк Категорий</h2>
                                <p class="text-muted">Управление категориями для доходов и расходов</p>
                            </div>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                                <i class="bi bi-plus-circle"></i> Добавить категорию
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
                                                <h6 class="card-title">Всего категорий</h6>
                                                <h3 class="mb-0"><?php echo count($categories); ?></h3>
                                            </div>
                                            <i class="bi bi-grid fs-1 opacity-50"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card bg-success text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="card-title">Категории доходов</h6>
                                                <h3 class="mb-0">
                                                    <?php 
                                                        $income_count = count(array_filter($categories, function($cat) {
                                                            return $cat['type'] == 'income';
                                                        }));
                                                        echo $income_count;
                                                    ?>
                                                </h3>
                                            </div>
                                            <i class="bi bi-arrow-up-circle fs-1 opacity-50"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card bg-danger text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="card-title">Категории расходов</h6>
                                                <h3 class="mb-0">
                                                    <?php 
                                                        $expense_count = count(array_filter($categories, function($cat) {
                                                            return $cat['type'] == 'expense';
                                                        }));
                                                        echo $expense_count;
                                                    ?>
                                                </h3>
                                            </div>
                                            <i class="bi bi-arrow-down-circle fs-1 opacity-50"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card bg-info text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="card-title">Используется в операциях</h6>
                                                <h3 class="mb-0"><?php echo count($category_stats); ?></h3>
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
                                <a href="?type=all" class="btn btn-outline-secondary filter-btn <?php echo $filter_type == 'all' ? 'active' : ''; ?>">
                                    <i class="bi bi-grid"></i> Все
                                </a>
                                <a href="?type=income" class="btn btn-outline-success filter-btn <?php echo $filter_type == 'income' ? 'active' : ''; ?>">
                                    <i class="bi bi-arrow-up-circle"></i> Доходы
                                </a>
                                <a href="?type=expense" class="btn btn-outline-danger filter-btn <?php echo $filter_type == 'expense' ? 'active' : ''; ?>">
                                    <i class="bi bi-arrow-down-circle"></i> Расходы
                                </a>
                            </div>
                        </div>
                        
                        <!-- Categories Grid -->
                        <div class="row">
                            <?php if (count($categories) > 0): ?>
                                <?php foreach ($categories as $category): ?>
                                    <div class="col-md-4 col-lg-3 mb-4">
                                        <div class="card category-card <?php echo $category['type']; ?> h-100">
                                            <div class="card-body">
                                                <div class="d-flex align-items-start mb-3">
                                                    <div class="category-color" style="background-color: <?php echo htmlspecialchars($category['color']); ?>;"></div>
                                                    <div class="flex-grow-1">
                                                        <h6 class="mb-0"><?php echo htmlspecialchars($category['name']); ?></h6>
                                                        <small class="text-muted">ID: <?php echo $category['id']; ?></small>
                                                    </div>
                                                </div>
                                                
                                                <div class="mt-3">
                                                    <?php if (isset($category_stats[$category['id']])): ?>
                                                        <span class="stats-badge">
                                                            <i class="bi bi-currency-dollar"></i> 
                                                            <?php echo number_format($category_stats[$category['id']]['total'], 2, '.', ' '); ?> ₽
                                                        </span>
                                                        <span class="stats-badge">
                                                            <i class="bi bi-hash"></i> 
                                                            <?php echo $category_stats[$category['id']]['count']; ?> операций
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="stats-badge text-muted">
                                                            <i class="bi bi-inbox"></i> Нет операций
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="category-actions mt-3">
                                                    <div class="btn-group w-100">
                                                        <a href="?edit=<?php echo $category['id']; ?>" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editCategoryModal" 
                                                           data-category-id="<?php echo $category['id']; ?>" 
                                                           data-category-name="<?php echo htmlspecialchars($category['name']); ?>" 
                                                           data-category-color="<?php echo htmlspecialchars($category['color']); ?>"
                                                           data-category-type="<?php echo $category['type']; ?>">
                                                            <i class="bi bi-pencil"></i> Редактировать
                                                        </a>
                                                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteCategoryModal" 
                                                                data-category-id="<?php echo $category['id']; ?>" 
                                                                data-category-name="<?php echo htmlspecialchars($category['name']); ?>">
                                                            <i class="bi bi-trash"></i> Удалить
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="type-badge <?php echo $category['type'] == 'income' ? 'bg-success' : 'bg-danger'; ?> text-white">
                                                <i class="bi bi-<?php echo $category['type'] == 'income' ? 'arrow-up' : 'arrow-down'; ?>"></i>
                                                <?php echo $category['type'] == 'income' ? 'Доход' : 'Расход'; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="col-12">
                                    <div class="alert alert-info text-center">
                                        <i class="bi bi-info-circle fs-1"></i>
                                        <h5>Нет добавленных категорий</h5>
                                        <p>Нажмите кнопку "Добавить категорию" чтобы создать первую категорию</p>
                                        <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                                            <i class="bi bi-plus-circle"></i> Добавить категорию
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
    
    <!-- Add Category Modal -->
    <div class="modal fade" id="addCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Добавить категорию</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="category_name" class="form-label">Название категории *</label>
                            <input type="text" class="form-control" id="category_name" name="category_name" required maxlength="50">
                            <small class="text-muted">Максимум 50 символов</small>
                        </div>
                        <div class="mb-3">
                            <label for="category_type" class="form-label">Тип категории *</label>
                            <select class="form-select" id="category_type" name="category_type" required>
                                <option value="expense">Расход (траты)</option>
                                <option value="income">Доход (поступления)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="category_color" class="form-label">Цвет категории</label>
                            <div class="d-flex align-items-center">
                                <input type="color" class="form-control form-control-color w-25 me-2" id="category_color" name="category_color" value="#6c757d">
                                <span class="text-muted">Выберите цвет для визуального выделения</span>
                            </div>
                        </div>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> 
                            <strong>Примеры категорий:</strong><br>
                            <span class="text-success">Доходы:</span> Зарплата, Фриланс, Подарки, Инвестиции<br>
                            <span class="text-danger">Расходы:</span> Продукты, Транспорт, Коммунальные, Развлечения
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" name="add_category" class="btn btn-primary">Добавить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Category Modal -->
    <div class="modal fade" id="editCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Редактировать категорию</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" id="edit_category_id" name="category_id">
                        <div class="mb-3">
                            <label for="edit_category_name" class="form-label">Название категории *</label>
                            <input type="text" class="form-control" id="edit_category_name" name="category_name" required maxlength="50">
                        </div>
                        <div class="mb-3">
                            <label for="edit_category_type" class="form-label">Тип категории *</label>
                            <select class="form-select" id="edit_category_type" name="category_type" required>
                                <option value="expense">Расход (траты)</option>
                                <option value="income">Доход (поступления)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_category_color" class="form-label">Цвет категории</label>
                            <input type="color" class="form-control form-control-color" id="edit_category_color" name="category_color">
                        </div>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> 
                            Изменение типа категории может повлиять на существующие операции!
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" name="edit_category" class="btn btn-primary">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Category Modal -->
    <div class="modal fade" id="deleteCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Удаление категории</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" id="delete_category_id" name="category_id">
                        <p>Вы уверены, что хотите удалить категорию <strong id="delete_category_name"></strong>?</p>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i> 
                            <strong>Внимание!</strong> Категорию нельзя удалить, если она используется в операциях. Сначала удалите или переназначьте все операции с этой категорией.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" name="delete_category" class="btn btn-danger">Удалить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Обработка модального окна редактирования
        const editCategoryModal = document.getElementById('editCategoryModal');
        if (editCategoryModal) {
            editCategoryModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const categoryId = button.getAttribute('data-category-id');
                const categoryName = button.getAttribute('data-category-name');
                const categoryColor = button.getAttribute('data-category-color');
                const categoryType = button.getAttribute('data-category-type');
                
                const modalInputId = editCategoryModal.querySelector('#edit_category_id');
                const modalInputName = editCategoryModal.querySelector('#edit_category_name');
                const modalInputColor = editCategoryModal.querySelector('#edit_category_color');
                const modalInputType = editCategoryModal.querySelector('#edit_category_type');
                
                if (modalInputId) modalInputId.value = categoryId;
                if (modalInputName) modalInputName.value = categoryName;
                if (modalInputColor) modalInputColor.value = categoryColor;
                if (modalInputType) modalInputType.value = categoryType;
            });
        }
        
        // Обработка модального окна удаления
        const deleteCategoryModal = document.getElementById('deleteCategoryModal');
        if (deleteCategoryModal) {
            deleteCategoryModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const categoryId = button.getAttribute('data-category-id');
                const categoryName = button.getAttribute('data-category-name');
                
                const modalInputId = deleteCategoryModal.querySelector('#delete_category_id');
                const modalSpanName = deleteCategoryModal.querySelector('#delete_category_name');
                
                if (modalInputId) modalInputId.value = categoryId;
                if (modalSpanName) modalSpanName.textContent = categoryName;
            });
        }
        
        // Динамическое изменение иконки при выборе типа категории в форме добавления
        const categoryTypeSelect = document.getElementById('category_type');
        if (categoryTypeSelect) {
            categoryTypeSelect.addEventListener('change', function() {
                const icon = this.value === 'income' ? 'arrow-up-circle' : 'arrow-down-circle';
                const color = this.value === 'income' ? 'success' : 'danger';
                // Можно добавить визуальную обратную связь
            });
        }
    </script>
</body>
</html>