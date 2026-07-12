<?php
require_once __DIR__ . '/includes/init.php';

$username = $_GET['username'] ?? ($_SESSION['user_username'] ?? '');
$stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
$stmt->execute([$username]);
$prof = $stmt->fetch();
if (!$prof) { http_response_code(404); echo 'User not found.'; exit; }

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

<h2>Recent Threads</h2>
<?php
$stmt = $pdo->prepare("
    SELECT t.id, t.title, t.created_at, c.name AS category_name
    FROM threads t
    JOIN categories c ON c.id = t.category_id
    WHERE t.author_id = ?
    ORDER BY t.created_at DESC
    LIMIT 10
");
$stmt->execute([$prof['id']]);
$recent_threads = $stmt->fetchAll();
?>
<?php if (empty($recent_threads)): ?>
  <p>No threads yet.</p>
<?php else: ?>
  <ul>
  <?php foreach ($recent_threads as $t): ?>
    <li><a href="thread.php?id=<?= $t['id'] ?>"><?= h($t['title']) ?></a>
      &mdash; <?= h($t['category_name']) ?> &mdash; <?= time_ago($t['created_at']) ?></li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>

<h2>Recent Replies</h2>
<?php
$stmt = $pdo->prepare("
    SELECT r.id, r.created_at, t.id AS thread_id, t.title AS thread_title,
        c.name AS category_name, SUBSTRING(r.body, 1, 200) AS body_preview
    FROM replies r
    JOIN threads t ON t.id = r.thread_id
    JOIN categories c ON c.id = t.category_id
    WHERE r.author_id = ?
    ORDER BY r.created_at DESC
    LIMIT 10
");
$stmt->execute([$prof['id']]);
$recent_replies = $stmt->fetchAll();
?>
<?php if (empty($recent_replies)): ?>
  <p>No replies yet.</p>
<?php else: ?>
  <ul>
  <?php foreach ($recent_replies as $r): ?>
    <li><a href="thread.php?id=<?= $r['thread_id'] ?>"><?= h($r['thread_title']) ?></a>
      &mdash; <?= h($r['category_name']) ?> &mdash; <?= time_ago($r['created_at']) ?>
      <br><small><?= h($r['body_preview']) ?><?= strlen($r['body_preview']) >= 200 ? '…' : '' ?></small></li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if (is_logged_in() && $_SESSION['user_id'] === $prof['id']): ?>
  <p><a href="change-password.php">Change password</a></p>
<?php endif; ?>
<?php
require_once __DIR__ . '/includes/footer.php';
