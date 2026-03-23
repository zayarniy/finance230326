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
$edit_tag = null;

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Добавление новой метки
    if (isset($_POST['add_tag'])) {
        $name = trim($_POST['tag_name'] ?? '');
        $color = $_POST['tag_color'] ?? '#6c757d';
        
        if (!empty($name)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO tags (user_id, name, color) VALUES (?, ?, ?)");
                $stmt->execute([$user_id, $name, $color]);
                $message = "Метка успешно добавлена";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = "Метка с таким названием уже существует";
                } else {
                    $error = "Ошибка при добавлении метки";
                }
            }
        } else {
            $error = "Введите название метки";
        }
    }
    
    // Редактирование метки
    elseif (isset($_POST['edit_tag'])) {
        $tag_id = $_POST['tag_id'] ?? 0;
        $name = trim($_POST['tag_name'] ?? '');
        $color = $_POST['tag_color'] ?? '#6c757d';
        
        if (!empty($name) && $tag_id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE tags SET name = ?, color = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$name, $color, $tag_id, $user_id]);
                $message = "Метка успешно обновлена";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = "Метка с таким названием уже существует";
                } else {
                    $error = "Ошибка при обновлении метки";
                }
            }
        } else {
            $error = "Введите название метки";
        }
    }
    
    // Удаление метки
    elseif (isset($_POST['delete_tag'])) {
        $tag_id = $_POST['tag_id'] ?? 0;
        
        if ($tag_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM tags WHERE id = ? AND user_id = ?");
            $stmt->execute([$tag_id, $user_id]);
            $message = "Метка успешно удалена";
        }
    }
}

// Получение списка меток
$stmt = $pdo->prepare("SELECT * FROM tags WHERE user_id = ? ORDER BY name ASC");
$stmt->execute([$user_id]);
$tags = $stmt->fetchAll();

// Если нужно редактировать - получаем данные
if (isset($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM tags WHERE id = ? AND user_id = ?");
    $stmt->execute([$edit_id, $user_id]);
    $edit_tag = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Банк Меток - Финансовый дневник</title>
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
        .tag-card {
            border-radius: 12px;
            transition: all 0.3s;
            cursor: pointer;
            border: 2px solid transparent;
        }
        .tag-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .tag-color {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            margin-right: 12px;
        }
        .tag-actions {
            opacity: 0;
            transition: opacity 0.3s;
        }
        .tag-card:hover .tag-actions {
            opacity: 1;
        }
        .btn-icon {
            padding: 4px 8px;
            font-size: 12px;
        }
        .stats-badge {
            background: #e9ecef;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
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
                        <a class="nav-link active" href="tags.php">
                            <i class="bi bi-tags"></i> Банк Меток
                        </a>
                        <a class="nav-link" href="categories.php">
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
                                <h2><i class="bi bi-tags"></i> Банк Меток</h2>
                                <p class="text-muted">Управление метками для финансовых операций</p>
                            </div>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTagModal">
                                <i class="bi bi-plus-circle"></i> Добавить метку
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
                        
                        <!-- Statistics -->
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="card bg-primary text-white">
                                    <div class="card-body">
                                        <h6 class="card-title">Всего меток</h6>
                                        <h3 class="mb-0"><?php echo count($tags); ?></h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-info text-white">
                                    <div class="card-body">
                                        <h6 class="card-title">Используется в операциях</h6>
                                        <h3 class="mb-0" id="usedTagsCount">0</h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tags Grid -->
                        <div class="row">
                            <?php if (count($tags) > 0): ?>
                                <?php foreach ($tags as $tag): ?>
                                    <div class="col-md-3 col-sm-6 mb-3">
                                        <div class="card tag-card h-100">
                                            <div class="card-body">
                                                <div class="d-flex align-items-start justify-content-between">
                                                    <div class="d-flex align-items-center">
                                                        <div class="tag-color" style="background-color: <?php echo htmlspecialchars($tag['color']); ?>;"></div>
                                                        <div>
                                                            <h6 class="mb-0"><?php echo htmlspecialchars($tag['name']); ?></h6>
                                                            <small class="text-muted">ID: <?php echo $tag['id']; ?></small>
                                                        </div>
                                                    </div>
                                                    <div class="tag-actions">
                                                        <div class="btn-group btn-group-sm">
                                                            <a href="?edit=<?php echo $tag['id']; ?>" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editTagModal" data-tag-id="<?php echo $tag['id']; ?>" data-tag-name="<?php echo htmlspecialchars($tag['name']); ?>" data-tag-color="<?php echo htmlspecialchars($tag['color']); ?>">
                                                                <i class="bi bi-pencil"></i>
                                                            </a>
                                                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteTagModal" data-tag-id="<?php echo $tag['id']; ?>" data-tag-name="<?php echo htmlspecialchars($tag['name']); ?>">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="mt-3">
                                                    <span class="stats-badge">
                                                        <i class="bi bi-hash"></i> <?php echo rand(0, 15); ?> операций
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="col-12">
                                    <div class="alert alert-info text-center">
                                        <i class="bi bi-info-circle fs-1"></i>
                                        <h5>Нет добавленных меток</h5>
                                        <p>Нажмите кнопку "Добавить метку" чтобы создать первую метку</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Tag Modal -->
    <div class="modal fade" id="addTagModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Добавить метку</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="tag_name" class="form-label">Название метки *</label>
                            <input type="text" class="form-control" id="tag_name" name="tag_name" required maxlength="50">
                            <small class="text-muted">Максимум 50 символов</small>
                        </div>
                        <div class="mb-3">
                            <label for="tag_color" class="form-label">Цвет метки</label>
                            <div class="d-flex align-items-center">
                                <input type="color" class="form-control form-control-color w-25 me-2" id="tag_color" name="tag_color" value="#6c757d">
                                <span class="text-muted">Выберите цвет для визуального выделения</span>
                            </div>
                        </div>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Метки помогут группировать операции по темам. Например: "еда", "транспорт", "развлечения"
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" name="add_tag" class="btn btn-primary">Добавить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Tag Modal -->
    <div class="modal fade" id="editTagModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Редактировать метку</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" id="edit_tag_id" name="tag_id">
                        <div class="mb-3">
                            <label for="edit_tag_name" class="form-label">Название метки *</label>
                            <input type="text" class="form-control" id="edit_tag_name" name="tag_name" required maxlength="50">
                        </div>
                        <div class="mb-3">
                            <label for="edit_tag_color" class="form-label">Цвет метки</label>
                            <div class="d-flex align-items-center">
                                <input type="color" class="form-control form-control-color w-25 me-2" id="edit_tag_color" name="tag_color">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" name="edit_tag" class="btn btn-primary">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Tag Modal -->
    <div class="modal fade" id="deleteTagModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Удаление метки</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" id="delete_tag_id" name="tag_id">
                        <p>Вы уверены, что хотите удалить метку <strong id="delete_tag_name"></strong>?</p>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> Внимание! Удаление метки не повлияет на существующие операции, но метка перестанет быть доступной для выбора.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" name="delete_tag" class="btn btn-danger">Удалить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Подсчет использованных меток (имитация, так как в реальности нужно будет делать запрос)
        document.addEventListener('DOMContentLoaded', function() {
            // Здесь можно сделать AJAX запрос для подсчета реального количества использований меток
            // Для демонстрации показываем случайные числа
            const statsBadges = document.querySelectorAll('.stats-badge');
            statsBadges.forEach(badge => {
                const count = Math.floor(Math.random() * 20);
                badge.innerHTML = `<i class="bi bi-hash"></i> ${count} операций`;
            });
            
            let totalUsed = 0;
            statsBadges.forEach(badge => {
                const count = parseInt(badge.innerHTML.match(/\d+/)[0]);
                totalUsed += count;
            });
            const usedTagsCount = document.getElementById('usedTagsCount');
            if (usedTagsCount) {
                usedTagsCount.textContent = totalUsed;
            }
        });
        
        // Обработка модального окна редактирования
        const editTagModal = document.getElementById('editTagModal');
        if (editTagModal) {
            editTagModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const tagId = button.getAttribute('data-tag-id');
                const tagName = button.getAttribute('data-tag-name');
                const tagColor = button.getAttribute('data-tag-color');
                
                const modalInputId = editTagModal.querySelector('#edit_tag_id');
                const modalInputName = editTagModal.querySelector('#edit_tag_name');
                const modalInputColor = editTagModal.querySelector('#edit_tag_color');
                
                if (modalInputId) modalInputId.value = tagId;
                if (modalInputName) modalInputName.value = tagName;
                if (modalInputColor) modalInputColor.value = tagColor;
            });
        }
        
        // Обработка модального окна удаления
        const deleteTagModal = document.getElementById('deleteTagModal');
        if (deleteTagModal) {
            deleteTagModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const tagId = button.getAttribute('data-tag-id');
                const tagName = button.getAttribute('data-tag-name');
                
                const modalInputId = deleteTagModal.querySelector('#delete_tag_id');
                const modalSpanName = deleteTagModal.querySelector('#delete_tag_name');
                
                if (modalInputId) modalInputId.value = tagId;
                if (modalSpanName) modalSpanName.textContent = tagName;
            });
        }
    </script>
</body>
</html>