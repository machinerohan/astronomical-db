<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$page_title = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';

$user_count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$thread_count = $pdo->query("SELECT COUNT(*) FROM threads")->fetchColumn();
$reply_count = $pdo->query("SELECT COUNT(*) FROM replies")->fetchColumn();
$pending_proposals = $pdo->query("SELECT COUNT(*) FROM threads WHERE proposal_status = 'pending'")->fetchColumn();
$entry_count = $pdo->query("SELECT COUNT(*) FROM objects WHERE status = 'active'")->fetchColumn();
?>
<h1>Admin Dashboard</h1>

<table border="1" cellpadding="6" style="border-collapse:collapse">
<tr><td>Users</td><td><?= $user_count ?></td></tr>
<tr><td>Threads</td><td><?= $thread_count ?></td></tr>
<tr><td>Replies</td><td><?= $reply_count ?></td></tr>
<tr><td>Pending proposals</td><td><?= $pending_proposals ?></td></tr>
<tr><td>Catalogue entries</td><td><?= $entry_count ?></td></tr>
</table>

<h2>Admin Tools</h2>
<ul>
  <li><a href="users.php">Manage Users</a></li>
  <li><a href="create-user.php">Create User</a></li>
  <li><a href="proposals.php">Pending Proposals</a></li>
  <li><a href="contributions.php">Contribution History</a></li>
</ul>
<?php
require_once __DIR__ . '/../includes/footer.php';
