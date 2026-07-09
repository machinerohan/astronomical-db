<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$page_title = 'Create User';
require_once __DIR__ . '/../includes/header.php';

$user = current_user($pdo);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $error = 'Username and password are required.';
    } elseif (strlen($password) < 4) {
        $error = 'Password must be at least 4 characters.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $error = 'Username already taken.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
            $stmt->execute([$username, $hash]);
            $new_id = $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO admin_actions (admin_id, action, target_type, target_id) VALUES (?, 'create_user', 'user', ?)")
                ->execute([$user['id'], $new_id]);

            $success = "User '$username' created. Initial password: $password (share out of band).";
        }
    }
}
?>
<h1>Create User</h1>
<?php if ($error): ?><p style="color:red"><?= h($error) ?></p><?php endif; ?>
<?php if ($success): ?><p style="color:green"><?= h($success) ?></p><?php endif; ?>
<form method="post">
<p><label>Username: <br><input type="text" name="username" required></label></p>
<p><label>Password: <br><input type="text" name="password" required></label></p>
<p><input type="submit" value="Create User"></p>
</form>
<p><a href="users.php">&larr; Back to user list</a></p>
<?php
require_once __DIR__ . '/../includes/footer.php';
