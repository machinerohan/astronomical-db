<?php
require_once __DIR__ . '/includes/init.php';

$slug = $_GET['slug'] ?? '';
$stmt = $pdo->prepare('SELECT * FROM categories WHERE slug = ?');
$stmt->execute([$slug]);
$cat = $stmt->fetch();
if (!$cat) { http_response_code(404); echo 'Category not found.'; exit; }

$page_title = $cat['name'];
require_once __DIR__ . '/includes/header.php';

$is_proposal = $cat['is_proposal'];
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare("
    SELECT t.*, u.username AS author_name,
        (SELECT COUNT(*) FROM replies WHERE thread_id = t.id) AS reply_count
    FROM threads t
    JOIN users u ON u.id = t.author_id
    WHERE t.category_id = ?
    ORDER BY t.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$cat['id'], $per_page, $offset]);
$threads = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM threads WHERE category_id = ?');
$stmt->execute([$cat['id']]);
$total = (int)$stmt->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));
?>
<h1><?= h($cat['name']) ?></h1>
<p><?= h($cat['description']) ?></p>

<p><a href="new-thread.php?category=<?= h($cat['slug']) ?>">+ New Thread</a></p>

<?php if (empty($threads)): ?>
  <p>No threads yet. <a href="new-thread.php?category=<?= h($cat['slug']) ?>">Start one!</a></p>
<?php else: ?>
  <table class="wide">
  <tr><th>Thread</th><th>Replies</th><th>Author</th><th>Last Activity</th></tr>
  <?php foreach ($threads as $t): ?>
    <tr>
      <td>
        <a href="thread.php?id=<?= $t['id'] ?>"><?= h($t['title']) ?></a>
        <?php if ($t['status'] === 'closed'): ?> <strong>[Closed]</strong><?php endif; ?>
        <?php if ($is_proposal): ?>
          <?php if ($t['proposal_status'] === 'approved'): ?> <strong style="color:green">[Approved]</strong>
          <?php elseif ($t['proposal_status'] === 'rejected'): ?> <strong style="color:red">[Rejected]</strong>
          <?php else: ?> <strong style="color:orange">[Pending]</strong>
          <?php endif; ?>
        <?php endif; ?>
      </td>
      <td style="text-align:center"><?= $t['reply_count'] ?></td>
      <td><a href="profile.php?username=<?= h($t['author_name']) ?>"><?= h($t['author_name']) ?></a></td>
      <td><?= time_ago($t['created_at']) ?></td>
    </tr>
  <?php endforeach; ?>
  </table>

  <?php render_pagination($page, $total_pages, "category.php?slug=" . h($slug) . "&page={p}"); ?>
<?php endif; ?>
<?php
require_once __DIR__ . '/includes/footer.php';
