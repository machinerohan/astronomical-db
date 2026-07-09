<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$slug = $_GET['category'] ?? '';
$stmt = $pdo->prepare('SELECT * FROM categories WHERE slug = ?');
$stmt->execute([$slug]);
$cat = $stmt->fetch();
if (!$cat) { echo 'Category not found.'; exit; }

$is_proposal = $cat['parent_id'] !== null;
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

            if ($is_proposal && $proposal_type === 'add_entry') {
                $pst = $pdo->prepare('
                    INSERT INTO proposed_entries (thread_id, reply_id, author_id, name, catalog_id, entry_type,
                        right_ascension, declination, apparent_mag, constellation, distance_ly, discovered_by, discovery_year, notes)
                    VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $pst->execute([
                    $thread_id, $user['id'],
                    $_POST['pe_name'] ?? '', !empty($_POST['pe_catalog_id']) ? $_POST['pe_catalog_id'] : null,
                    $_POST['pe_entry_type'] ?? 'star',
                    !empty($_POST['pe_ra']) ? $_POST['pe_ra'] : null,
                    !empty($_POST['pe_dec']) ? $_POST['pe_dec'] : null,
                    !empty($_POST['pe_mag']) ? $_POST['pe_mag'] : null,
                    !empty($_POST['pe_constellation']) ? $_POST['pe_constellation'] : null,
                    !empty($_POST['pe_distance']) ? $_POST['pe_distance'] : null,
                    !empty($_POST['pe_discoverer']) ? $_POST['pe_discoverer'] : null,
                    !empty($_POST['pe_discovery_year']) ? $_POST['pe_discovery_year'] : null,
                    !empty($_POST['pe_notes']) ? $_POST['pe_notes'] : null,
                ]);
            } elseif ($is_proposal && $proposal_type === 'edit_field') {
                $pst = $pdo->prepare('
                    INSERT INTO proposed_field_edits (thread_id, reply_id, entry_id, author_id, field, old_value, new_value)
                    VALUES (?, NULL, ?, ?, ?, ?, ?)
                ');
                $pst->execute([
                    $thread_id, (int)($_POST['pfe_entry_id'] ?? 0), $user['id'],
                    $_POST['pfe_field'] ?? '', !empty($_POST['pfe_old_value']) ? $_POST['pfe_old_value'] : null,
                    !empty($_POST['pfe_new_value']) ? $_POST['pfe_new_value'] : null,
                ]);
            } elseif ($is_proposal && $proposal_type === 'remove_entry') {
                $pst = $pdo->prepare('
                    INSERT INTO proposed_removals (thread_id, reply_id, entry_id, target_field, author_id, reason)
                    VALUES (?, NULL, ?, ?, ?, ?)
                ');
                $pst->execute([
                    $thread_id, (int)($_POST['pr_entry_id'] ?? 0),
                    !empty($_POST['pr_target_field']) ? $_POST['pr_target_field'] : null, $user['id'],
                    $_POST['pr_reason'] ?? '',
                ]);
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

$all_entry_types = ['star','galaxy','nebula','emission_nebula','reflection_nebula','planetary_nebula',
    'open_cluster','globular_cluster','quasar','planet','dwarf_planet','moon','asteroid','comet',
    'cluster','supernova_remnant'];
?>
<h1>New Thread in <?= h($cat['name']) ?></h1>

<?php if ($error): ?><p style="color:red"><?= h($error) ?></p><?php endif; ?>

<form method="post">
<p><label>Title: <br><input type="text" name="title" size="60" required></label></p>
<p><label>Body: <br><textarea name="body" rows="12" cols="70" required></textarea></label></p>
<p><small>Reference syntax: @username, @entry:Sirius, @thread:42, @reply:123</small></p>

<?php if ($is_proposal): ?>
  <hr>
  <p><label>Proposal type:
    <select name="proposal_type" id="proposal_type">
      <option value="add_entry">Add new entry</option>
      <option value="edit_field">Edit existing field</option>
      <option value="remove_entry">Remove entry / revert field</option>
    </select>
  </label></p>

  <div id="add_entry_fields">
    <h3>New Entry Data</h3>
    <p><label>Name: <input type="text" name="pe_name" size="40"></label></p>
    <p><label>Catalog ID: <input type="text" name="pe_catalog_id" size="20"></label></p>
    <p><label>Entry type:
      <select name="pe_entry_type">
        <?php foreach ($all_entry_types as $et): ?>
          <option value="<?= h($et) ?>"><?= h($et) ?></option>
        <?php endforeach; ?>
      </select>
    </label></p>
    <p><label>RA (J2000): <input type="text" name="pe_ra" size="16" placeholder="06:45:08.9"></label>
       <label>Dec: <input type="text" name="pe_dec" size="16" placeholder="-16:42:58"></label></p>
    <p><label>Mag: <input type="text" name="pe_mag" size="8"></label>
       <label>Constellation: <input type="text" name="pe_constellation" size="8" placeholder="CMa"></label></p>
    <p><label>Distance (ly): <input type="text" name="pe_distance" size="12"></label></p>
    <p><label>Discoverer: <input type="text" name="pe_discoverer" size="30"></label>
       <label>Year: <input type="number" name="pe_discovery_year" size="6"></label></p>
    <p><label>Notes: <br><textarea name="pe_notes" rows="4" cols="60"></textarea></label></p>
  </div>

  <div id="edit_field_fields" style="display:none">
    <h3>Edit Field</h3>
    <p><label>Target entry:
      <select name="pfe_entry_id">
        <?php
        $entries = $pdo->query("SELECT id, name, catalog_id FROM objects WHERE status='active' ORDER BY name")->fetchAll();
        foreach ($entries as $e): ?>
          <option value="<?= $e['id'] ?>"><?= h($e['name']) ?> (<?= h($e['catalog_id'] ?? 'no cat') ?>)</option>
        <?php endforeach; ?>
      </select>
    </label></p>
    <p><label>Field: <input type="text" name="pfe_field" size="30" placeholder="apparent_mag"></label></p>
    <p><label>Old value: <input type="text" name="pfe_old_value" size="30"></label></p>
    <p><label>New value: <input type="text" name="pfe_new_value" size="30"></label></p>
  </div>

  <div id="remove_entry_fields" style="display:none">
    <h3>Remove Entry / Revert Field</h3>
    <p><label>Target entry:
      <select name="pr_entry_id">
        <?php foreach ($entries as $e): ?>
          <option value="<?= $e['id'] ?>"><?= h($e['name']) ?> (<?= h($e['catalog_id'] ?? 'no cat') ?>)</option>
        <?php endforeach; ?>
      </select>
    </label></p>
    <p><label>Specific field to revert (leave empty for full removal): <input type="text" name="pr_target_field" size="30"></label></p>
    <p><label>Reason: <br><textarea name="pr_reason" rows="3" cols="60"></textarea></label></p>
  </div>

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
