<?php
/**
 * Admin page for managing report tags/labels
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

// Require admin role
if (!isAdmin()) {
    setFlash('error', 'Access denied. Admin privileges required.');
    header('Location: ?page=dashboard');
    exit;
}

$db = Database::getInstance();

// Initialize default tags
$db->initializeDefaultTags();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $color = trim($_POST['color'] ?? '#808080');
        $description = trim($_POST['description'] ?? '');

        if (!$name) {
            setFlash('error', 'Tag name is required.');
        } elseif ($db->getTagByName($name)) {
            setFlash('error', 'A tag with this name already exists.');
        } else {
            $id = $db->createTag($name, $color, $description);
            if ($id) {
                setFlash('success', "Tag '$name' created successfully.");
            } else {
                setFlash('error', 'Failed to create tag.');
            }
        }
    } elseif ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $color = trim($_POST['color'] ?? '#808080');
        $description = trim($_POST['description'] ?? '');

        $tag = $db->getTag($id);
        if (!$tag) {
            setFlash('error', 'Tag not found.');
        } elseif (!$name) {
            setFlash('error', 'Tag name is required.');
        } else {
            if ($db->updateTag($id, $name, $color, $description)) {
                setFlash('success', "Tag '$name' updated successfully.");
            } else {
                setFlash('error', 'Failed to update tag.');
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        $tag = $db->getTag($id);
        if (!$tag) {
            setFlash('error', 'Tag not found.');
        } else {
            if ($db->deleteTag($id)) {
                setFlash('success', "Tag '{$tag['name']}' deleted.");
            } else {
                setFlash('error', 'Failed to delete tag.');
            }
        }
    }

    header('Location: ?page=admin_tags');
    exit;
}

// Get all tags
$tags = $db->getAllTags();
?>

<h1 class="page-title">Report Tags</h1>

<div class="admin-nav" style="margin-bottom: 20px;">
    <a href="?page=admin" class="btn btn-sm btn-secondary">← Back to Admin</a>
</div>

<div class="tags-layout">
    <!-- Create New Tag Form -->
    <div class="card">
        <h3>Create New Tag</h3>
        <form method="POST">
            <input type="hidden" name="action" value="create">

            <div class="form-group">
                <label for="name">Tag Name</label>
                <input type="text" id="name" name="name" required placeholder="e.g., verified, needs-review">
            </div>

            <div class="form-group">
                <label for="color">Color</label>
                <div class="color-input-group">
                    <input type="color" id="color" name="color" value="#808080">
                    <input type="text" id="color-hex" value="#808080" pattern="^#[0-9A-Fa-f]{6}$" style="width: 100px;">
                </div>
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <input type="text" id="description" name="description" placeholder="Brief description of when to use this tag">
            </div>

            <button type="submit" class="btn">Create Tag</button>
        </form>
    </div>

    <!-- Tags List -->
    <div class="card">
        <h3>Existing Tags</h3>
        <?php if (empty($tags)): ?>
            <p style="color: var(--text-muted); text-align: center; padding: 20px;">No tags created yet.</p>
        <?php else: ?>
            <div class="tags-list">
                <?php foreach ($tags as $tag): ?>
                    <div class="tag-item" id="tag-<?= $tag['id'] ?>">
                        <form method="POST" class="tag-edit-form">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?= $tag['id'] ?>">

                            <div class="tag-preview">
                                <span class="tag-badge" style="background-color: <?= e($tag['color']) ?>;">
                                    <?= e($tag['name']) ?>
                                </span>
                                <span class="tag-usage"><?= $tag['usage_count'] ?> reports</span>
                            </div>

                            <div class="tag-edit-fields" style="display: none;">
                                <input type="text" name="name" value="<?= e($tag['name']) ?>" required>
                                <input type="color" name="color" value="<?= e($tag['color']) ?>">
                                <input type="text" name="description" value="<?= e($tag['description'] ?? '') ?>" placeholder="Description">
                            </div>

                            <div class="tag-description">
                                <?= e($tag['description'] ?: 'No description') ?>
                            </div>

                            <div class="tag-actions">
                                <button type="button" class="btn btn-sm btn-secondary edit-btn" onclick="toggleTagEdit(<?= $tag['id'] ?>)">Edit</button>
                                <button type="submit" class="btn btn-sm save-btn" style="display: none;">Save</button>
                                <button type="button" class="btn btn-sm btn-secondary cancel-btn" style="display: none;" onclick="toggleTagEdit(<?= $tag['id'] ?>)">Cancel</button>
                            </div>
                        </form>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this tag? This will remove it from all reports.');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $tag['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger delete-btn">Delete</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.tags-layout {
    display: grid;
    grid-template-columns: 350px 1fr;
    gap: 20px;
}

@media (max-width: 900px) {
    .tags-layout {
        grid-template-columns: 1fr;
    }
}

.color-input-group {
    display: flex;
    gap: 10px;
    align-items: center;
}

.color-input-group input[type="color"] {
    width: 50px;
    height: 36px;
    padding: 0;
    border: 1px solid var(--border);
    cursor: pointer;
}

.tags-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.tag-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: var(--bg-dark);
    border-radius: 6px;
    border: 1px solid var(--border);
    flex-wrap: wrap;
}

.tag-preview {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 150px;
}

.tag-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: bold;
    color: #fff;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
}

.tag-usage {
    font-size: 11px;
    color: var(--text-muted);
}

.tag-description {
    flex: 1;
    font-size: 13px;
    color: var(--text-muted);
    min-width: 200px;
}

.tag-edit-fields {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.tag-edit-fields input[type="text"] {
    width: 150px;
}

.tag-edit-fields input[type="color"] {
    width: 40px;
    height: 30px;
    padding: 0;
    border: 1px solid var(--border);
}

.tag-actions {
    display: flex;
    gap: 6px;
}

.tag-item.editing .tag-preview,
.tag-item.editing .tag-description {
    display: none;
}

.tag-item.editing .tag-edit-fields,
.tag-item.editing .save-btn,
.tag-item.editing .cancel-btn {
    display: flex !important;
}

.tag-item.editing .edit-btn,
.tag-item.editing .delete-btn {
    display: none !important;
}
</style>

<script>
// Sync color picker with hex input
document.getElementById('color').addEventListener('input', function() {
    document.getElementById('color-hex').value = this.value;
});
document.getElementById('color-hex').addEventListener('input', function() {
    if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
        document.getElementById('color').value = this.value;
    }
});

function toggleTagEdit(tagId) {
    var item = document.getElementById('tag-' + tagId);
    item.classList.toggle('editing');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
