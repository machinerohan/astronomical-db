<?php
require_once __DIR__ . '/includes/init.php';

$q = $_GET['q'] ?? '';
if (!$q) { header('Location: catalogue.php'); exit; }

$entry = find_object($pdo, $q);
if (!$entry) { http_response_code(404); echo 'Entry not found.'; exit; }

if ($entry['status'] === 'deleted') {
    $page_title = $entry['name'] . ' [Deleted]';
} else {
    $page_title = $entry['name'];
}
require_once __DIR__ . '/includes/header.php';

$fields = [];
foreach (ENTRY_FIELD_LABELS as $col => $label) {
    if ($col === 'notes') continue;
    $fields[$label] = $entry[$col];
}
$fields['Status'] = $entry['status'];
?>
<h1><?= h($entry['name']) ?></h1>
<?php if ($entry['status'] === 'deleted'): ?>
  <p><strong style="color:red">This entry has been deleted.</strong></p>
<?php endif; ?>

<table>
<?php foreach ($fields as $label => $value): ?>
  <tr><th style="text-align:right"><?= h($label) ?></th><td><?= h($value ?? '') ?></td></tr>
<?php endforeach; ?>
</table>

<?php if ($entry['notes']): ?>
  <h3>Notes</h3>
  <p><?= h($entry['notes']) ?></p>
<?php endif; ?>

<h2>Edit History</h2>
<?php
$stmt = $pdo->prepare("
    SELECT ee.*, u.username AS reviewer_name,
        t.title AS thread_title
    FROM entry_edits ee
    JOIN users u ON u.id = ee.reviewer_id
    JOIN threads t ON t.id = ee.thread_id
    WHERE ee.entry_id = ?
    ORDER BY ee.created_at ASC
");
$stmt->execute([$entry['id']]);
$edits = $stmt->fetchAll();
?>
<?php if (empty($edits)): ?>
  <p>No edit history recorded.</p>
<?php else: ?>
  <table class="wide">
  <tr><th>Action</th><th>Field</th><th>Old Value</th><th>New Value</th><th>Reviewer</th><th>Thread</th><th>Date</th></tr>
  <?php foreach ($edits as $e): ?>
    <tr>
      <td><?= h($e['action']) ?></td>
      <td><?= h($e['field'] ?? '') ?></td>
      <td><?= h($e['old_value'] ?? '') ?></td>
      <td><?= h($e['new_value'] ?? '') ?></td>
      <td><?= h($e['reviewer_name']) ?></td>
      <td><a href="thread.php?id=<?= $e['thread_id'] ?>"><?= h($e['thread_title']) ?></a></td>
      <td><?= time_ago($e['created_at']) ?></td>
    </tr>
  <?php endforeach; ?>
  </table>
<?php endif; ?>

<h2>Linked Threads</h2>
<?php
$stmt = $pdo->prepare("
    SELECT t.*, u.username AS author_name, cat.name AS category_name
    FROM threads t
    JOIN users u ON u.id = t.author_id
    JOIN categories cat ON cat.id = t.category_id
    WHERE t.entry_id = ? OR t.identified_entry_id = ?
    ORDER BY t.created_at DESC
");
$stmt->execute([$entry['id'], $entry['id']]);
$linked = $stmt->fetchAll();
?>
<?php if (empty($linked)): ?>
  <p>No threads linked to this entry.</p>
<?php else: ?>
  <ul>
  <?php foreach ($linked as $t): ?>
    <li><a href="thread.php?id=<?= $t['id'] ?>"><?= h($t['title']) ?></a>
      &mdash; <?= h($t['category_name']) ?> &mdash; by <?= h($t['author_name']) ?>
      (<?= time_ago($t['created_at']) ?>)</li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>

<p><a href="catalogue.php">&larr; Back to catalogue</a></p>
<?php
require_once __DIR__ . '/includes/footer.php';
