<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$page_title = 'Pending Proposals';
require_once __DIR__ . '/../includes/header.php';

$stmt = $pdo->query("
    SELECT t.*, u.username AS author_name, cat.name AS category_name
    FROM threads t
    JOIN users u ON u.id = t.author_id
    JOIN categories cat ON cat.id = t.category_id
    WHERE t.proposal_status = 'pending'
    ORDER BY t.created_at DESC
");
$proposals = $stmt->fetchAll();
?>
<h1>Pending Proposals</h1>

<?php if (empty($proposals)): ?>
  <p>No pending proposals.</p>
<?php else: ?>
  <table class="wide">
  <tr><th>Thread</th><th>Author</th><th>Category</th><th>Type</th><th>Date</th></tr>
  <?php foreach ($proposals as $p): ?>
    <tr>
      <td><a href="../thread.php?id=<?= $p['id'] ?>"><?= h($p['title']) ?></a></td>
      <td><?= h($p['author_name']) ?></td>
      <td><?= h($p['category_name']) ?></td>
      <td><?= h($p['proposal_type']) ?></td>
      <td><?= time_ago($p['created_at']) ?></td>
    </tr>
  <?php endforeach; ?>
  </table>
<?php endif; ?>
<?php
require_once __DIR__ . '/../includes/footer.php';
