<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$page_title = 'Login';
require_once __DIR__ . '/includes/header.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (login($pdo, $username, $password)) {
        session_write_close();
        $redirect = flash_redirect() ?? 'index.php';
        header("Location: $redirect");
        exit;
    }
    $error = 'Invalid username or password.';
}
?>
<h1>Login</h1>
<?php if ($error): ?><p style="color:red"><?= h($error) ?></p><?php endif; ?>
<form method="post">
<p><label>Username: <br><input type="text" name="username" required></label></p>
<p><label>Password: <br><input type="password" name="password" required></label></p>
<p><input type="submit" value="Login"></p>
</form>
<p>Default accounts: <code>admin</code>/<code>admin</code>, <code>alice</code>/<code>password</code></p>
<?php
require_once __DIR__ . '/includes/footer.php';
