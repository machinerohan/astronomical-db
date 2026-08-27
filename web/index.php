<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$page = $_GET['page'] ?? 'home';
$action = $_POST['action'] ?? null;
$category_slug = $_GET['cat'] ?? null;
$thread_id = filter_input(INPUT_GET, 'thread', FILTER_VALIDATE_INT);
$user_id = filter_input(INPUT_GET, 'user', FILTER_VALIDATE_INT);

// === AUTH ACTIONS ===

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

// === FORUM ACTIONS ===

if ($action === 'create_thread') {
    $user = require_user();
    verify_csrf();
    $catId = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
    $type = $_POST['thread_type'] ?? 'discussion';
    $title = trim((string) ($_POST['title'] ?? ''));
    $body = trim((string) ($_POST['body'] ?? ''));
    
    if (!$catId || !in_array($type, ['discussion', 'identification', 'proposal'])) {
        flash('error', 'Invalid thread type or category.');
    } elseif (strlen($title) < 3) {
        flash('error', 'Title must be at least 3 characters.');
    } elseif (strlen($body) < 5) {
        flash('error', 'Message must be at least 5 characters.');
    } else {
        try {
            $threadId = create_thread($catId, $user['id'], $type, $title, $body);
            flash('success', 'Thread created!');
            redirect("thread=$threadId");
        } catch (Exception $e) {
            flash('error', 'Could not create thread.');
        }
    }
    redirect($category_slug ? "forum&cat=$category_slug" : 'forum');
}

if ($action === 'create_post') {
    $user = require_user();
    verify_csrf();
    $tId = filter_input(INPUT_POST, 'thread_id', FILTER_VALIDATE_INT);
    $body = trim((string) ($_POST['body'] ?? ''));
    
    if (!$tId) {
        flash('error', 'Invalid thread.');
    } elseif (strlen($body) < 3) {
        flash('error', 'Post must be at least 3 characters.');
    } else {
        try {
            create_post($tId, $user['id'], $body);
            flash('success', 'Post added!');
        } catch (Exception $e) {
            flash('error', 'Could not create post.');
        }
    }
    redirect("thread=$tId");
}

if ($action === 'approve_proposal') {
    $expert = require_expert();
    verify_csrf();
    $propId = filter_input(INPUT_POST, 'proposal_id', FILTER_VALIDATE_INT);
    
    if (!$propId) {
        flash('error', 'Invalid proposal.');
    } else {
        try {
            approve_proposal($propId, $expert['id']);
            flash('success', 'Proposal approved!');
        } catch (Exception $e) {
            flash('error', 'Could not approve proposal.');
        }
    }
    redirect('approvals');
}

if ($action === 'reject_proposal') {
    $expert = require_expert();
    verify_csrf();
    $propId = filter_input(INPUT_POST, 'proposal_id', FILTER_VALIDATE_INT);
    $reason = trim((string) ($_POST['reason'] ?? ''));
    
    if (!$propId || strlen($reason) < 3) {
        flash('error', 'Invalid proposal or reason required.');
    } else {
        try {
            reject_proposal($propId, $expert['id'], $reason);
            flash('success', 'Proposal rejected.');
        } catch (Exception $e) {
            flash('error', 'Could not reject proposal.');
        }
    }
    redirect('approvals');
}

// === LOAD DATA ===

$user = current_user();
$flash = take_flash();
$categories = get_categories();
$pending = [];

if ($user && $user['role'] === 'admin') {
    $pending = db()->query('SELECT id, username, created_at FROM users WHERE registration_status = "pending" ORDER BY created_at')->fetchAll();
}

function page_title(string $page, ?string $extra = null): string
{
    return match ($page) {
        'register' => 'Join the catalogue',
        'login' => 'Welcome back',
        'dashboard' => 'Your profile',
        'profile' => $extra ? "Profile: $extra" : 'User profile',
        'admin' => 'Registration desk',
        'forum' => 'Forum',
        'thread' => 'Discussion',
        'approvals' => 'Review proposals',
        default => 'AstroForum',
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
            <a href="index.php?page=forum">Forum</a>
            <?php if ($user): ?>
                <a href="index.php?page=dashboard">Dashboard</a>
                <?php if ($user['role'] === 'admin'): ?><a href="index.php?page=admin">Admin<?php if ($pending): ?><b class="count"><?= count($pending) ?></b><?php endif; ?></a><?php endif; ?>
                <?php if ($user['expertise'] !== 'normal' || $user['role'] === 'admin'): ?><a href="index.php?page=approvals">Approvals</a><?php endif; ?>
                <form method="post" class="inline-form"><input type="hidden" name="action" value="logout"><?= csrf_field() ?><button class="nav-button" type="submit">Log out</button></form>
            <?php else: ?>
                <a href="index.php?page=login">Log in</a><a class="nav-cta" href="index.php?page=register">Register</a>
            <?php endif; ?>
        </nav>
    </header>

    <main class="shell">
        <?php if ($flash): ?><div class="notice <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

        <!-- REGISTER -->
        <?php if ($page === 'register'): ?>
            <section class="auth-layout"><div class="auth-intro"><p class="eyebrow">Create an account</p><h1>Join the discussion.</h1><p>Register to ask questions, browse the catalogue, and contribute answers.</p><div class="signal-list"><span>01</span>Admin approval required</div><div class="signal-list"><span>02</span>One account per user</div></div><div class="form-panel"><p class="eyebrow">Registration</p><h2>Create your account</h2><form method="post" class="stack"><input type="hidden" name="action" value="register"><?= csrf_field() ?><label>Username<input name="username" autocomplete="username" required minlength="3" maxlength="64"></label><label>Password<input type="password" name="password" autocomplete="new-password" required minlength="8"><small>At least 8 characters.</small></label><label>Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" required minlength="8"></label><button class="primary-button" type="submit">Register <span>→</span></button></form><p class="form-foot">Already registered? <a href="index.php?page=login">Log in</a></p></div></section>

        <!-- LOGIN -->
        <?php elseif ($page === 'login'): ?>
            <section class="auth-layout compact"><div class="auth-intro"><p class="eyebrow">Account access</p><h1>Log in to AstroForum.</h1><p>Sign in to manage your account and take part in the community.</p></div><div class="form-panel"><p class="eyebrow">Log in</p><h2>Account login</h2><form method="post" class="stack"><input type="hidden" name="action" value="login"><?= csrf_field() ?><label>Username<input name="username" autocomplete="username" required></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button class="primary-button" type="submit">Log in <span>→</span></button></form><p class="form-foot">Need an account? <a href="index.php?page=register">Register here</a></p></div></section>

        <!-- DASHBOARD -->
        <?php elseif ($page === 'dashboard'): 
            $member = require_user(); 
            $stats = get_user_stats($member['id']);
        ?>
            <section class="page-heading"><p class="eyebrow">Your profile</p><h1><?= e($member['username']) ?></h1><p>Account status and membership details.</p></section>
            <section class="dashboard-grid">
                <div class="stat-panel"><span class="stat-label">Expertise</span><strong><?= e(ucfirst($member['expertise'])) ?></strong><p><?= $member['is_restricted'] ? 'Restricted' : 'Good standing' ?></p></div>
                <div class="stat-panel"><span class="stat-label">Posts</span><strong><?= $stats['posts'] ?></strong><p>Total discussion posts</p></div>
                <div class="stat-panel"><span class="stat-label">Proposals</span><strong><?= $stats['proposals'] ?></strong><p>Total proposals submitted</p></div>
                <div class="stat-panel"><span class="stat-label">Approvals</span><strong><?= $stats['approvals'] ?></strong><p>Proposals you've approved</p></div>
                <div class="profile-panel"><span class="stat-label">Account details</span>
                    <dl>
                        <div><dt>Username</dt><dd><?= e($member['username']) ?></dd></div>
                        <div><dt>Role</dt><dd><?= e(ucfirst($member['role'])) ?></dd></div>
                        <div><dt>Member since</dt><dd><?= e(date('M j, Y', strtotime($member['created_at']))) ?></dd></div>
                        <div><dt>Status</dt><dd class="status-active"><?= e(ucfirst($member['registration_status'])) ?></dd></div>
                    </dl>
                </div>
            </section>

        <!-- ADMIN DESK -->
        <?php elseif ($page === 'admin'): 
            $admin = require_admin(); 
        ?>
            <section class="page-heading"><p class="eyebrow">Admin only</p><h1>Registration desk</h1><p>Review new observers before they enter the discussion.</p></section>
            <section class="queue-panel"><div class="queue-head"><div><span class="stat-label">Awaiting review</span><h2><?= count($pending) ?> registration<?= count($pending) === 1 ? '' : 's' ?></h2></div><span class="queue-icon">◎</span></div>
            <?php if (!$pending): ?>
                <div class="empty-state">The queue is clear. New registrations will appear here.</div>
            <?php else: ?>
                <div class="user-list">
                    <?php foreach ($pending as $candidate): ?>
                        <div class="user-row"><div><strong><?= e($candidate['username']) ?></strong><span>Submitted <?= e(date('M j, Y · g:i a', strtotime($candidate['created_at']))) ?></span></div><div class="row-actions"><form method="post"><input type="hidden" name="action" value="approve"><input type="hidden" name="user_id" value="<?= e((string) $candidate['id']) ?>"><?= csrf_field() ?><button class="approve-button" type="submit">Approve</button></form><form method="post"><input type="hidden" name="action" value="reject"><input type="hidden" name="user_id" value="<?= e((string) $candidate['id']) ?>"><?= csrf_field() ?><button class="reject-button" type="submit">Reject</button></form></div></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </section>

        <!-- FORUM LISTING -->
        <?php elseif ($page === 'forum') {
            if ($category_slug) {
                $category = get_category_by_slug($category_slug);
                if (!$category) {
                    redirect('forum');
                }
                $threads = get_threads_by_category($category['id']);
        ?>
            <section class="page-heading"><p class="eyebrow"><?= e($category['object_type'] ?? 'General') ?></p><h1><?= e($category['name']) ?></h1><p><?= e($category['description']) ?></p></section>
            <?php if ($user): ?>
                <section style="margin-bottom: 30px;"><form method="post" class="stack" style="max-width: 600px;"><input type="hidden" name="action" value="create_thread"><input type="hidden" name="category_id" value="<?= $category['id'] ?>"><?= csrf_field() ?>
                <label>Thread title<input name="title" required minlength="3" placeholder="Ask a question or start a discussion..."></label>
                <label>Thread type
                    <select name="thread_type" required>
                        <option value="discussion">Discussion</option>
                        <option value="identification">Help identifying object</option>
                        <option value="proposal">Propose new object</option>
                    </select>
                </label>
                <label>Message<textarea name="body" required minlength="5" rows="4" placeholder="Describe your question or discussion..."></textarea></label>
                <button class="primary-button" type="submit">Create thread <span>→</span></button>
                </form></section>
            <?php endif; ?>
            <section><h2>Threads</h2>
            <?php if (!$threads): ?>
                <p class="empty-state">No threads yet in this category. Be the first to post!</p>
            <?php else: ?>
                <div class="thread-list">
                    <?php foreach ($threads as $t): ?>
                        <div class="thread-row">
                            <div class="thread-info">
                                <a href="index.php?page=thread&thread=<?= $t['id'] ?>"><strong><?= e($t['title']) ?></strong></a>
                                <span class="thread-meta">by <?= e($t['username']) ?> · <?= $t['post_count'] ?> posts · <?= e(date('M j · g:i a', strtotime($t['last_post'] ?? $t['created_at']))) ?></span>
                            </div>
                            <span class="thread-type"><?= ucfirst($t['type']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </section>
        <?php } else { 
            // Show all categories
        ?>
            <section class="hero"><div class="hero-copy"><p class="eyebrow">Astronomy questions</p><h1>Ask questions. Share what you know.</h1><p class="hero-text">A place to discuss astronomical objects and keep useful answers in one catalogue.</p>
            <?php if (!$user): ?>
                <div class="hero-actions"><a class="primary-button" href="index.php?page=register">Create an account <span>→</span></a><a class="text-link" href="index.php?page=login">Log in</a></div>
            <?php endif; ?>
            </div></section>
            <section class="catalogue-preview"><div class="section-head"><div><p class="eyebrow">Discussion categories</p><h2>Browse by topic</h2></div></div>
            <div class="category-grid">
                <?php foreach ($categories as $cat): 
                    $thread_count = db()->prepare('SELECT COUNT(*) FROM threads WHERE category_id = ?');
                    $thread_count->execute([$cat['id']]);
                    $count = $thread_count->fetchColumn();
                ?>
                    <a href="index.php?page=forum&cat=<?= e($cat['slug']) ?>" class="category-card">
                        <span class="category-type"><?= e($cat['object_type'] ?? 'General') ?></span>
                        <h3><?= e($cat['name']) ?></h3>
                        <p><?= $count ?> thread<?= $count !== 1 ? 's' : '' ?></p>
                    </a>
                <?php endforeach; ?>
            </div>
            </section>
        <?php } } ?>

        <!-- THREAD VIEW -->
        <?php elseif ($page === 'thread' && $thread_id) { 
            $thread = get_thread_by_id($thread_id);
            if (!$thread) {
                $page = 'home';
            } else {
                $posts = get_posts_for_thread($thread_id);
        ?>
            <section class="page-heading">
                <p class="eyebrow"><a href="index.php?page=forum&cat=<?= e($thread['category_slug']) ?>"><?= e($thread['category_name']) ?></a> / <?= ucfirst($thread['type']) ?></p>
                <h1><?= e($thread['title']) ?></h1>
                <p><?= e($thread['username']) ?> · <span class="username-badge"><?= ucfirst($thread['expertise']) ?></span></p>
            </section>

            <section class="thread-posts">
                <?php foreach ($posts as $post): ?>
                    <article class="post">
                        <div class="post-header">
                            <a href="index.php?page=profile&user=<?= $post['author_id'] ?>" class="post-author"><?= e($post['username']) ?></a>
                            <span class="post-badge"><?= ucfirst($post['expertise']) ?></span>
                            <?php if ($post['author_id'] == $thread['author_id']): ?>
                                <span class="post-badge op">OP</span>
                            <?php endif; ?>
                            <span class="post-date"><?= e(date('M j, Y · g:i a', strtotime($post['created_at']))) ?></span>
                        </div>
                        <div class="post-body"><?= e($post['body']) ?></div>
                    </article>
                <?php endforeach; ?>
            </section>

            <?php if ($user && $thread['status'] === 'open'): ?>
                <section style="margin-top: 30px;"><h2>Reply to this thread</h2>
                <form method="post" class="stack" style="max-width: 700px;">
                    <input type="hidden" name="action" value="create_post">
                    <input type="hidden" name="thread_id" value="<?= $thread_id ?>">
                    <?= csrf_field() ?>
                    <label>Your response<textarea name="body" required minlength="3" rows="4" placeholder="Add to the discussion..."></textarea></label>
                    <button class="primary-button" type="submit">Post reply <span>→</span></button>
                </form>
                </section>
            <?php endif; ?>

        <?php } } ?>

        <!-- APPROVALS DASHBOARD -->
        <?php elseif ($page === 'approvals'): 
            $user = require_expert();
            $proposals = get_pending_proposals(50);
        ?>
            <section class="page-heading"><p class="eyebrow">Expert tools</p><h1>Review proposals</h1><p>Approve or reject pending proposals for the catalogue.</p></section>
            <section>
            <?php if (!$proposals): ?>
                <p class="empty-state">No pending proposals. Great work!</p>
            <?php else: ?>
                <div class="proposals-list">
                    <?php foreach ($proposals as $prop): ?>
                        <div class="proposal-card">
                            <div class="proposal-header">
                                <h3><?= ucfirst($prop['type']) ?></h3>
                                <span class="proposal-user">by <?= e($prop['username']) ?></span>
                            </div>
                            <p class="proposal-context">In: <a href="index.php?page=forum&cat=<?= e($prop['category_slug']) ?>"><?= e($prop['title']) ?></a></p>
                            <?php if ($prop['type'] === 'edit_field'): ?>
                                <p><strong><?= e($prop['field']) ?>:</strong> <?= e($prop['new_value']) ?></p>
                            <?php endif; ?>
                            <span class="proposal-date"><?= e(date('M j, Y · g:i a', strtotime($prop['created_at']))) ?></span>
                            <div class="proposal-actions">
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="approve_proposal">
                                    <input type="hidden" name="proposal_id" value="<?= $prop['id'] ?>">
                                    <?= csrf_field() ?>
                                    <button class="approve-button" type="submit">Approve</button>
                                </form>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="reject_proposal">
                                    <input type="hidden" name="proposal_id" value="<?= $prop['id'] ?>">
                                    <?= csrf_field() ?>
                                    <input type="text" name="reason" placeholder="Reason..." required minlength="3" style="width: 200px; padding: 8px; margin-right: 5px;">
                                    <button class="reject-button" type="submit">Reject</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </section>

        <!-- HOME PAGE -->
        <?php else: 
            $objects = db()->query('SELECT name, catalog_id, object_type, constellation, distance_ly FROM objects WHERE status = "active" ORDER BY id LIMIT 6')->fetchAll();
        ?>
            <section class="hero"><div class="hero-copy"><p class="eyebrow">Astronomy questions</p><h1>Ask questions. Share what you know.</h1><p class="hero-text">A place to discuss astronomical objects and keep useful answers in one catalogue.</p>
            <?php if (!$user): ?>
                <div class="hero-actions"><a class="primary-button" href="index.php?page=register">Create an account <span>→</span></a><a class="text-link" href="index.php?page=login">Log in</a></div>
            <?php else: ?>
                <div class="hero-actions"><a class="primary-button" href="index.php?page=forum">Browse discussions <span>→</span></a></div>
            <?php endif; ?>
            </div><div class="hero-coordinate"><span>OBJECTS IN CATALOGUE</span><strong><?= count($objects) ?></strong><small>active objects available to browse</small></div></section>
            <section class="catalogue-preview"><div class="section-head"><div><p class="eyebrow">Browse objects</p><h2>Latest catalogue entries</h2></div><span class="section-note">Read-only list</span></div>
            <div class="object-grid">
                <?php foreach ($objects as $object): ?>
                    <article class="object-card"><span class="object-type"><?= e(strtoupper($object['object_type'])) ?></span><h3><?= e($object['name']) ?></h3><p><?= e($object['catalog_id']) ?> · <?= e($object['constellation']) ?></p><small><?= e((string) $object['distance_ly']) ?> ly</small></article>
                <?php endforeach; ?>
            </div>
            </section>
        <?php endif; ?>
    </main>
    <footer><span>ASTROFORUM</span><span>Astronomy questions and objects</span></footer>
</body>
</html>