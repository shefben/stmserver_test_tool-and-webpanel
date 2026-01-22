<?php
/**
 * Notifications page - shows user's notifications
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance();
$user = getCurrentUser();

if (!isset($user['id'])) {
    setFlash('error', 'User session invalid');
    header('Location: ?page=dashboard');
    exit;
}

// Handle mark as read action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $notificationId = (int)($_POST['notification_id'] ?? 0);
        if ($notificationId) {
            $db->markNotificationRead($notificationId);
        }
    } elseif ($action === 'mark_all_read') {
        $db->markAllNotificationsRead($user['id']);
        setFlash('success', 'All notifications marked as read.');
    }

    header('Location: ?page=notifications');
    exit;
}

// Get notifications
$notifications = $db->getUserNotifications($user['id']);
$unreadCount = $db->getUnreadNotificationCount($user['id']);
?>

<div class="report-header">
    <div>
        <h1 class="page-title">Notifications</h1>
        <p style="color: var(--text-muted);">
            <?php if ($unreadCount > 0): ?>
                You have <?= $unreadCount ?> unread notification<?= $unreadCount > 1 ? 's' : '' ?>
            <?php else: ?>
                You're all caught up!
            <?php endif; ?>
        </p>
    </div>
    <div>
        <?php if ($unreadCount > 0): ?>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn btn-secondary">Mark All as Read</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?= renderFlash() ?>

<div class="card">
    <?php if (empty($notifications)): ?>
        <div class="empty-state">
            <div class="empty-icon">🔔</div>
            <p>No notifications yet.</p>
            <p style="color: var(--text-muted); font-size: 13px;">
                You'll be notified when an admin flags your tests for retest.
            </p>
        </div>
    <?php else: ?>
        <div class="notification-list">
            <?php foreach ($notifications as $notification): ?>
                <div class="notification-item <?= $notification['is_read'] ? 'read' : 'unread' ?>">
                    <div class="notification-icon">
                        <?php if ($notification['type'] === 'retest'): ?>
                            <span title="Retest Request">🔄</span>
                        <?php elseif ($notification['type'] === 'fixed'): ?>
                            <span title="Fix Notification">✅</span>
                        <?php else: ?>
                            <span title="Information">ℹ️</span>
                        <?php endif; ?>
                    </div>
                    <div class="notification-content">
                        <div class="notification-title"><?= e($notification['title']) ?></div>
                        <div class="notification-message"><?= nl2br(e($notification['message'])) ?></div>
                        <?php if (!empty($notification['notes'])): ?>
                            <div class="notification-notes">
                                <strong>Admin Notes:</strong>
                                <?= nl2br(e($notification['notes'])) ?>
                            </div>
                        <?php endif; ?>
                        <div class="notification-meta">
                            <span><?= formatRelativeTime($notification['created_at']) ?></span>
                            <?php if (!empty($notification['report_id'])): ?>
                                <a href="?page=report_detail&id=<?= $notification['report_id'] ?>" class="notification-link">
                                    View Report #<?= $notification['report_id'] ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="notification-actions">
                        <?php if (!$notification['is_read']): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-secondary" title="Mark as read">✓</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
}

.empty-icon {
    font-size: 48px;
    margin-bottom: 20px;
    opacity: 0.5;
}

.notification-list {
    display: flex;
    flex-direction: column;
}

.notification-item {
    display: flex;
    gap: 15px;
    padding: 15px;
    border-bottom: 1px solid var(--border);
    transition: background 0.2s;
}

.notification-item:last-child {
    border-bottom: none;
}

.notification-item:hover {
    background: var(--bg-accent);
}

.notification-item.unread {
    background: rgba(196, 181, 80, 0.05);
    border-left: 3px solid var(--primary);
}

.notification-item.read {
    opacity: 0.7;
}

.notification-icon {
    font-size: 24px;
    flex-shrink: 0;
}

.notification-content {
    flex: 1;
    min-width: 0;
}

.notification-title {
    font-weight: 600;
    margin-bottom: 5px;
    color: var(--text);
}

.notification-message {
    font-size: 13px;
    color: var(--text-muted);
    margin-bottom: 8px;
}

.notification-notes {
    background: var(--bg-dark);
    border-left: 3px solid var(--primary);
    padding: 10px 12px;
    margin: 10px 0;
    font-size: 13px;
    border-radius: 0 4px 4px 0;
}

.notification-notes strong {
    display: block;
    margin-bottom: 5px;
    color: var(--primary);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.notification-meta {
    display: flex;
    gap: 15px;
    font-size: 12px;
    color: var(--text-muted);
}

.notification-link {
    color: var(--primary);
    text-decoration: none;
}

.notification-link:hover {
    text-decoration: underline;
}

.notification-actions {
    flex-shrink: 0;
    display: flex;
    align-items: flex-start;
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
