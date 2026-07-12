<?php
require_once __DIR__ . '/includes/init.php';
require_login();

$page_title = 'Change Password';
require_once __DIR__ . '/includes/header.php';

$user = current_user($pdo);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $user['password'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 4) {
        $error = 'New password must be at least 4 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New passwords do not match.';
    } else {
        $hash = password_hash($new, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->execute([$hash, $user['id']]);
        $success = 'Password changed successfully.';
    }
}
?>
<h1>Change Password</h1>
<?php render_flash($error); render_flash($success, 'success'); ?>
<form method="post">
<p><label>Current password: <br><input type="password" name="current_password" required></label></p>
<p><label>New password: <br><input type="password" name="new_password" required></label></p>
<p><label>Confirm new password: <br><input type="password" name="confirm_password" required></label></p>
<p><input type="submit" value="Change Password"></p>
</form>
<?php
require_once __DIR__ . '/includes/footer.php';
