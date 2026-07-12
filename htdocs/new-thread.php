<?php
require_once __DIR__ . '/includes/init.php';
require_login();

$slug = $_GET['category'] ?? '';
$stmt = $pdo->prepare('SELECT * FROM categories WHERE slug = ?');
$stmt->execute([$slug]);
$cat = $stmt->fetch();
if (!$cat) { http_response_code(404); echo 'Category not found.'; exit; }

$is_proposal = $cat['is_proposal'];
$user = current_user($pdo);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');

    if (!$title || !$body) {
        $error = 'Title and body are required.';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('
                INSERT INTO threads (category_id, title, body, author_id, proposal_type, proposal_status)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $proposal_type = $is_proposal ? ($_POST['proposal_type'] ?? 'add_entry') : null;
            $proposal_status = $is_proposal ? 'pending' : null;
            $stmt->execute([$cat['id'], $title, $body, $user['id'], $proposal_type, $proposal_status]);
            $thread_id = $pdo->lastInsertId();

            if ($is_proposal) {
                insert_proposal_data($pdo, $thread_id, null, $user['id'], $proposal_type);
            }

            $pdo->commit();
            session_write_close();
            header('Location: thread.php?id=' . $thread_id);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error creating thread: ' . $e->getMessage();
        }
    }
}

$page_title = 'New Thread';
require_once __DIR__ . '/includes/header.php';

?>
<h1>New Thread in <?= h($cat['name']) ?></h1>

<?php render_flash($error); ?>

<form method="post">
<p><label>Title: <br><input type="text" name="title" size="60" required></label></p>
<p><label>Body: <br><textarea name="body" rows="12" cols="70" required></textarea></label></p>
<p><small>Reference syntax: @username, @entry:Sirius, @thread:42, @reply:123</small></p>

<?php
$stmt = $pdo->prepare("SELECT entry_type FROM category_entry_types WHERE category_id = ?");
$stmt->execute([$cat['id']]);
$allowed_types = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: ENTRY_TYPES;
?>
<?php if ($is_proposal): ?>
  <hr>
  <p><label>Proposal type:
    <select name="proposal_type" id="proposal_type">
      <option value="add_entry">Add new entry</option>
      <option value="edit_field">Edit existing field</option>
      <option value="remove_entry">Remove entry / revert field</option>
    </select>
  </label></p>

  <?php
  $entries = $pdo->query("SELECT id, name, catalog_id FROM objects WHERE status='active' ORDER BY name")->fetchAll();
  $show_type = 'add_entry';
  ?>
  <?php require __DIR__ . '/includes/proposal-fields.php'; ?>

  <script>
  document.getElementById('proposal_type').addEventListener('change', function() {
    document.getElementById('add_entry_fields').style.display = this.value === 'add_entry' ? '' : 'none';
    document.getElementById('edit_field_fields').style.display = this.value === 'edit_field' ? '' : 'none';
    document.getElementById('remove_entry_fields').style.display = this.value === 'remove_entry' ? '' : 'none';
  });
  </script>
<?php endif; ?>

<p><input type="submit" value="Create Thread"></p>
</form>

<p><a href="category.php?slug=<?= h($cat['slug']) ?>">&larr; Back to <?= h($cat['name']) ?></a></p>
<?php
require_once __DIR__ . '/includes/footer.php';
