<?php
require_once __DIR__ . '/includes/init.php';

$page_title = 'Forum Index';
require_once __DIR__ . '/includes/header.php';

$categories = $pdo->query("
    SELECT c.*,
        (SELECT COUNT(*) FROM threads WHERE category_id = c.id AND status = 'open') AS thread_count
    FROM categories c
    ORDER BY c.sort_order
")->fetchAll();
?>
<h1>AstroForum</h1>
<table class="wide" style="max-width:700px">
<tr><th>Category</th><th>Threads</th></tr>
<?php foreach ($categories as $cat): ?>
<tr>
  <td>
    <strong><a href="category.php?slug=<?= h($cat['slug']) ?>"><?= h($cat['name']) ?></a></strong>
    <?php if ($cat['is_proposal']): ?> <strong style="color:purple">[Proposals]</strong><?php endif; ?>
    <br><small><?= h($cat['description']) ?></small>
  </td>
  <td style="text-align:center"><?= $cat['thread_count'] ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php
require_once __DIR__ . '/includes/footer.php';
