<?php
require_once __DIR__ . '/includes/init.php';

$page_title = 'Forum Index';
require_once __DIR__ . '/includes/header.php';

$cats = $pdo->query("
    SELECT c.*,
        (SELECT COUNT(*) FROM threads WHERE category_id = c.id AND status = 'open') AS thread_count
    FROM categories c
    ORDER BY c.sort_order
")->fetchAll();

$parents = array_filter($cats, fn($c) => $c['parent_id'] === null);
$children = [];
foreach ($cats as $c) {
    if ($c['parent_id'] !== null) {
        $children[$c['parent_id']][] = $c;
    }
}
?>
<h1>AstroForum</h1>
<table class="wide" style="max-width:700px">
<tr><th>Category</th><th>Threads</th></tr>
<?php foreach ($parents as $cat): ?>
<tr>
  <td><strong><a href="category.php?slug=<?= h($cat['slug']) ?>"><?= h($cat['name']) ?></a></strong>
    <br><small><?= h($cat['description']) ?></small>
    <?php if (isset($children[$cat['id']])): ?>
      <ul style="margin:4px 0 0 16px;padding:0">
      <?php foreach ($children[$cat['id']] as $child): ?>
        <li><a href="category.php?slug=<?= h($child['slug']) ?>"><?= h($child['name']) ?></a></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </td>
  <td style="text-align:center"><?= $cat['thread_count'] ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php
require_once __DIR__ . '/includes/footer.php';
