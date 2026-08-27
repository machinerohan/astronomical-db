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

if ($action === 'approve_registration' || $action === 'reject_registration') {
    $admin = require_admin();
    verify_csrf();
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $status = $action === 'approve_registration' ? 'active' : 'rejected';
    $statement = db()->prepare('UPDATE users SET registration_status = ?, approved_by = ? WHERE id = ? AND registration_status = "pending"');
    $statement->execute([$status, $action === 'approve_registration' ? $admin['id'] : null, $userId]);
    flash('success', $action === 'approve_registration' ? 'Registration approved.' : 'Registration rejected.');
    redirect('admin');
}

// Verification & restriction (spec R8) ----------------------------------------

if ($action === 'verify') {
    verify_user(require_admin(), filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT) ?? 0, (string) ($_POST['note'] ?? ''));
    redirect('admin');
}

if ($action === 'unverify') {
    remove_verification(require_admin(), filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT) ?? 0, (string) ($_POST['note'] ?? ''));
    redirect('admin');
}

if ($action === 'restrict') {
    restrict_user(require_admin(), filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT) ?? 0, (string) ($_POST['note'] ?? ''));
    redirect('admin');
}

if ($action === 'unrestrict') {
    unrestrict_user(require_admin(), filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT) ?? 0, (string) ($_POST['note'] ?? ''));
    redirect('admin');
}

// Threads & replies (spec R2, R3, R9) --------------------------------------------

if ($action === 'create_thread') {
    $user = require_user();
    ensure_not_restricted($user);
    verify_csrf();

    $categoryId = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT) ?? 0;
    $type = (string) ($_POST['type'] ?? 'discussion');
    $title = trim((string) ($_POST['title'] ?? ''));
    $body = trim((string) ($_POST['body'] ?? ''));

    if (!in_array($type, ['discussion', 'identification', 'proposal'], true)) {
        $type = 'discussion';
    }
    $category = $categoryId > 0 ? category_by_slug(category_slug_from_id($categoryId)) : null;

    if (!$category || !$title || !$body || strlen($title) > 255) {
        flash('error', 'A category, a title, and some content are required.');
        redirect($categoryId > 0 ? 'new_thread' : 'forums');
    }

    $proposalPayload = null;
    $proposalType = null;

    if ($type === 'proposal') {
        $proposalType = (string) ($_POST['proposal_kind'] ?? '');
        if (!in_array($proposalType, ['add_entry', 'edit_field'], true)) {
            flash('error', 'Choose whether the proposal adds an entry or edits a field.');
            redirect('new_thread');
        }

        // Subforum integrity (R9): an object-type subforum carries matching proposals.
        if ($category['object_type'] !== null) {
            $payloadTypeCheck = $proposalType === 'add_entry'
                ? (string) ($_POST['object_type'] ?? '')
                : (catalogue_object(filter_input(INPUT_POST, 'target_object_id', FILTER_VALIDATE_INT) ?? 0)['object_type'] ?? '');
            if ($payloadTypeCheck !== $category['object_type']) {
                flash('error', 'Proposals about a “' . $category['object_type'] . '” belong in its subforum.');
                redirect('new_thread');
            }
        }

        if ($proposalType === 'add_entry') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $objectType = trim((string) ($_POST['object_type'] ?? ''));
            if ($name === '' || $objectType === '') {
                flash('error', 'A proposed entry needs at least a name and an object type.');
                redirect('new_thread');
            }
            $mag = ($_POST['apparent_mag'] ?? '') !== '' ? (float) $_POST['apparent_mag'] : null;
            $distance = ($_POST['distance_ly'] ?? '') !== '' ? (float) $_POST['distance_ly'] : null;
            $year = ($_POST['discovery_year'] ?? '') !== '' ? (int) $_POST['discovery_year'] : null;

            $proposalPayload = [
                'field' => null,
                'new_value' => null,
                'object' => [
                    'name' => $name,
                    'object_type' => $objectType,
                    'right_ascension' => trim((string) ($_POST['right_ascension'] ?? '')) ?: null,
                    'declination' => trim((string) ($_POST['declination'] ?? '')) ?: null,
                    'apparent_mag' => $mag,
                    'constellation' => trim((string) ($_POST['constellation'] ?? '')) ?: null,
                    'distance_ly' => $distance,
                    'discovered_by' => trim((string) ($_POST['discovered_by'] ?? '')) ?: null,
                    'discovery_year' => $year,
                    'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
                ],
            ];
        } else {
            $targetId = filter_input(INPUT_POST, 'target_object_id', FILTER_VALIDATE_INT);
            $field = (string) ($_POST['field'] ?? '');
            $newValue = trim((string) ($_POST['new_value'] ?? ''));
            if (!$targetId || !catalogue_object($targetId)) {
                flash('error', 'Choose the catalogue entry this proposal edits.');
                redirect('new_thread');
            }
            if (!in_array($field, ALLOWED_OBJECT_FIELDS, true) || $newValue === '') {
                flash('error', 'Pick a valid field and provide its new value.');
                redirect('new_thread');
            }
            $proposalPayload = [
                'field' => $field,
                'new_value' => mb_substr($newValue, 0, 255),
                'target_object_id' => $targetId,
            ];
        }
    }

    try {
        $threadId = create_thread($user, $categoryId, $type, $title, $body);

        if ($proposalPayload !== null) {
            $openingPostId = (int) db()->query('SELECT id FROM posts WHERE thread_id = ' . $threadId . ' ORDER BY id ASC LIMIT 1')->fetchColumn();
            $proposalId = create_proposal($user, $threadId, $openingPostId, (string) $proposalType, $proposalPayload);

            // Pictures submitted with the proposal wait here until approval attaches
            // them to the created catalogue entry (spec R11).
            foreach ($_FILES['images']['name'] ?? [] as $index => $ignored) {
                $single = [
                    'name' => $_FILES['images']['name'][$index],
                    'type' => $_FILES['images']['type'][$index],
                    'tmp_name' => $_FILES['images']['tmp_name'][$index],
                    'error' => $_FILES['images']['error'][$index],
                    'size' => $_FILES['images']['size'][$index],
                ];
                $stored = store_image_upload($single, trim((string) ($_POST['image_captions'][$index] ?? '')));
                if ($stored !== null) {
                    attach_image_to_proposal($proposalId, $stored, (int) $user['id']);
                }
            }
        }

        flash('success', 'Thread created.');
        redirect('thread_page', ['id' => $threadId]);
    } catch (Throwable $exception) {
        flash('error', 'Could not create the thread: ' . $exception->getMessage());
        redirect('new_thread');
    }
}

if ($action === 'reply') {
    $user = require_user();
    ensure_not_restricted($user);
    verify_csrf();

    $threadId = filter_input(INPUT_POST, 'thread_id', FILTER_VALIDATE_INT);
    $body = trim((string) ($_POST['body'] ?? ''));
    $linkedPostId = filter_input(INPUT_POST, 'linked_post_id', FILTER_VALIDATE_INT);

    if (!$threadId || $body === '') {
        flash('error', 'Write something before posting.');
    } else {
        $insert = db()->prepare(
            'INSERT INTO posts (thread_id, author_id, body, linked_post_id) VALUES (?, ?, ?, ?)'
        );
        $insert->execute([$threadId, $user['id'], $body, $linkedPostId ?: null]);
        flash('success', 'Reply posted.');
    }
    redirect($threadId ? 'thread_page' : 'forums', ['id' => $threadId]);
}

if ($action === 'confirm_identification') {
    $user = require_user();
    ensure_not_restricted($user);
    verify_csrf();
    $threadId = filter_input(INPUT_POST, 'thread_id', FILTER_VALIDATE_INT);
    $objectId = filter_input(INPUT_POST, 'object_id', FILTER_VALIDATE_INT);
    if ($threadId && $objectId) {
        confirm_identification($threadId, $objectId, $user);
    }
    redirect($threadId ? 'thread_page' : 'forums', ['id' => $threadId]);
}

// Proposals (spec R4, R5, R9) ------------------------------------------------------

if ($action === 'approve_proposal') {
    verify_csrf();
    $approver = require_proposal_approver();
    $proposalId = filter_input(INPUT_POST, 'proposal_id', FILTER_VALIDATE_INT) ?? 0;

    $check = db()->prepare('SELECT thread_id, author_id FROM proposals WHERE id = ?');
    $check->execute([$proposalId]);
    $info = $check->fetch();
    $returnTo = (int) ($info['thread_id'] ?? 0);

    if ((int) ($info['author_id'] ?? 0) === (int) $approver['id']) {
        flash('error', 'You cannot approve your own proposal.'); // R5/R4
        redirect('thread_page', ['id' => $returnTo]);
    }

    try {
        approve_proposal($proposalId, $approver);
    } catch (Throwable $exception) {
        // If bookkeeping failed after a new entry was created, remove the orphan
        // so the catalogue never holds an unaudited row.
        $orphan = db()->prepare("SELECT oe.object_id FROM object_edits oe WHERE oe.proposal_id = ? AND oe.field = '__created__'");
        $orphan->execute([$proposalId]);
        if ($madeId = (int) ($orphan->fetchColumn() ?: 0)) {
            catalog_db()->prepare('DELETE FROM objects WHERE id = ?')->execute([$madeId]);
        }
        flash('error', 'Approval could not be completed: ' . $exception->getMessage());
    }
    redirect('thread_page', ['id' => $returnTo]);
}

if ($action === 'reject_proposal') {
    verify_csrf();
    $approver = require_proposal_approver();
    $proposalId = filter_input(INPUT_POST, 'proposal_id', FILTER_VALIDATE_INT) ?? 0;
    $reason = trim((string) ($_POST['reason'] ?? ''));

    $lookup = db()->prepare('SELECT thread_id FROM proposals WHERE id = ?');
    $lookup->execute([$proposalId]);

    if ($reason === '') {
        flash('error', 'Rejections need a reason so the thread gets the answer (spec rule 9).');
    } else {
        reject_proposal($proposalId, $approver, $reason);
    }
    redirect('thread_page', ['id' => (int) ($lookup->fetchColumn() ?: 0)]);
}

// Disputes (spec R6, R7) -------------------------------------------------------------

if ($action === 'file_dispute') {
    $user = require_user();
    ensure_not_restricted($user);
    verify_csrf();
    $proposalId = filter_input(INPUT_POST, 'proposal_id', FILTER_VALIDATE_INT) ?? 0;
    $reason = (string) ($_POST['reason'] ?? '');

    $lookup = db()->prepare('SELECT thread_id, author_id FROM proposals WHERE id = ?');
    $lookup->execute([$proposalId]);
    $info = $lookup->fetch();

    if ((int) ($info['author_id'] ?? 0) === (int) $user['id']) {
        flash('error', 'Disputing your own approved proposal is pointless.');
    } else {
        file_dispute($user, $proposalId, $reason);
        flash('success', 'Dispute filed.');
    }
    redirect('thread_page', ['id' => (int) ($info['thread_id'] ?? 0)]);
}

if ($action === 'resolve_dispute') {
    $user = require_user();
    ensure_not_restricted($user);
    verify_csrf();
    $disputeId = filter_input(INPUT_POST, 'dispute_id', FILTER_VALIDATE_INT) ?? 0;
    $uphold = ($_POST['resolution'] ?? '') === 'uphold';

    $lookup = db()->prepare('SELECT p.thread_id FROM disputes d JOIN proposals p ON p.id = d.proposal_id WHERE d.id = ?');
    $lookup->execute([$disputeId]);

    resolve_dispute($disputeId, $user, $uphold);
    redirect('thread_page', ['id' => (int) ($lookup->fetchColumn() ?: 0)]);
}

// --------------------------------------------------------------------------------------

$user = current_user();
$flash = take_flash();

function category_slug_from_id(int $id): string
{
    static $cache = [];
    if (!isset($cache[$id])) {
        $row = db()->prepare('SELECT slug FROM categories WHERE id = ?');
        $row->execute([$id]);
        $cache[$id] = (string) $row->fetchColumn();
    }
    return $cache[$id];
}

function expertise_badge(?array $who): string
{
    if (!$who) {
        return '';
    }
    if ($who['role'] === 'admin') {
        return '<span class="badge badge-admin">admin</span>';
    }
    if ($who['is_restricted']) {
        return '<span class="badge badge-restricted">restricted</span>';
    }
    return match ($who['expertise']) {
        'expert' => '<span class="badge badge-expert">expert</span>',
        'verified' => '<span class="badge badge-verified">verified</span>',
        default => '',
    };
}

function object_thumbnail_map(array $objects): array
{
    $ids = array_column($objects, 'id');
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_map('intval', $ids));
    $rows = catalog_db()->query(
        "SELECT i.object_id, i.path FROM object_images i
         JOIN (SELECT object_id, MAX(id) AS mid FROM object_images WHERE object_id IS NOT NULL GROUP BY object_id) t
           ON t.mid = i.id WHERE i.object_id IN ($in)"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    return $rows;
}

/** Data per GET page */
$pageData = [];

switch ($page) {
    case 'catalogue':
        $type = trim((string) ($_GET['type'] ?? ''));
        $search = trim((string) ($_GET['q'] ?? ''));
        $pageData['objects'] = catalogue_objects($type ?: null, $search ?: null);
        $pageData['thumbs'] = object_thumbnail_map($pageData['objects']);
        $pageData['types'] = catalog_db()->query('SELECT DISTINCT object_type FROM objects WHERE status = "active" ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN);
        break;

    case 'object_detail':
        $objectId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $pageData['object'] = $objectId ? catalogue_object($objectId) : null;
        if ($pageData['object']) {
            $pageData['images'] = images_for_object($objectId);
            $pageData['provenance'] = object_provenance($objectId);
        }
        break;

    case 'forums':
        $counts = db()->query('SELECT c.id, COUNT(t.id) AS threads FROM categories c LEFT JOIN threads t ON t.category_id = c.id GROUP BY c.id')->fetchAll();
        $map = array_column($counts, 'threads', 'id');
        $pageData['categories'] = array_map(static function (array $c) use ($map) {
            $c['thread_count'] = (int) ($map[$c['id']] ?? 0);
            return $c;
        }, categories());
        break;

    case 'category':
        $slug = (string) ($_GET['slug'] ?? '');
        $pageData['category'] = category_by_slug($slug);
        $pageData['threads'] = $pageData['category'] ? threads_for_category((int) $pageData['category']['id']) : [];
        break;

    case 'new_thread':
        $slug = (string) ($_GET['category'] ?? '');
        $pageData['category'] = category_by_slug($slug) ?: (db()->query('SELECT * FROM categories WHERE object_type IS NULL')->fetch() ?: null);
        $pageData['categories'] = categories();
        $pageData['objects'] = catalogue_objects();
        $pageData['prefill_target'] = filter_input(INPUT_GET, 'target', FILTER_VALIDATE_INT) ?: '';
        $pageData['prefill_field'] = (string) ($_GET['field'] ?? '');
        break;

    case 'thread_page':
        $threadId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $pageData['thread'] = $threadId ? thread_by_id($threadId) : null;
        if ($pageData['thread']) {
            $pageData['posts'] = posts_for_thread($threadId);
            $propStmt = db()->prepare('SELECT pr.*, u.username AS proposer_name FROM proposals pr JOIN users u ON u.id = pr.author_id WHERE pr.thread_id = ? ORDER BY pr.id ASC LIMIT 1');
            $propStmt->execute([$threadId]);
            $pageData['proposal'] = $propStmt->fetch() ?: null;
            if ($pageData['proposal']) {
                $pendingObj = db()->prepare('SELECT * FROM proposed_objects WHERE proposal_id = ?');
                $pendingObj->execute([(int) $pageData['proposal']['id']]);
                $pageData['proposed_object'] = $pendingObj->fetch() ?: null;
                $disputesFor = db()->prepare('SELECT d.*, ru.username AS resolver_name, du.username AS disputer_name FROM disputes d JOIN users du ON du.id = d.author_id LEFT JOIN users ru ON ru.id = d.resolver_id WHERE d.proposal_id = ? ORDER BY d.created_at DESC');
                $disputesFor->execute([(int) $pageData['proposal']['id']]);
                $pageData['disputes'] = $disputesFor->fetchAll();
                $currentValue = $pageData['proposal']['type'] === 'edit_field' && $pageData['proposal']['target_object_id']
                    ? (catalogue_object((int) $pageData['proposal']['target_object_id'])[$pageData['proposal']['field']] ?? null)
                    : null;
                $pageData['current_value'] = $currentValue;
            }
        }
        break;

    case 'profile':
        $profileId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $viewerIsAdmin = ($user['role'] ?? '') === 'admin';
        if (!$profileId && $user) {
            $profileId = (int) $user['id'];
        }
        $stmt = db()->prepare('SELECT id, username, role, expertise, promotion_source, registration_status, is_restricted, created_at FROM users WHERE id = ?');
        $stmt->execute([$profileId]);
        $pageData['profile'] = $stmt->fetch() ?: null;
        if ($pageData['profile']) {
            $pid = (int) $pageData['profile']['id'];
            $pageData['history'] = profile_history($pid);
            $pageData['counts'] = approval_counts($pid);
            $showLog = $viewerIsAdmin || ($user && (int) $user['id'] === $pid);
            $pageData['verifications'] = $showLog ? verification_log($pid) : [];
        }
        break;

    case 'admin':
        $pageData['pending'] = db()->query('SELECT id, username, created_at FROM users WHERE registration_status = "pending" ORDER BY created_at')->fetchAll();
        $pageData['members'] = db()->query('SELECT id, username, role, expertise, promotion_source, is_restricted, created_at FROM users WHERE role <> "admin" ORDER BY created_at DESC')->fetchAll();
        break;

    case 'verification_log':
        $uid = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $stmt = db()->prepare('SELECT id, username FROM users WHERE id = ?');
        $stmt->execute([$uid]);
        $pageData['subject'] = $stmt->fetch() ?: null;
        $pageData['verifications'] = $pageData['subject'] ? verification_log((int) $pageData['subject']['id']) : [];
        break;

    default:
        $pageData['objects'] = catalogue_objects(null, null);
        $limit = array_slice($pageData['objects'], 0, 12);
        $pageData['objects_preview'] = $limit;
        $pageData['thumbs'] = object_thumbnail_map($limit);
        $pageData['forum_stats'] = db()->query('SELECT COUNT(*) AS threads, (SELECT COUNT(*) FROM posts) AS posts, (SELECT COUNT(*) FROM users WHERE registration_status = "active") AS members FROM threads')->fetch();
}

function page_title(string $page): string
{
    return match ($page) {
        'register' => 'Join the catalogue',
        'login' => 'Welcome back',
        'admin' => 'Administration desk',
        'forums' => 'Subforums',
        'category', 'new_thread' => 'Threads',
        'catalogue', 'object_detail' => 'Catalogue',
        'profile' => 'Profile',
        'verification_log' => 'Verification log',
        default => 'AstroForum',
    };
}
?>
<!doctype html>
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
            <a href="index.php?page=forums">Forums</a>
            <a href="index.php?page=catalogue">Catalogue</a>
            <?php if ($user): ?>
                <a href="index.php?page=profile">Dashboard</a>
                <?php if ($user['role'] === 'admin'): ?><a href="index.php?page=admin">Admin desk</a><?php endif; ?>
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

        <?php elseif ($page === 'admin'): $admin = require_admin(); ?>
            <section class="page-heading"><p class="eyebrow">Admin only</p><h1>Administration desk</h1><p>Review registrations and steward expert standing across the community.</p></section>

            <section class="queue-panel"><div class="queue-head"><div><span class="stat-label">Awaiting review</span><h2><?= count($pageData['pending']) ?> registration<?= count($pageData['pending']) === 1 ? '' : 's' ?></h2></div><span class="queue-icon">◎</span></div>
                <?php if (!$pageData['pending']): ?><div class="empty-state">The queue is clear.</div>
                <?php else: ?><div class="user-list"><?php foreach ($pageData['pending'] as $candidate): ?><div class="user-row"><div><strong><?= e($candidate['username']) ?></strong><span>Submitted <?= e(date('M j, Y · g:i a', strtotime($candidate['created_at']))) ?></span></div><div class="row-actions">
                    <form method="post"><input type="hidden" name="action" value="approve_registration"><input type="hidden" name="user_id" value="<?= (int) $candidate['id'] ?>"><?= csrf_field() ?><button class="approve-button">Approve</button></form>
                    <form method="post"><input type="hidden" name="action" value="reject_registration"><input type="hidden" name="user_id" value="<?= (int) $candidate['id'] ?>"><?= csrf_field() ?><button class="reject-button">Reject</button></form>
                </div></div><?php endforeach; ?></div><?php endif; ?></section>

            <section class="section-head spaced"><div><p class="eyebrow">Stewardship (rule 8)</p><h2>User management</h2></div><span class="section-note">Verification grants expert access · restriction removes contribution rights</span></section>
            <div class="user-list">
                <?php foreach ($pageData['members'] as $m): ?>
                    <div class="user-row member-row">
                        <div>
                            <strong><a class="quiet-link" href="index.php?page=profile&id=<?= (int) $m['id'] ?>"><?= e($m['username']) ?></a></strong>
                            <?= expertise_badge($m) ?>
                            <?php if ($m['promotion_source'] === 'auto'): ?><span class="badge badge-auto">auto-promoted</span><?php endif; ?>
                            <span class="meta-line">joined <?= e(date('M j, Y', strtotime($m['created_at']))) ?></span>
                        </div>
                        <div class="stack-actions">
                            <?php if ($m['expertise'] !== 'verified'): ?>
                                <form method="post" class="note-form"><input type="hidden" name="action" value="verify"><input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>"><?= csrf_field() ?><input name="note" placeholder="Why verify?" required minlength="3"><button class="approve-button">Verify</button></form>
                            <?php else: ?>
                                <form method="post" class="note-form"><input type="hidden" name="action" value="unverify"><input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>"><?= csrf_field() ?><input name="note" placeholder="Reason (optional)"><button class="reject-button">Unverify</button></form>
                            <?php endif; ?>
                            <?php if (!$m['is_restricted']): ?>
                                <form method="post" class="note-form"><input type="hidden" name="action" value="restrict"><input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>"><?= csrf_field() ?><input name="note" placeholder="Why restrict?" required minlength="3"><button class="reject-button">Restrict</button></form>
                            <?php else: ?>
                                <form method="post" class="note-form"><input type="hidden" name="action" value="unrestrict"><input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>"><?= csrf_field() ?><input name="note" placeholder="Reason (optional)"><button class="approve-button">Unrestrict</button></form>
                            <?php endif; ?>
                            <a class="text-link" href="index.php?page=verification_log&id=<?= (int) $m['id'] ?>">History</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php elseif ($page === 'verification_log'):
            if (($user['role'] ?? '') !== 'admin') { http_response_code(403); exit('Admins only.'); } ?>
            <section class="page-heading"><p class="eyebrow">Moderation record</p><h1>Verification log · <?= e($pageData['subject']['username'] ?? '?') ?></h1><p>Every verification decision recorded for this account (rule 8).</p></section>
            <div class="timeline">
                <?php if (!$pageData['verifications']): ?><div class="empty-state">No records.</div><?php endif; ?>
                <?php foreach ($pageData['verifications'] as $v): ?>
                    <div class="entry-card"><span class="badge badge-kind"><?= e($v['kind']) ?></span><p><?= e($v['note'] ?? '—') ?></p><small>by <?= e($v['verifier_name']) ?> · <?= e(date('M j, Y g:i a', strtotime($v['created_at']))) ?></small></div>
                <?php endforeach; ?>
            </div>

        <?php elseif ($page === 'forums'): ?>
            <section class="page-heading"><p class="eyebrow">Discussion board</p><h1>Subforums</h1><p>General discussion alongside one subforum per catalogued object type (rule 9).</p></section>
            <section class="object-grid forum-grid">
                <?php foreach ($pageData['categories'] as $c): ?>
                    <a class="object-card category-card" href="index.php?page=category&slug=<?= e($c['slug']) ?>">
                        <span class="object-type"><?= $c['object_type'] ? e(strtoupper($c['object_type'])) : 'GENERAL' ?></span>
                        <h3><?= e($c['name']) ?></h3>
                        <p><?= e($c['description'] ?? '') ?></p>
                        <small><?= (int) $c['thread_count'] ?> thread<?= (int) $c['thread_count'] === 1 ? '' : 's' ?></small>
                    </a>
                <?php endforeach; ?>
            </section>

        <?php elseif ($page === 'category' && $pageData['category']): $cat = $pageData['category']; ?>
            <nav class="crumb"><a href="index.php?page=forums">Forums</a> › <?= e($cat['name']) ?></nav>
            <section class="section-head"><div><p class="eyebrow"><?= $cat['object_type'] ? 'Subforum for ' . e($cat['object_type']) . ' entries' : 'General discussion' ?></p><h1><?= e($cat['name']) ?></h1><p><?= e($cat['description'] ?? '') ?></p></div>
                <?php if ($user): ?><a class="primary-button" href="index.php?page=new_thread&category=<?= e($cat['slug']) ?>">New thread <span>→</span></a>
                <?php else: ?><a class="text-link" href="index.php?page=login">Log in to start threads</a><?php endif; ?>
            </section>
            <div class="thread-list">
                <?php if (!$pageData['threads']): ?><div class="empty-state">No threads yet. Start one!</div><?php endif; ?>
                <?php foreach ($pageData['threads'] as $t): ?>
                    <a class="thread-row" href="index.php?page=thread_page&id=<?= (int) $t['id'] ?>">
                        <div>
                            <strong><?= e($t['title']) ?></strong>
                            <?php if ($t['type'] === 'identification'): ?>
                                <span class="tag tag-id">identification</span>
                                <?php if ($t['identified_object_name']): ?><span class="tag tag-ok">→ <?= e($t['identified_object_name']) ?></span><?php endif; ?>
                            <?php elseif ($t['type'] === 'proposal'): ?>
                                <span class="tag tag-proposal">proposal</span>
                                <span class="tag tag-status-<?= e($t['proposal_status'] ?? '') ?>"><?= e(ucfirst((string) ($t['proposal_status'] ?? ''))) ?></span>
                            <?php endif; ?>
                            <small>by <?= e($t['author_name']) ?> · <?= e(date('M j, Y', strtotime($t['created_at']))) ?></small>
                        </div>
                        <span class="reply-count"><?= (int) $t['reply_count'] ?> repl<?= (int) $t['reply_count'] === 1 ? 'y' : 'ies' ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

        <?php elseif ($page === 'new_thread' && $pageData['category']):
            if (!$user) { redirect('login'); } $cat = $pageData['category']; ?>
            <nav class="crumb"><a href="index.php?page=forums">Forums</a> › <a href="index.php?page=category&slug=<?= e($cat['slug']) ?>"><?= e($cat['name']) ?></a> › New thread</nav>
            <section class="form-panel wide-panel"><p class="eyebrow">Compose</p><h2>Start a thread in “<?= e($cat['name']) ?>”</h2>
                <form method="post" enctype="multipart/form-data" class="stack js-thread-form">
                    <input type="hidden" name="action" value="create_thread"><?= csrf_field() ?>
                    <input type="hidden" name="category_id" value="<?= (int) $cat['id'] ?>">
                    <label>Title<input name="title" required maxlength="255"></label>
                    <fieldset class="radio-set">
                        <legend>Thread type</legend>
                        <label class="radio"><input type="radio" name="type" value="discussion" checked> Discussion (general chat)</label>
                        <label class="radio"><input type="radio" name="type" value="identification"> Identification request <small>asking others to identify an unknown object (only via the opening message, rule 3)</small></label>
                        <label class="radio"><input type="radio" name="type" value="proposal"> Catalogue proposal <small>add an entry or change a field (rules 4–5)</small></label>
                    </fieldset>
                    <label>Opening message<textarea name="body" rows="6" required></textarea></label>

                    <fieldset class="proposal-fields hidden-fields">
                        <legend>Proposal payload</legend>
                        <fieldset class="radio-set">
                            <label class="radio"><input type="radio" name="proposal_kind" value="add_entry" checked> Add a new catalogue entry</label>
                            <label class="radio"><input type="radio" name="proposal_kind" value="edit_field"> Edit an existing entry’s field</label>
                        </fieldset>
                        <div class="subpanel js-add-fields">
                            <label>Name<input name="name" maxlength="255"></label>
                            <div class="grid-2">
                                <label>Object type<select name="object_type"><option value="star">star</option><option value="galaxy">galaxy</option><option value="nebula">nebula</option><option value="planet">planet</option><option value="other">other</option></select></label>
                                <label>Constellation<input name="constellation" maxlength="16"></label>
                            </div>
                            <div class="grid-2">
                                <label>Right ascension (deg)<input name="right_ascension" inputmode="decimal"></label>
                                <label>Declination (deg)<input name="declination" inputmode="decimal"></label>
                            </div>
                            <div class="grid-2">
                                <label>Apparent magnitude<input name="apparent_mag" inputmode="decimal"></label>
                                <label>Distance (ly)<input name="distance_ly" inputmode="decimal"></label>
                            </div>
                            <div class="grid-2">
                                <label>Discovered by<input name="discovered_by" maxlength="128"></label>
                                <label>Discovery year<input name="discovery_year" inputmode="numeric"></label>
                            </div>
                            <label>Notes<textarea name="notes" rows="3"></textarea></label>
                        </div>
                        <div class="subpanel js-edit-fields hidden-fields">
                            <label>Catalogue entry<select name="target_object_id"><?php foreach ($pageData['objects'] as $o): ?><option value="<?= (int) $o['id'] ?>" <?= (string) $pageData['prefill_target'] === (string) $o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?><?= $o['catalog_id'] ? ' (' . e($o['catalog_id']) . ')' : '' ?></option><?php endforeach; ?></select></label>
                            <label>Field<select name="field"><?php foreach (ALLOWED_OBJECT_FIELDS as $f): ?><option value="<?= e($f) ?>" <?= $pageData['prefill_field'] === $f ? 'selected' : '' ?>><?= e($f) ?></option><?php endforeach; ?></select></label>
                            <label>New value<input name="new_value" maxlength="255"></label>
                        </div>
                        <label>Pictures (JPEG/PNG/WebP/GIF, ≤4 MiB each)<input type="file" name="images[]" multiple accept="image/jpeg,image/png,image/webp,image/gif"><small>Submitted pictures attach to the proposal and move to the catalogue entry once experts approve (rule 11).</small></label>
                    </fieldset>
                    <button class="primary-button" type="submit">Publish thread <span>→</span></button>
                </form>
            </section>
            <script>
                // Minimal progressive enhancement: reveal fields relevant to chosen type.
                const form = document.querySelector('.js-thread-form');
                const types = form.querySelectorAll('input[name="type"]');
                const propFields = form.querySelector('.proposal-fields');
                const addFields = form.querySelector('.js-add-fields');
                const editFields = form.querySelector('.js-edit-fields');
                function sync() {
                    const type = form.querySelector('input[name="type"]:checked').value;
                    propFields.classList.toggle('hidden-fields', type !== 'proposal');
                    if (type === 'proposal') {
                        const kind = form.querySelector('input[name="proposal_kind"]:checked').value;
                        addFields.classList.toggle('hidden-fields', kind !== 'add_entry');
                        editFields.classList.toggle('hidden-fields', kind !== 'edit_field');
                    }
                }
                types.forEach(r => r.addEventListener('change', sync));
                form.querySelectorAll('input[name="proposal_kind"]').forEach(r => r.addEventListener('change', sync));
                sync();
            </script>

        <?php elseif ($page === 'thread_page' && $pageData['thread']):
            $t = $pageData['thread']; $isAuthor = $user && (int) $user['id'] === (int) $t['author_id']; ?>
            <nav class="crumb"><a href="index.php?page=forums">Forums</a> › <a href="index.php?page=category&slug=<?= e($t['category_slug']) ?>"><?= e($t['category_name']) ?></a> › <?= e($t['title']) ?></nav>
            <section class="section-head"><div><h1><?= e($t['title']) ?></h1>
                <p>
                    <?php if ($t['type'] === 'identification'): ?><span class="tag tag-id">identification</span><?php endif; ?>
                    <?php if ($t['type'] === 'proposal'): ?><span class="tag tag-proposal">proposal</span><?php endif; ?>
                    started by <?= e($t['author_name']) ?> <?= expertise_badge($t) ?> · <?= e(date('M j, Y', strtotime($t['created_at']))) ?>
                </p>
                <?php if ($t['type'] === 'identification' && $t['identified_object_id']): ?>
                    <p class="answer-banner">✔ Identified as <a href="index.php?page=object_detail&id=<?= (int) $t['identified_object_id'] ?>"><?= e(catalogue_object((int) $t['identified_object_id'])['name'] ?? 'deleted entry') ?></a></p>
                <?php endif; ?>
            </div></section>

            <?php if ($t['status'] !== 'open'): ?><div class="notice">This thread is closed.</div><?php endif; ?>

            <div class="post-list">
                <?php foreach ($pageData['posts'] as $p): ?>
                    <article class="post-card <?= $p['is_opening'] ? 'post-opening' : '' ?>">
                        <header><strong><?= e($p['author_name']) ?></strong> <?= expertise_badge(['role' => 'member', 'expertise' => $p['author_expertise'], 'is_restricted' => false]) ?>
                            <?php if ($p['is_solution']): ?><span class="tag tag-ok">confirmed answer</span><?php endif; ?>
                            <time><?= e(date('M j, Y g:i a', strtotime($p['created_at']))) ?></time></header>
                        <?php if (!empty($p['linked_post_body'])): ?>
                            <blockquote class="quote-block">In reply to <?= e($p['linked_post_author']) ?>: <?= nl2br(e(mb_substr((string) $p['linked_post_body'], 0, 280))) ?></blockquote>
                        <?php endif; ?>
                        <div class="post-body"><?= nl2br(e($p['body'])) ?></div>
                        <?php if ($p['linked_object_id']): ?>
                            <a class="chip-link" href="index.php?page=object_detail&id=<?= (int) $p['linked_object_id'] ?>">🔭 Catalogue: <?= e($p['linked_object_name'] ?? '#' . $p['linked_object_id']) ?></a>
                        <?php endif; ?>
                        <?php if ($user && $p['id'] != ($user['id'] ?? null)): ?>
                            <details class="quote-toggle"><summary>Reply quoting this</summary>
                                <form method="post" class="stack"><input type="hidden" name="action" value="reply"><?= csrf_field() ?>
                                    <input type="hidden" name="thread_id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="linked_post_id" value="<?= (int) $p['id'] ?>">
                                    <textarea name="body" rows="3" required></textarea><button class="primary-button">Post reply</button></form>
                            </details>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php // Identification confirmation (rule 3): author-only, only while unresolved. ?>
            <?php if ($t['type'] === 'identification' && !$t['identified_object_id'] && $isAuthor && $t['status'] === 'open'): ?>
                <section class="form-panel action-panel"><p class="eyebrow">You opened this thread</p><h2>Confirm the identification</h2>
                    <form method="post" class="inline-action"><input type="hidden" name="action" value="confirm_identification"><?= csrf_field() ?>
                        <input type="hidden" name="thread_id" value="<?= (int) $t['id'] ?>">
                        <label>Which catalogue entry?<select name="object_id"><?php foreach (catalogue_objects() as $o): ?><option value="<?= (int) $o['id'] ?>"><?= e($o['name']) ?><?= $o['catalog_id'] ? ' (' . e($o['catalog_id']) . ')' : '' ?></option><?php endforeach; ?></select></label>
                        <button class="primary-button">Confirm</button></form>
                    <small>The reply will link the thread to the object’s catalogue page.</small></section>
            <?php endif; ?>

            <?php // Proposal lifecycle panel (rules 4–7, 9). ?>
            <?php if ($t['type'] === 'proposal' && !empty($pageData['proposal'])): $pr = $pageData['proposal']; ?>
                <section class="proposal-panel">
                    <div class="panel-head"><span class="stat-label">Proposal #<?= (int) $pr['id'] ?></span>
                        <span class="tag tag-status-<?= e($pr['status']) ?>"><?= e(ucfirst($pr['status'])) ?></span>
                        <span class="meta"><?= $pr['type'] === 'add_entry' ? 'Add new entry' : 'Edit “' . e((string) $pr['field']) . '”' ?> by <?= e($pr['proposer_name']) ?></span></div>

                    <?php if ($pr['type'] === 'add_entry' && !empty($pageData['proposed_object'])): $po = $pageData['proposed_object']; ?>
                        <dl class="payload-grid"><div><dt>name</dt><dd><?= e($po['name']) ?></dd></div><div><dt>object_type</dt><dd><?= e($po['object_type']) ?></dd></div><div><dt>constellation</dt><dd><?= e($po['constellation'] ?? '—') ?></dd></div><div><dt>distance_ly</dt><dd><?= e((string) $po['distance_ly']) ?></dd></div><div><dt>discovered_by</dt><dd><?= e($po['discovered_by'] ?? '—') ?></dd></div><div><dt>discovery_year</dt><dd><?= e((string) $po['discovery_year']) ?></dd></div></dl>
                    <?php elseif ($pr['type'] === 'edit_field'): ?>
                        <div class="diff-box"><code><?= e((string) $pr['field']) ?>:</code> <del><?= e((string) $pageData['current_value']) ?></del> → <ins><?= e((string) $pr['new_value']) ?></ins></div>
                    <?php endif; ?>

                    <?php $imgs = images_for_proposal((int) $pr['id']); if ($imgs): ?>
                        <div class="image-strip"><?php foreach ($imgs as $im): ?><figure><img src="<?= e($im['path']) ?>" alt="<?= e($im['caption'] ?? 'submitted picture') ?>" loading="lazy"><?php if ($im['caption']): ?><figcaption><?= e($im['caption']) ?></figcaption><?php endif; ?></figure><?php endforeach; ?></div>
                    <?php endif; ?>

                    <?php if ($pr['status'] === 'pending' && $user && can_approve_proposals($user)): ?>
                        <?php if ((int) $pr['author_id'] !== (int) $user['id']): ?>
                            <div class="resolution-row">
                                <form method="post"><input type="hidden" name="action" value="approve_proposal"><input type="hidden" name="proposal_id" value="<?= (int) $pr['id'] ?>"><?= csrf_field() ?><button class="approve-button">Approve & apply</button></form>
                                <form method="post" class="grow-form"><input type="hidden" name="action" value="reject_proposal"><input type="hidden" name="proposal_id" value="<?= (int) $pr['id'] ?>"><?= csrf_field() ?><input name="reason" placeholder="Reason sent as reply (required)" required><button class="reject-button">Reject</button></form>
                            </div>
                        <?php else: ?><p class="hint">Awaiting review — you cannot approve your own proposal.</p><?php endif; ?>
                    <?php elseif ($pr['status'] === 'approved'): ?>
                        <p class="hint">Approved by <?= e(db()->query('SELECT username FROM users WHERE id = ' . (int) $pr['approver_id'])->fetchColumn() ?: 'unknown') ?>.</p>
                        <?php if ($user && !$user['is_restricted'] && (int) $pr['author_id'] !== (int) $user['id']): ?>
                            <details class="quote-toggle"><summary>Dispute this approval (rule 6)</summary>
                                <form method="post" class="stack"><input type="hidden" name="action" value="file_dispute"><?= csrf_field() ?>
                                    <input type="hidden" name="proposal_id" value="<?= (int) $pr['id'] ?>">
                                    <textarea name="reason" rows="3" required placeholder="Why is this change wrong?"></textarea>
                                    <button class="reject-button">File dispute</button></form>
                            </details>
                        <?php endif; ?>
                    <?php elseif ($pr['status'] === 'reverted'): ?><p class="hint warn">This change was reverted after an upheld dispute.</p>
                    <?php elseif ($pr['status'] === 'rejected'): ?><p class="hint">Rejected: <?= e((string) $pr['reason']) ?></p>
                    <?php endif; ?>

                    <?php foreach ($pageData['disputes'] ?? [] as $d): ?>
                        <div class="dispute-entry">
                            <div><strong>Dispute by <?= e($d['disputer_name']) ?></strong>
                                <span class="tag tag-status-<?= e($d['status']) ?>"><?= e($d['status']) ?></span>
                                <p><?= nl2br(e($d['reason'])) ?></p>
                                <?php if ($d['resolver_name']): ?><small>resolved by <?= e($d['resolver_name']) ?></small><?php endif; ?></div>
                            <?php if ($d['status'] === 'pending' && $user && !$user['is_restricted']
                                     && (int) $user['id'] !== (int) $pr['approver_id']   // rule 6: not the original approver
                                     && (int) $user['id'] !== (int) $d['author_id']): ?>
                                <div class="resolution-row">
                                    <form method="post"><input type="hidden" name="action" value="resolve_dispute"><input type="hidden" name="dispute_id" value="<?= (int) $d['id'] ?>"><?= csrf_field() ?><button class="approve-button" name="resolution" value="uphold">Uphold → revert change</button></form>
                                    <form method="post"><input type="hidden" name="action" value="resolve_dispute"><input type="hidden" name="dispute_id" value="<?= (int) $d['id'] ?>"><?= csrf_field() ?><button class="reject-button" name="resolution" value="keep">Keep change</button></form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <?php if ($user && $t['status'] === 'open' && !$user['is_restricted']): ?>
                <section class="form-panel action-panel"><form method="post" class="stack"><input type="hidden" name="action" value="reply"><?= csrf_field() ?>
                    <input type="hidden" name="thread_id" value="<?= (int) $t['id'] ?>">
                    <label>Add your reply<textarea name="body" rows="4" required></textarea></label>
                    <button class="primary-button">Post reply</button></form></section>
            <?php elseif (!$user): ?>
                <p class="form-foot center"><a href="index.php?page=login">Log in</a> to join the discussion.</p>
            <?php endif; ?>

        <?php elseif ($page === 'profile' && $pageData['profile']): $p = $pageData['profile']; ?>
            <section class="page-heading"><p class="eyebrow">Member profile</p><h1><?= e($p['username']) ?> <?= expertise_badge($p) ?><?php if ($p['promotion_source'] === 'auto'): ?> <span class="badge badge-auto">auto-promoted</span><?php endif; ?></h1>
                <p>Member since <?= e(date('F Y', strtotime($p['created_at']))) ?><?php if ($p['is_restricted']): ?> · <span class="restricted-note">account restricted</span><?php endif; ?></p></section>

            <section class="stat-grid">
                <div class="stat-panel"><span class="stat-label">Approvals earned</span><strong><?= (int) $pageData['counts']['approved'] ?></strong><p><?php if ($p['expertise'] === 'expert' && $p['promotion_source'] === 'auto'): ?>Expert standing (rule 5)<?php elseif ($p['expertise'] === 'normal'): ?><?= (int) $pageData['counts']['next_expert_in'] ?> more until expert<?php else: ?>Standing granted by admins<?php endif; ?></p></div>
                <div class="stat-panel"><span class="stat-label">Upheld reverts</span><strong><?= (int) $pageData['counts']['reverted'] ?></strong><p><?= REVERTS_BEFORE_EXPERT_LOSS ?> reverts would cost auto-granted expert standing (rule 7)</p></div>
                <div class="stat-panel"><span class="stat-label">Reviews performed</span><strong><?= (int) $pageData['counts']['reviewed'] ?></strong><p>Proposals approved or rejected by this member</p></div>
                <div class="stat-panel"><span class="stat-label">Disputes resolved</span><strong><?= (int) $pageData['history']['disputes_resolved'] ?></strong><p>Rule 6 resolutions</p></div>
            </section>

            <section class="history-section"><h2>Recent activity</h2>
                <?php if (!$pageData['history']['posts'] && !$pageData['history']['proposals']): ?><div class="empty-state">Nothing yet.</div><?php endif; ?>
                <?php foreach ($pageData['history']['posts'] as $h): ?>
                    <a class="history-row" href="index.php?page=thread_page&id=<?= (int) $h['thread_id'] ?>">
                        <span class="tag tag-<?= e($h['thread_type']) ?>"><?= e($h['thread_type']) ?></span>
                        <strong><?= e($h['thread_title']) ?></strong>
                        <small><?= e(date('M j, Y', strtotime($h['created_at']))) ?> · <?= e(mb_substr((string) $h['body'], 0, 90)) ?>…</small></a>
                <?php endforeach; ?>
            </section>

            <section class="history-section"><h2>Proposals filed</h2>
                <?php if (!$pageData['history']['proposals']): ?><div class="empty-state">No proposals yet.</div><?php endif; ?>
                <?php foreach ($pageData['history']['proposals'] as $pr2): ?>
                    <a class="history-row" href="index.php?page=thread_page&id=<?= (int) $pr2['thread_id'] ?>">
                        <span class="tag tag-status-<?= e($pr2['status']) ?>"><?= e($pr2['status']) ?></span>
                        <strong><?= e($pr2['thread_title']) ?></strong>
                        <small><?= $pr2['type'] === 'add_entry' ? 'add entry' : 'edit ' . e((string) $pr2['field']) ?> · <?= (int) $pr2['dispute_count'] ?> dispute<?= (int) $pr2['dispute_count'] === 1 ? '' : 's' ?></small></a>
                <?php endforeach; ?>
            </section>

            <?php if (!empty($pageData['verifications'])): ?>
                <section class="history-section"><h2>Verification record</h2>
                    <?php foreach ($pageData['verifications'] as $v): ?>
                        <div class="entry-card"><span class="badge badge-kind"><?= e($v['kind']) ?></span><p><?= e($v['note'] ?? '—') ?></p><small>by <?= e($v['verifier_name']) ?> · <?= e(date('M j, Y', strtotime($v['created_at']))) ?></small></div>
                    <?php endforeach; ?></section>
            <?php endif; ?>

        <?php elseif ($page === 'catalogue'): ?>
            <section class="page-heading"><p class="eyebrow">The catalogue</p><h1>Astronomical objects</h1><p>Community-maintained entries with observer-submitted pictures (rule 11).</p></section>
            <form method="get" class="filter-bar"><input type="hidden" name="page" value="catalogue">
                <select name="type"><option value="">All types</option><?php foreach ($pageData['types'] as $tp): ?><option value="<?= e($tp) ?>" <?= ($_GET['type'] ?? '') === $tp ? 'selected' : '' ?>><?= e($tp) ?></option><?php endforeach; ?></select>
                <input name="q" placeholder="Search name or catalogue ID" value="<?= e((string) ($_GET['q'] ?? '')) ?>">
                <button class="primary-button">Filter</button></form>
            <section class="object-grid">
                <?php if (!$pageData['objects']): ?><div class="empty-state">No matches.</div><?php endif; ?>
                <?php foreach ($pageData['objects'] as $o): ?>
                    <a class="object-card" href="index.php?page=object_detail&id=<?= (int) $o['id'] ?>">
                        <?php if (!empty($pageData['thumbs'][$o['id']])): ?><img class="card-thumb" src="<?= e($pageData['thumbs'][$o['id']]) ?>" alt="" loading="lazy"><?php endif; ?>
                        <span class="object-type"><?= e(strtoupper($o['object_type'])) ?></span>
                        <h3><?= e($o['name']) ?></h3>
                        <p><?= e($o['catalog_id'] ?? '') ?><?= $o['constellation'] ? ' · ' . e($o['constellation']) : '' ?></p>
                        <small><?= $o['distance_ly'] !== null ? e(rtrim(rtrim((string) $o['distance_ly'], '0'), '.') ) . ' ly' : 'distance unknown' ?><?= (int) $o['image_count'] > 0 ? ' · 📷 ' . (int) $o['image_count'] : '' ?></small>
                    </a>
                <?php endforeach; ?>
            </section>

        <?php elseif ($page === 'object_detail' && $pageData['object']): $o = $pageData['object']; ?>
            <nav class="crumb"><a href="index.php?page=catalogue">Catalogue</a> › <?= e($o['name']) ?></nav>
            <section class="detail-layout">
                <div><p class="eyebrow"><?= e(strtoupper($o['object_type'])) ?><?= $o['catalog_id'] ? ' · ' . e($o['catalog_id']) : '' ?></p><h1><?= e($o['name']) ?></h1>
                    <dl class="payload-grid">
                        <div><dt>Constellation</dt><dd><?= e($o['constellation'] ?? '—') ?></dd></div>
                        <div><dt>Right ascension</dt><dd><?= e($o['right_ascension'] ?? '—') ?></dd></div>
                        <div><dt>Declination</dt><dd><?= e($o['declination'] ?? '—') ?></dd></div>
                        <div><dt>Apparent magnitude</dt><dd><?= e((string) $o['apparent_mag']) ?></dd></div>
                        <div><dt>Distance</dt><dd><?= $o['distance_ly'] !== null ? number_format((float) $o['distance_ly'], 2) . ' ly' : '—' ?></dd></div>
                        <div><dt>Discovery</dt><dd><?= e(($o['discovered_by'] ?? '—') . ($o['discovery_year'] ? ' · ' . $o['discovery_year'] : '')) ?></dd></div>
                        <div class="span-2"><dt>Notes</dt><dd><?= nl2br(e($o['notes'] ?? '—')) ?></dd></div>
                    </dl>
                    <?php if (!empty($pageData['provenance'])): $prov = $pageData['provenance']; ?>
                        <p class="provenance">Created via <a href="index.php?page=thread_page&id=<?= (int) $prov['proposal_thread_id'] ?>">community proposal #<?= (int) $prov['proposal_id'] ?></a> and kept current through the dispute process.</p>
                    <?php endif; ?>
                    <?php if ($user && !$user['is_restricted']): ?>
                        <a class="text-link" href="index.php?page=new_thread&category=<?php
                            $ownCat = db()->prepare('SELECT slug FROM categories WHERE object_type = ?'); $ownCat->execute([$o['object_type']]);
                            echo e((string) ($ownCat->fetchColumn() ?: 'general')); ?>&target=<?= (int) $o['id'] ?>">Propose an edit to this entry →</a>
                    <?php endif; ?>
                </div>
                <aside>
                    <h2 class="gallery-title">Observer pictures</h2>
                    <?php $imgs = $pageData['images'] ?? []; ?>
                    <?php if (!$imgs): ?><div class="empty-state">No pictures yet. Attach one via a proposal.</div><?php endif; ?>
                    <div class="image-strip column"><?php foreach ($imgs as $im): ?><figure><img src="<?= e($im['path']) ?>" alt="<?= e($im['caption'] ?? '') ?>" loading="lazy"><?php if ($im['caption']): ?><figcaption><?= e($im['caption']) ?></figcaption><?php endif; ?></figure><?php endforeach; ?></div>
                </aside>
            </section>

        <?php else: /* home & any unmatched page — compute preview data on demand */
            $pageData['objects_preview'] ??= array_slice(catalogue_objects(null, null), 0, 12);
            $pageData['thumbs'] ??= object_thumbnail_map($pageData['objects_preview']);
            $pageData['forum_stats'] = db()->query('SELECT COUNT(*) AS threads, (SELECT COUNT(*) FROM posts) AS posts, (SELECT COUNT(*) FROM users WHERE registration_status = "active") AS members FROM threads')->fetch();
            $fs = $pageData['forum_stats']; ?>
            <section class="hero"><div class="hero-copy"><p class="eyebrow">Crowdsourced astronomy</p><h1>Identify the sky. Curate the catalogue.</h1><p class="hero-text">Ask the community to identify unknown objects, propose corrections to the catalogue, and let experts settle what stays.</p><div class="hero-actions"><a class="primary-button" href="index.php?page=forums">Browse subforums <span>→</span></a><a class="text-link" href="index.php?page=catalogue">Catalogue</a></div></div><div class="hero-coordinate"><span>COMMUNITY</span><strong><?= (int) $fs['members'] ?></strong><small><?= (int) $fs['threads'] ?> threads · <?= (int) $fs['posts'] ?> messages</small></div></section>
            <section class="catalogue-preview"><div class="section-head"><div><p class="eyebrow">Browse objects</p><h2>Latest catalogue entries</h2></div><span class="section-note"><a class="text-link" href="index.php?page=catalogue">See all</a></span></div>
                <div class="object-grid"><?php foreach ($pageData['objects_preview'] as $o): ?><article class="object-card"><span class="object-type"><?= e(strtoupper($o['object_type'])) ?></span><h3><a class="quiet-link" href="index.php?page=object_detail&id=<?= (int) $o['id'] ?>"><?= e($o['name']) ?></a></h3><p><?= e($o['catalog_id'] ?? '') ?> · <?= e($o['constellation'] ?? '') ?></p><small><?= $o['distance_ly'] !== null ? e(rtrim(rtrim((string) $o['distance_ly'], '0'), '.')) . ' light years' : '' ?></small></article><?php endforeach; ?></div></section>
        <?php endif; ?>
    </main>
    <footer><span>ASTROFORUM</span><span>Community catalogue & discussion</span></footer>
</body>
</html>
