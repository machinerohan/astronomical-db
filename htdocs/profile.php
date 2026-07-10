<?php
require_once __DIR__ . '/includes/init.php';

$username = $_GET['username'] ?? ($_SESSION['user_username'] ?? '');
$stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
$stmt->execute([$username]);
$prof = $stmt->fetch();
if (!$prof) { header('HTTP/1.1 404 Not Found'); echo 'User not found.'; exit; }

$page_title = $prof['username'] . ' — Profile';
require_once __DIR__ . '/includes/header.php';

$stmt = $pdo->prepare('SELECT COUNT(*) FROM threads WHERE author_id = ?');
$stmt->execute([$prof['id']]);
$threads_started = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM replies WHERE author_id = ? AND is_solution = 1');
$stmt->execute([$prof['id']]);
$solutions = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM proposed_entries WHERE author_id = ? AND thread_id IN
        (SELECT id FROM threads WHERE proposal_status = 'approved')
");
$stmt->execute([$prof['id']]);
$proposals_submitted = (int)$stmt->fetchColumn();

$approved = count_approved($pdo, 'proposed_entries', 'author_id', $prof['id']);
$approved += count_approved($pdo, 'proposed_field_edits', 'author_id', $prof['id']);

$neg = $pdo->prepare("SELECT COUNT(*) FROM entry_edits WHERE target_author_id = ? AND action = 'removed'");
$neg->execute([$prof['id']]);
$removed = (int)$neg->fetchColumn();

$net = $approved - $removed;
?>
<h1><?= h($prof['username']) ?></h1>

<table>
<tr><td>Role</td><td><?= h($prof['role']) ?></td></tr>
<tr><td>Expertise</td><td><?= h($prof['expertise']) ?></td></tr>
<tr><td>Member since</td><td><?= date('M j, Y', strtotime($prof['created_at'])) ?></td></tr>
</table>

<h2>Contribution Stats</h2>
<table>
<tr><td>Threads started</td><td><?= $threads_started ?></td></tr>
<tr><td>Solutions provided</td><td><?= $solutions ?></td></tr>
<tr><td>Proposals submitted</td><td><?= $proposals_submitted ?></td></tr>
<tr><td>Approved proposals</td><td><?= $approved ?></td></tr>
<tr><td>Removed contributions</td><td><?= $removed ?></td></tr>
<tr><td><strong>Net score</strong></td><td><strong><?= $net ?></strong></td></tr>
</table>

<?php if (is_logged_in() && $_SESSION['user_id'] === $prof['id']): ?>
  <p><a href="change-password.php">Change password</a></p>
<?php endif; ?>
<?php
require_once __DIR__ . '/includes/footer.php';
