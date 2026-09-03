<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$page = $_GET['page'] ?? 'home';
$action = $_POST['action'] ?? null;
$category_slug = $_GET['cat'] ?? null;
$thread_id = filter_input(INPUT_GET, 'thread', FILTER_VALIDATE_INT);
$user_id = filter_input(INPUT_GET, 'user', FILTER_VALIDATE_INT);

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

if ($action === 'create_add_proposal') {
    $user = require_user();
    verify_csrf();
    $threadId = filter_input(INPUT_POST, 'thread_id', FILTER_VALIDATE_INT);
    $postId = filter_input(INPUT_POST, 'post_id', FILTER_VALIDATE_INT);
    $object = array_map('trim', [
        'name' => (string) ($_POST['name'] ?? ''),
        'object_type' => (string) ($_POST['object_type'] ?? ''),
        'right_ascension' => (string) ($_POST['right_ascension'] ?? ''),
        'declination' => (string) ($_POST['declination'] ?? ''),
        'apparent_mag' => (string) ($_POST['apparent_mag'] ?? ''),
        'constellation' => (string) ($_POST['constellation'] ?? ''),
        'distance_ly' => (string) ($_POST['distance_ly'] ?? ''),
        'discovered_by' => (string) ($_POST['discovered_by'] ?? ''),
        'discovery_year' => (string) ($_POST['discovery_year'] ?? ''),
        'notes' => (string) ($_POST['notes'] ?? ''),
        'image_url' => (string) ($_POST['image_url'] ?? ''),
    ]);
    if (!$threadId || !$postId || $object['name'] === '' || $object['object_type'] === '') {
        flash('error', 'Name, object type, thread, and opening post are required.');
    } else {
        try {
            $imageUrl = $object['image_url'];
            if ($imageUrl !== '' && (!filter_var($imageUrl, FILTER_VALIDATE_URL) || !preg_match('/^https:\/\//i', $imageUrl))) {
                throw new InvalidArgumentException('Image URLs must use HTTPS.');
            }
            $object['image_url'] = $imageUrl;
            create_add_proposal($threadId, $postId, $user['id'], $object);
            flash('success', 'Catalogue proposal submitted for expert review.');
        } catch (Exception $exception) {
            flash('error', $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Could not submit the proposal.');
        }
    }
    redirect("thread=$threadId");
}

if ($action === 'create_edit_proposal') {
    $user = require_user();
    verify_csrf();
    $threadId = filter_input(INPUT_POST, 'thread_id', FILTER_VALIDATE_INT);
    $postId = filter_input(INPUT_POST, 'post_id', FILTER_VALIDATE_INT);
    $objectId = filter_input(INPUT_POST, 'target_object_id', FILTER_VALIDATE_INT);
    $field = (string) ($_POST['field'] ?? '');
    $newValue = trim((string) ($_POST['new_value'] ?? ''));
    try {
        if (!$threadId || !$postId || !$objectId || $newValue === '') {
            throw new RuntimeException('Target object, field, and value are required.');
        }
        $proposalId = create_proposal($threadId, $user['id'], 'edit_field', $field, $newValue, $objectId);
        db()->prepare('UPDATE proposals SET post_id = ? WHERE id = ?')->execute([$postId, $proposalId]);
        flash('success', 'Catalogue edit proposal submitted for expert review.');
    } catch (Exception $exception) {
        flash('error', $exception->getMessage());
    }
    redirect("thread=$threadId");
}

if ($action === 'confirm_identification') {
    $user = require_user();
    verify_csrf();
    $threadId = filter_input(INPUT_POST, 'thread_id', FILTER_VALIDATE_INT);
    $postId = filter_input(INPUT_POST, 'post_id', FILTER_VALIDATE_INT);
    $objectId = filter_input(INPUT_POST, 'object_id', FILTER_VALIDATE_INT);
    $thread = $threadId ? get_thread_by_id($threadId) : null;
    $opening = false;
    if ($threadId && $postId) {
        $openingStatement = db()->prepare('SELECT is_opening FROM posts WHERE id = ? AND thread_id = ?');
        $openingStatement->execute([$postId, $threadId]);
        $opening = (bool) $openingStatement->fetchColumn();
    }
    if (!$thread || $thread['type'] !== 'identification' || (int) $thread['author_id'] !== (int) $user['id'] || !$postId || !$objectId || !get_catalogue_object($objectId) || $opening) {
        flash('error', 'Only the identification thread author can confirm a catalogue match.');
    } else {
        db()->prepare('UPDATE threads SET identified_object_id = ? WHERE id = ?')->execute([$objectId, $threadId]);
        db()->prepare('UPDATE posts SET is_solution = 1, linked_object_id = ? WHERE id = ? AND thread_id = ?')->execute([$objectId, $postId, $threadId]);
        flash('success', 'Identification confirmed.');
    }
    redirect("thread=$threadId");
}

if ($action === 'create_dispute') {
    $user = require_user();
    verify_csrf();
    $proposalId = filter_input(INPUT_POST, 'proposal_id', FILTER_VALIDATE_INT);
    $reason = trim((string) ($_POST['reason'] ?? ''));
    try {
        if (!$proposalId || strlen($reason) < 3) {
            throw new RuntimeException('A reason is required.');
        }
        create_dispute($proposalId, $user['id'], $reason);
        flash('success', 'Dispute submitted for independent review.');
    } catch (Exception $exception) {
        flash('error', $exception->getMessage());
    }
    redirect('profile');
}

if ($action === 'resolve_dispute') {
    $expert = require_expert();
    verify_csrf();
    $disputeId = filter_input(INPUT_POST, 'dispute_id', FILTER_VALIDATE_INT);
    try {
        resolve_dispute($disputeId ?: 0, $expert['id'], ($_POST['decision'] ?? '') === 'approve');
        flash('success', 'Dispute resolved.');
    } catch (Exception $exception) {
        flash('error', $exception->getMessage());
    }
    redirect('disputes');
}

if ($action === 'moderate_user') {
    $admin = require_admin();
    verify_csrf();
    $targetId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $expertise = $_POST['expertise'] ?? 'normal';
    $restricted = !empty($_POST['is_restricted']) ? 1 : 0;
    if ($targetId && in_array($expertise, ['normal', 'expert', 'verified'], true) && $targetId !== (int) $admin['id']) {
        if ($expertise !== 'verified') {
            $restricted = 0;
        }
        db()->prepare('UPDATE users SET expertise = ?, is_restricted = ? WHERE id = ?')->execute([$expertise, $restricted, $targetId]);
        if ($expertise === 'verified') {
            db()->prepare('INSERT INTO verifications (user_id, verified_by_id, note) VALUES (?, ?, ?)')->execute([$targetId, $admin['id'], trim((string) ($_POST['verification_note'] ?? ''))]);
        }
        flash('success', 'User permissions updated.');
    }
    redirect('admin');
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
            flash('error', $e->getMessage());
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

$user = current_user();
$flash = take_flash();
$categories = get_categories();
$pending = [];
$profile = null;
$history = [];
$verification = null;
$disputes = [];
$catalogueObject = null;

if ($user && $user['role'] === 'admin') {
    $pending = db()->query('SELECT id, username, created_at FROM users WHERE registration_status = "pending" ORDER BY created_at')->fetchAll();
}

if ($page === 'profile') {
    $profile = get_user_by_id($user_id ?: ($user['id'] ?? 0));
    if ($profile) {
        $history = get_user_history((int) $profile['id']);
        $verification = get_verification((int) $profile['id']);
    }
}

if ($page === 'disputes') {
    require_expert();
    $disputes = get_pending_disputes();
}

if ($page === 'catalogue') {
    $catalogueObjectId = filter_input(INPUT_GET, 'object', FILTER_VALIDATE_INT);
    $catalogueObject = $catalogueObjectId ? get_catalogue_object($catalogueObjectId) : null;
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
        'disputes' => 'Review disputes',
        'catalogue' => 'Catalogue object',
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
                <?php if ($user['expertise'] !== 'normal' || $user['role'] === 'admin'): ?><a href="index.php?page=disputes">Disputes</a><?php endif; ?>
                <form method="post" class="inline-form"><input type="hidden" name="action" value="logout"><?= csrf_field() ?><button class="nav-button" type="submit">Log out</button></form>
            <?php else: ?>
                <a href="index.php?page=login">Log in</a><a class="nav-cta" href="index.php?page=register">Register</a>
            <?php endif; ?>
        </nav>
    </header>

    <main class="shell">
        <?php if ($flash): ?><div class="notice <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

        <?php if ($page === 'register'): ?>
            <section class="auth-layout"><div class="auth-intro"><p class="eyebrow">Create an account</p><h1>Join the discussion.</h1><p>Register to ask questions, browse the catalogue, and contribute answers.</p><div class="signal-list"><span>01</span>Admin approval required</div><div class="signal-list"><span>02</span>One account per user</div></div><div class="form-panel"><p class="eyebrow">Registration</p><h2>Create your account</h2><form method="post" class="stack"><input type="hidden" name="action" value="register"><?= csrf_field() ?><label>Username<input name="username" autocomplete="username" required minlength="3" maxlength="64"></label><label>Password<input type="password" name="password" autocomplete="new-password" required minlength="8"><small>At least 8 characters.</small></label><label>Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" required minlength="8"></label><button class="primary-button" type="submit">Register <span>→</span></button></form><p class="form-foot">Already registered? <a href="index.php?page=login">Log in</a></p></div></section>

        <?php elseif ($page === 'login'): ?>
            <section class="auth-layout compact"><div class="auth-intro"><p class="eyebrow">Account access</p><h1>Log in to AstroForum.</h1><p>Sign in to manage your account and take part in the community.</p></div><div class="form-panel"><p class="eyebrow">Log in</p><h2>Account login</h2><form method="post" class="stack"><input type="hidden" name="action" value="login"><?= csrf_field() ?><label>Username<input name="username" autocomplete="username" required></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button class="primary-button" type="submit">Log in <span>→</span></button></form><p class="form-foot">Need an account? <a href="index.php?page=register">Register here</a></p></div></section>

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

        <?php elseif ($page === 'profile' && $profile): ?>
            <section class="page-heading"><p class="eyebrow">Community member</p><h1><?= e($profile['username']) ?></h1><p><?= e(ucfirst($profile['expertise'])) ?> · Joined <?= e(date('M j, Y', strtotime($profile['created_at']))) ?></p></section>
            <?php if ($verification): ?><section class="notice success">Verified by <?= e($verification['verifier']) ?>: <?= e($verification['note']) ?></section><?php endif; ?>
            <section class="profile-panel"><h2>Activity history</h2><?php if (!$history): ?><p class="empty-state">No activity yet.</p><?php else: ?><div class="activity-list"><?php foreach ($history as $item): ?><article class="activity-item"><span class="activity-type"><?= e(ucfirst($item['type'])) ?></span><a href="index.php?page=thread&thread=<?= e((string) $item['thread_id']) ?>"><?= e($item['title']) ?></a><small><?= e(date('M j, Y', strtotime($item['created_at']))) ?></small></article><?php endforeach; ?></div><?php endif; ?></section>

        <?php elseif ($page === 'catalogue' && $catalogueObject): ?>
            <section class="page-heading"><p class="eyebrow"><?= e(strtoupper($catalogueObject['object_type'])) ?></p><h1><?= e($catalogueObject['name']) ?></h1><p><?= e($catalogueObject['catalog_id'] ?? 'Catalogue entry') ?> · <?= e($catalogueObject['constellation'] ?? 'Uncharted') ?></p></section>
            <section class="profile-panel"><dl><div><dt>Object type</dt><dd><?= e($catalogueObject['object_type']) ?></dd></div><div><dt>Distance</dt><dd><?= e((string) $catalogueObject['distance_ly']) ?> ly</dd></div><div><dt>Coordinates</dt><dd><?= e((string) $catalogueObject['right_ascension']) ?> / <?= e((string) $catalogueObject['declination']) ?></dd></div></dl></section>

        <?php elseif ($page === 'disputes'): ?>
            <section class="page-heading"><p class="eyebrow">Independent review</p><h1>Proposal disputes</h1><p>Review challenges without resolving your own approvals.</p></section>
            <section class="proposals-list"><?php if (!$disputes): ?><p class="empty-state">No pending disputes.</p><?php else: ?><?php foreach ($disputes as $dispute): ?><article class="proposal-card"><h3><?= e($dispute['title']) ?></h3><p><?= e($dispute['reason']) ?></p><small>Raised by <?= e($dispute['username']) ?></small><div class="proposal-actions"><form method="post"><input type="hidden" name="action" value="resolve_dispute"><input type="hidden" name="dispute_id" value="<?= e((string) $dispute['id']) ?>"><?= csrf_field() ?><input type="hidden" name="decision" value="approve"><button class="approve-button" type="submit">Approve revert</button></form><form method="post"><input type="hidden" name="action" value="resolve_dispute"><input type="hidden" name="dispute_id" value="<?= e((string) $dispute['id']) ?>"><?= csrf_field() ?><input type="hidden" name="decision" value="reject"><button class="reject-button" type="submit">Reject dispute</button></form></div></article><?php endforeach; ?><?php endif; ?></section>

        <?php elseif ($page === 'admin'): 
            $admin = require_admin(); 
            $managedUsers = db()->query('SELECT id, username, expertise, is_restricted FROM users ORDER BY username')->fetchAll();
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
            <section class="profile-panel" style="margin-top: 30px;"><h2>Manage user access</h2><?php foreach ($managedUsers as $managed): ?><form method="post" class="user-row"><input type="hidden" name="action" value="moderate_user"><input type="hidden" name="user_id" value="<?= e((string) $managed['id']) ?>"><?= csrf_field() ?><strong><?= e($managed['username']) ?></strong><select name="expertise"><option value="normal"<?= $managed['expertise'] === 'normal' ? ' selected' : '' ?>>Normal</option><option value="expert"<?= $managed['expertise'] === 'expert' ? ' selected' : '' ?>>Expert</option><option value="verified"<?= $managed['expertise'] === 'verified' ? ' selected' : '' ?>>Verified</option></select><label><input type="checkbox" name="is_restricted" value="1"<?= $managed['is_restricted'] ? ' checked' : '' ?>> Restricted</label><input name="verification_note" placeholder="Verification reason"><button class="approve-button" type="submit">Save access</button></form><?php endforeach; ?></section>

        <?php elseif ($page === 'forum'):
            if ($category_slug):
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
        <?php else:
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
        <?php endif; ?>

        <?php elseif ($page === 'thread' && $thread_id):
            $thread = get_thread_by_id($thread_id);
            if (!$thread):
                $page = 'home';
            else:
                $posts = get_posts_for_thread($thread_id);
                $threadProposals = get_proposals_for_thread($thread_id);
                $catalogueObjects = get_catalogue_objects();
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
                                <span class="post-badge op">Thread Creator</span>
                            <?php endif; ?>
                            <span class="post-date"><?= e(date('M j, Y · g:i a', strtotime($post['created_at']))) ?></span>
                        </div>
                        <div class="post-body"><?= e($post['body']) ?><?php if ($post['linked_object_id']): ?> <a href="index.php?page=catalogue&object=<?= e((string) $post['linked_object_id']) ?>">View catalogue object</a><?php endif; ?></div>
                    </article>
                <?php endforeach; ?>
            </section>

            <?php if ($thread['type'] === 'proposal' && $user): ?>
                <section class="form-panel" style="margin-top: 30px;"><h2>Propose a catalogue entry</h2><form method="post" class="stack"><input type="hidden" name="action" value="create_add_proposal"><input type="hidden" name="thread_id" value="<?= e((string) $thread_id) ?>"><input type="hidden" name="post_id" value="<?= e((string) ($posts[0]['id'] ?? 0)) ?>"><?= csrf_field() ?><label>Name<input name="name" required></label><label>Object type<input name="object_type" required placeholder="star, galaxy, nebula..."></label><label>Right ascension<input name="right_ascension"></label><label>Declination<input name="declination"></label><label>Apparent magnitude<input name="apparent_mag" type="number" step="0.001"></label><label>Constellation<input name="constellation"></label><label>Distance in light years<input name="distance_ly" type="number" step="0.001"></label><label>Discovered by<input name="discovered_by"></label><label>Discovery year<input name="discovery_year" type="number"></label><label>Notes<textarea name="notes" rows="3"></textarea></label><label>Image URL<input name="image_url" type="url" placeholder="https://..."></label><button class="primary-button" type="submit">Submit proposal <span>→</span></button></form></section>
                <section class="form-panel" style="margin-top: 30px;"><h2>Propose a catalogue edit</h2><form method="post" class="stack"><input type="hidden" name="action" value="create_edit_proposal"><input type="hidden" name="thread_id" value="<?= e((string) $thread_id) ?>"><input type="hidden" name="post_id" value="<?= e((string) ($posts[0]['id'] ?? 0)) ?>"><?= csrf_field() ?><label>Catalogue object<select name="target_object_id" required><?php foreach ($catalogueObjects as $object): ?><option value="<?= e((string) $object['id']) ?>"><?= e($object['name']) ?></option><?php endforeach; ?></select></label><label>Field<select name="field" required><?php foreach (['name', 'object_type', 'constellation', 'distance_ly', 'notes'] as $field): ?><option value="<?= e($field) ?>"><?= e(ucwords(str_replace('_', ' ', $field))) ?></option><?php endforeach; ?></select></label><label>New value<input name="new_value" required></label><button class="primary-button" type="submit">Submit edit <span>→</span></button></form></section>
            <?php endif; ?>

            <?php if ($thread['type'] === 'identification' && $user && (int) $thread['author_id'] === (int) $user['id'] && !$thread['identified_object_id'] && count($posts) > 1): ?>
                <section class="form-panel" style="margin-top: 30px;"><h2>Confirm the identification</h2><form method="post" class="stack"><input type="hidden" name="action" value="confirm_identification"><input type="hidden" name="thread_id" value="<?= e((string) $thread_id) ?>"><?= csrf_field() ?><label>Helpful reply<select name="post_id" required><?php foreach (array_slice($posts, 1) as $post): ?><option value="<?= e((string) $post['id']) ?>"><?= e($post['username'] . ': ' . substr($post['body'], 0, 80)) ?></option><?php endforeach; ?></select></label><label>Catalogue object<select name="object_id" required><?php foreach ($catalogueObjects as $object): ?><option value="<?= e((string) $object['id']) ?>"><?= e($object['name'] . ' (' . $object['object_type'] . ')') ?></option><?php endforeach; ?></select></label><button class="primary-button" type="submit">Confirm identification <span>→</span></button></form></section>
            <?php endif; ?>

            <?php foreach ($threadProposals as $proposal): ?><section class="notice <?= $proposal['status'] === 'approved' ? 'success' : 'error' ?>" style="margin-top: 16px;"><strong><?= e(ucfirst($proposal['type'])) ?> proposal: <?= e($proposal['status']) ?></strong><?php if ($proposal['reason']): ?> <span><?= e($proposal['reason']) ?></span><?php endif; ?><?php if ($proposal['status'] === 'approved' && $user && (int) $proposal['author_id'] !== (int) $user['id'] && (int) $proposal['approver_id'] !== (int) $user['id']): ?><form method="post" class="inline-form"><input type="hidden" name="action" value="create_dispute"><input type="hidden" name="proposal_id" value="<?= e((string) $proposal['id']) ?>"><?= csrf_field() ?><input name="reason" required minlength="3" placeholder="Why should this be reverted?"><button class="reject-button" type="submit">Dispute</button></form><?php endif; ?></section><?php endforeach; ?>

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

        <?php endif; ?>

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

        <?php else: 
            $objects = catalogue_db()->query('SELECT name, catalog_id, object_type, constellation, distance_ly FROM objects WHERE status = "active" ORDER BY id LIMIT 6')->fetchAll();
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