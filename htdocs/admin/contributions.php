<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$page_title = 'Contribution History';
require_once __DIR__ . '/../includes/header.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

$total = $pdo->query("SELECT COUNT(*) FROM entry_edits")->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

$stmt = $pdo->prepare("
    SELECT ee.*, u.username AS reviewer_name,
        o.name AS entry_name, t.title AS thread_title
    FROM entry_edits ee
    JOIN users u ON u.id = ee.reviewer_id
    LEFT JOIN objects o ON o.id = ee.entry_id
    JOIN threads t ON t.id = ee.thread_id
    ORDER BY ee.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$per_page, $offset]);
$edits = $stmt->fetchAll();
?>
<h1>Contribution History</h1>

<?php if (empty($edits)): ?>
  <p>No contributions recorded.</p>
<?php else: ?>
  <table class="wide">
  <tr><th>Entry</th><th>Action</th><th>Field</th><th>Old</th><th>New</th><th>Reviewer</th><th>Thread</th><th>Date</th></tr>
  <?php foreach ($edits as $e): ?>
    <tr>
      <td><?= $e['entry_name'] ? '<a href="../entry.php?q=' . h(urlencode($e['entry_name'])) . '">' . h($e['entry_name']) . '</a>' : '#' . $e['entry_id'] ?></td>
      <td><?= h($e['action']) ?></td>
      <td><?= h($e['field'] ?? '') ?></td>
      <td><?= h($e['old_value'] ?? '') ?></td>
      <td><?= h($e['new_value'] ?? '') ?></td>
      <td><?= h($e['reviewer_name']) ?></td>
      <td><a href="../thread.php?id=<?= $e['thread_id'] ?>"><?= h($e['thread_title']) ?></a></td>
      <td><?= time_ago($e['created_at']) ?></td>
    </tr>
  <?php endforeach; ?>
  </table>

  <?php render_pagination($page, $total_pages, "contributions.php?page={p}"); ?>
<?php endif; ?>
<?php
require_once __DIR__ . '/../includes/footer.php';
