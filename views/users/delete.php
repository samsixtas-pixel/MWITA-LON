<?php
/**
 * views/users/delete.php — remove a staff account (administrator only).
 *
 * Destructive, so it only accepts POST with a valid CSRF token. The
 * account list uses a confirmation modal; this endpoint no longer
 * accepts a plain link.
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireAdmin();

if (!isPost()) {
    flash('error', t('Use the account list to remove a user.'));
    redirect(BASE_URL . 'views/users/index.php');
}

$id     = (int) ($_POST['user_id'] ?? 0);
$target = $id > 0 ? (new User())->find($id) : false;

if (!$target) {
    flash('error', t('User not found.'));
    redirect(BASE_URL . 'views/users/index.php');
}

if ($id === currentUserId()) {
    flash('error', t('You cannot delete your own account.'));
    redirect(BASE_URL . 'views/users/index.php');
}

if ($target['role'] === 'admin' && $target['status'] === 'active') {
    $remaining = (int) Database::getConnection()
        ->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id != " . (int) $id)
        ->fetchColumn();

    if ($remaining === 0) {
        flash('error', t('At least one active administrator must remain.'));
        redirect(BASE_URL . 'views/users/index.php');
    }
}

(new User())->delete($id);
AuditLog::record('user.delete', 'users', $id, 'Deleted user ' . $target['username']);

flash('success', t('User deleted.'));
redirect(BASE_URL . 'views/users/index.php');
