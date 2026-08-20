<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$page = $_GET['page'] ?? 'home';
$action = $_POST['action'] ?? null;

if ($action === 'register') {
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_]{3,64}$/', $username)) {
        flash('error', 'Use 3-64 letters, numbers, or underscores for your username.');
    } elseif (strlen($password) < 8) {
        flash('error', 'Your password must be at least 8 characters.');
    } elseif ($password !== $confirmation) {
        flash('error', 'The password confirmation does not match.');
    } else {
        try {
            $statement = db()->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
            $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            flash('success', 'Registration received. An administrator must approve your account before you can log in.');
        } catch (PDOException $exception) {
            flash('error', $exception->getCode() === '23000' ? 'That username is already taken.' : 'Registration could not be completed.');
        }
    }
    redirect('register');
}

if ($action === 'login') {
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $statement = db()->prepare('SELECT * FROM users WHERE username = ?');
    $statement->execute([$username]);
    $account = $statement->fetch();

    if (!$account || !password_verify($password, $account['password_hash'])) {
        flash('error', 'The username or password is incorrect.');
    } elseif ($account['registration_status'] === 'pending') {
        flash('error', 'Your registration is waiting for administrator approval.');
    } elseif ($account['registration_status'] === 'rejected') {
        flash('error', 'This registration was rejected. Please contact an administrator.');
    } else {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $account['id'];
        flash('success', 'Welcome back, ' . $account['username'] . '.');
        redirect('dashboard');
    }
    redirect('login');
}

if ($action === 'logout') {
    verify_csrf();
    $_SESSION = [];
    session_destroy();
    header('Location: index.php?page=home');
    exit;
}

if ($action === 'approve' || $action === 'reject') {
    $admin = require_admin();
    verify_csrf();
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $status = $action === 'approve' ? 'active' : 'rejected';
    $statement = db()->prepare('UPDATE users SET registration_status = ?, approved_by = ? WHERE id = ? AND registration_status = "pending"');
    $statement->execute([$status, $action === 'approve' ? $admin['id'] : null, $userId]);
    flash('success', $action === 'approve' ? 'Registration approved.' : 'Registration rejected.');
    redirect('admin');
}

$user = current_user();
$flash = take_flash();
$objects = db()->query('SELECT name, catalog_id, object_type, constellation, distance_ly FROM objects WHERE status = "active" ORDER BY id')->fetchAll();
$pending = [];
if ($user && $user['role'] === 'admin') {
    $pending = db()->query('SELECT id, username, created_at FROM users WHERE registration_status = "pending" ORDER BY created_at')->fetchAll();
}

function page_title(string $page): string
{
    return match ($page) {
        'register' => 'Join the catalogue',
        'login' => 'Welcome back',
        'dashboard' => 'Your profile',
        'admin' => 'Registration desk',
        default => 'Questions',
    };
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(page_title($page)) ?> | AstroForum</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header class="topbar">
        <a class="brand" href="index.php"><span class="brand-mark">✦</span><span>Astro<span>Forum</span></span></a>
        <nav>
            <?php if ($user): ?>
                <a href="index.php?page=dashboard">Dashboard</a>
                <?php if ($user['role'] === 'admin'): ?><a href="index.php?page=admin">Admin desk<?php if ($pending): ?><b class="count"><?= count($pending) ?></b><?php endif; ?></a><?php endif; ?>
                <form method="post" class="inline-form"><input type="hidden" name="action" value="logout"><?= csrf_field() ?><button class="nav-button" type="submit">Log out</button></form>
            <?php else: ?>
                <a href="index.php?page=login">Log in</a><a class="nav-cta" href="index.php?page=register">Register</a>
            <?php endif; ?>
        </nav>
    </header>

    <main class="shell">
        <?php if ($flash): ?><div class="notice <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

        <?php if ($page === 'register'): ?>
            <section class="auth-layout"><div class="auth-intro"><p class="eyebrow">Create an account</p><h1>Join the discussion.</h1><p>Register to ask questions, browse the catalogue, and contribute answers.</p><div class="signal-list"><span>01</span>Admin approval is required</div><div class="signal-list"><span>02</span>Use one account for your posts</div></div><div class="form-panel"><p class="eyebrow">Registration</p><h2>Create your account</h2><form method="post" class="stack"><input type="hidden" name="action" value="register"><?= csrf_field() ?><label>Username<input name="username" autocomplete="username" required minlength="3" maxlength="64"></label><label>Password<input type="password" name="password" autocomplete="new-password" required minlength="8"><small>At least 8 characters.</small></label><label>Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" required minlength="8"></label><button class="primary-button" type="submit">Register <span>→</span></button></form><p class="form-foot">Already registered? <a href="index.php?page=login">Log in</a></p></div></section>
        <?php elseif ($page === 'login'): ?>
            <section class="auth-layout compact"><div class="auth-intro"><p class="eyebrow">Account access</p><h1>Log in to AstroForum.</h1><p>Sign in to manage your account and take part in the community.</p></div><div class="form-panel"><p class="eyebrow">Log in</p><h2>Account login</h2><form method="post" class="stack"><input type="hidden" name="action" value="login"><?= csrf_field() ?><label>Username<input name="username" autocomplete="username" required></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button class="primary-button" type="submit">Log in <span>→</span></button></form><p class="form-foot">Need an account? <a href="index.php?page=register">Register here</a></p></div></section>
        <?php elseif ($page === 'dashboard'): $member = require_user(); ?>
            <section class="page-heading"><p class="eyebrow">Your profile</p><h1><?= e($member['username']) ?></h1><p>Account status and membership details.</p></section><section class="dashboard-grid"><div class="stat-panel"><span class="stat-label">Expertise</span><strong><?= e(ucfirst($member['expertise'])) ?></strong><p><?= $member['is_restricted'] ? 'Your account is currently restricted.' : 'Your account is in good standing.' ?></p></div><div class="stat-panel"><span class="stat-label">Member since</span><strong><?= e(date('M Y', strtotime($member['created_at']))) ?></strong><p>Account creation date.</p></div><div class="profile-panel"><span class="stat-label">Account details</span><dl><div><dt>Username</dt><dd><?= e($member['username']) ?></dd></div><div><dt>Role</dt><dd><?= e(ucfirst($member['role'])) ?></dd></div><div><dt>Registration</dt><dd class="status-active"><?= e(ucfirst($member['registration_status'])) ?></dd></div></dl></div></section>
        <?php elseif ($page === 'admin'): $admin = require_admin(); ?>
            <section class="page-heading"><p class="eyebrow">Admin only</p><h1>Registration desk</h1><p>Review new observers before they enter the discussion.</p></section><section class="queue-panel"><div class="queue-head"><div><span class="stat-label">Awaiting review</span><h2><?= count($pending) ?> registration<?= count($pending) === 1 ? '' : 's' ?></h2></div><span class="queue-icon">◎</span></div><?php if (!$pending): ?><div class="empty-state">The queue is clear. New registrations will appear here.</div><?php else: ?><div class="user-list"><?php foreach ($pending as $candidate): ?><div class="user-row"><div><strong><?= e($candidate['username']) ?></strong><span>Submitted <?= e(date('M j, Y · g:i a', strtotime($candidate['created_at']))) ?></span></div><div class="row-actions"><form method="post"><input type="hidden" name="action" value="approve"><input type="hidden" name="user_id" value="<?= e((string) $candidate['id']) ?>"><?= csrf_field() ?><button class="approve-button" type="submit">Approve</button></form><form method="post"><input type="hidden" name="action" value="reject"><input type="hidden" name="user_id" value="<?= e((string) $candidate['id']) ?>"><?= csrf_field() ?><button class="reject-button" type="submit">Reject</button></form></div></div><?php endforeach; ?></div><?php endif; ?></section>
        <?php else: ?>
            <section class="hero"><div class="hero-copy"><p class="eyebrow">Astronomy questions</p><h1>Ask questions. Share what you know.</h1><p class="hero-text">A place to discuss astronomical objects and keep useful answers in one catalogue.</p><div class="hero-actions"><a class="primary-button" href="index.php?page=register">Create an account <span>→</span></a><a class="text-link" href="index.php?page=login">Log in</a></div></div><div class="hero-coordinate"><span>OBJECTS IN CATALOGUE</span><strong><?= count($objects) ?></strong><small>active objects available to browse</small></div></section><section class="catalogue-preview"><div class="section-head"><div><p class="eyebrow">Browse objects</p><h2>Latest catalogue entries</h2></div><span class="section-note">Read-only list</span></div><div class="object-grid"><?php foreach ($objects as $object): ?><article class="object-card"><span class="object-type"><?= e(strtoupper($object['object_type'])) ?></span><h3><?= e($object['name']) ?></h3><p><?= e($object['catalog_id']) ?> · <?= e($object['constellation']) ?></p><small><?= e((string) $object['distance_ly']) ?> light years</small></article><?php endforeach; ?></div></section>
        <?php endif; ?>
    </main>
    <footer><span>ASTROFORUM</span><span>Astronomy questions and objects</span></footer>
</body>
</html>