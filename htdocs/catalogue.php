<?php
require_once __DIR__ . '/includes/init.php';

$page_title = 'Catalogue';
require_once __DIR__ . '/includes/header.php';

$search = trim($_GET['search'] ?? '');
$type_filter = $_GET['type'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 30;
$offset = ($page - 1) * $per_page;

$where = "WHERE o.status = 'active'";
$params = [];

if ($search) {
    $where .= " AND (o.name LIKE ? OR o.catalog_id LIKE ? OR o.notes LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($type_filter) {
    $where .= " AND o.entry_type = ?";
    $params[] = $type_filter;
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM objects o $where");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

$stmt = $pdo->prepare("
    SELECT o.*,
        (SELECT COUNT(*) FROM threads t WHERE t.entry_id = o.id OR t.identified_entry_id = o.id) AS thread_count
    FROM objects o
    $where
    ORDER BY o.name ASC
    LIMIT ? OFFSET ?
");
$params[] = $per_page;
$params[] = $offset;
$stmt->execute($params);
$entries = $stmt->fetchAll();

$types = $pdo->query("SELECT DISTINCT entry_type FROM objects WHERE status = 'active' ORDER BY entry_type")->fetchAll(PDO::FETCH_COLUMN);
?>
<h1>Catalogue</h1>

<form method="get">
<p>
  <input type="text" name="search" size="40" placeholder="Search by name, catalog ID, or notes" value="<?= h($search) ?>">
  <select name="type">
    <option value="">All types</option>
    <?php foreach ($types as $t): ?>
      <option value="<?= h($t) ?>" <?= $type_filter === $t ? 'selected' : '' ?>><?= h($t) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="submit" value="Search">
</p>
</form>

<p><?= $total ?> entries found.</p>

<?php if (empty($entries)): ?>
  <p>No entries found.</p>
<?php else: ?>
  <table class="wide">
  <tr><th>Name</th><th>Catalog ID</th><th>Type</th><th>Constellation</th><th>Mag</th><th>Threads</th></tr>
  <?php foreach ($entries as $e): ?>
    <tr>
      <td><a href="entry.php?q=<?= h(urlencode($e['name'])) ?>"><?= h($e['name']) ?></a></td>
      <td><?= h($e['catalog_id'] ?? '') ?></td>
      <td><?= h($e['entry_type']) ?></td>
      <td><?= h($e['constellation'] ?? '') ?></td>
      <td><?= h($e['apparent_mag'] ?? '') ?></td>
      <td style="text-align:center"><?= $e['thread_count'] ?></td>
    </tr>
  <?php endforeach; ?>
  </table>

  <?php render_pagination($page, $total_pages, "catalogue.php?page={p}&search=" . h(urlencode($search)) . "&type=" . h(urlencode($type_filter))); ?>
<?php endif; ?>
<?php
require_once __DIR__ . '/includes/footer.php';
