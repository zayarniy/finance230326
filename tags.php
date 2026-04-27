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
                header("Location: tags.php");
                exit;
            } catch (PDOException $e) {
                $error = "Метка с таким названием уже существует";
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
                header("Location: tags.php");
                exit;
            } catch (PDOException $e) {
                $error = "Метка с таким названием уже существует";
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
            header("Location: tags.php");
            exit;
        }
    }
}

// Получение списка меток
$stmt = $pdo->prepare("SELECT * FROM tags WHERE user_id = ? ORDER BY name ASC");
$stmt->execute([$user_id]);
$tags = $stmt->fetchAll();

// Подсчет использования меток
$tag_usage = [];
$stmt = $pdo->prepare("SELECT tags_text FROM transactions WHERE user_id = ?");
$stmt->execute([$user_id]);
$transactions = $stmt->fetchAll();

foreach ($transactions as $t) {
    if (!empty($t['tags_text'])) {
        $tags_list = explode(';', $t['tags_text']);
        foreach ($tags_list as $tag_name) {
            $tag_name = trim($tag_name);
            if (!empty($tag_name)) {
                $tag_usage[$tag_name] = ($tag_usage[$tag_name] ?? 0) + 1;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#667eea">
    <title>Метки - Финансовый дневник</title>
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
            font-size: 32px;
            font-weight: bold;
            margin: 8px 0;
        }

        .tags-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            padding: 0 16px;
            margin-bottom: 20px;
        }

        .tag-card {
            background: white;
            border-radius: 20px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            overflow: hidden;
        }

        .tag-card:active {
            transform: scale(0.98);
        }

        .tag-color {
            width: 40px;
            height: 40px;
            border-radius: 20px;
            margin-bottom: 12px;
        }

        .tag-name {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
            word-break: break-word;
        }

        .tag-usage {
            font-size: 11px;
            color: #6c757d;
        }

        .tag-actions {
            position: absolute;
            top: 12px;
            right: 12px;
            display: flex;
            gap: 8px;
            opacity: 0.7;
        }

        .tag-actions button {
            background: white;
            border: none;
            border-radius: 20px;
            padding: 6px 10px;
            font-size: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
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
    </style>
</head>

<body>
    <div class="mobile-header">
        <div class="d-flex justify-content-between align-items-center">
            <a href="dashboard.php" class="back-button">← Назад</a>
            <div></div>
        </div>
        <div class="page-title fs-3 fw-bold mt-2">Метки</div>
        <div class="small">Управление метками для операций</div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div>🏷️</div>
            <div class="stat-value"><?php echo count($tags); ?></div>
            <div class="small text-muted">Всего меток</div>
        </div>
        <div class="stat-card">
            <div>📊</div>
            <div class="stat-value"><?php echo count($tag_usage); ?></div>
            <div class="small text-muted">Используется</div>
        </div>
    </div>

    <div class="tags-grid">
        <?php if (count($tags) > 0): ?>
            <?php foreach ($tags as $tag): ?>
                <div class="tag-card" data-id="<?php echo $tag['id']; ?>"
                    data-name="<?php echo htmlspecialchars($tag['name']); ?>" data-color="<?php echo $tag['color']; ?>">
                    <div class="tag-color" style="background-color: <?php echo $tag['color']; ?>;"></div>
                    <div class="tag-name"><?php echo htmlspecialchars($tag['name']); ?></div>
                    <div class="tag-usage">
                        <i class="bi bi-hash"></i> <?php echo $tag_usage[$tag['name']] ?? 0; ?> операций
                    </div>
                    <div class="tag-actions">
                        <button class="edit-tag" data-id="<?php echo $tag['id']; ?>"
                            data-name="<?php echo htmlspecialchars($tag['name']); ?>" data-color="<?php echo $tag['color']; ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="delete-tag" data-id="<?php echo $tag['id']; ?>"
                            data-name="<?php echo htmlspecialchars($tag['name']); ?>">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state" style="grid-column: span 2;">
                <i class="bi bi-tags"></i>
                <h5>Нет меток</h5>
                <p class="small">Создайте первую метку, нажав на кнопку +</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="fab" id="addTagBtn"><i class="bi bi-plus-lg"></i></div>

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


    <!-- Add Tag Modal -->
    <div class="modal fade" id="addTagModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Добавить метку</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Название метки</label>
                            <input type="text" name="tag_name" class="form-control" required maxlength="50"
                                placeholder="например: важное, срочно, семья">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Цвет</label>
                            <div class="d-flex align-items-center gap-3">
                                <input type="color" name="tag_color" class="form-control form-control-color w-25"
                                    value="#6c757d">
                                <div class="color-preview" id="colorPreview" style="background-color: #6c757d;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="submit" name="add_tag"
                            class="btn btn-primary w-100 rounded-pill">Добавить</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Tag Modal -->
    <div class="modal fade" id="editTagModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Редактировать метку</h5><button type="button" class="btn-close"
                        data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="tag_id" id="editTagId">
                        <div class="mb-3">
                            <label class="form-label">Название метки</label>
                            <input type="text" name="tag_name" id="editTagName" class="form-control" required
                                maxlength="50">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Цвет</label>
                            <div class="d-flex align-items-center gap-3">
                                <input type="color" name="tag_color" id="editTagColor"
                                    class="form-control form-control-color w-25">
                                <div class="color-preview" id="editColorPreview"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="submit" name="edit_tag"
                            class="btn btn-primary w-100 rounded-pill">Сохранить</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Tag Modal -->
    <div class="modal fade" id="deleteTagModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Удалить метку?</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="tag_id" id="deleteTagId">
                        <p>Удалить метку <strong id="deleteTagName"></strong>?</p>
                        <div class="alert alert-warning small">
                            <i class="bi bi-exclamation-triangle"></i> Метка останется в операциях, но пропадет из
                            списка
                        </div>
                    </div>
                    <div class="modal-footer"><button type="submit" name="delete_tag"
                            class="btn btn-danger w-100 rounded-pill">Удалить</button></div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const addModal = new bootstrap.Modal(document.getElementById('addTagModal'));
            const editModal = new bootstrap.Modal(document.getElementById('editTagModal'));
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteTagModal'));

            // Кнопка добавления
            document.getElementById('addTagBtn').onclick = () => addModal.show();

            // Предпросмотр цвета при добавлении
            const colorInput = document.querySelector('#addTagModal input[name="tag_color"]');
            const colorPreview = document.getElementById('colorPreview');
            if (colorInput && colorPreview) {
                colorInput.oninput = function () {
                    colorPreview.style.backgroundColor = this.value;
                };
            }

            // Редактирование метки
            document.querySelectorAll('.edit-tag').forEach(btn => {
                btn.onclick = function (e) {
                    e.stopPropagation();
                    document.getElementById('editTagId').value = this.dataset.id;
                    document.getElementById('editTagName').value = this.dataset.name;
                    document.getElementById('editTagColor').value = this.dataset.color;
                    document.getElementById('editColorPreview').style.backgroundColor = this.dataset.color;
                    editModal.show();
                };
            });

            // Предпросмотр цвета при редактировании
            const editColorInput = document.querySelector('#editTagModal input[name="tag_color"]');
            const editColorPreview = document.getElementById('editColorPreview');
            if (editColorInput && editColorPreview) {
                editColorInput.oninput = function () {
                    editColorPreview.style.backgroundColor = this.value;
                };
            }

            // Удаление метки
            document.querySelectorAll('.delete-tag').forEach(btn => {
                btn.onclick = function (e) {
                    e.stopPropagation();
                    document.getElementById('deleteTagId').value = this.dataset.id;
                    document.getElementById('deleteTagName').innerText = this.dataset.name;
                    deleteModal.show();
                };
            });

            // Клик по карточке метки для быстрого редактирования
            document.querySelectorAll('.tag-card').forEach(card => {
                card.onclick = function (e) {
                    if (e.target.closest('.edit-tag') || e.target.closest('.delete-tag')) return;
                    const editBtn = this.querySelector('.edit-tag');
                    if (editBtn) editBtn.click();
                };
            });
        });
    </script>
</body>

</html>