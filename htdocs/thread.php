<?php
require_once __DIR__ . '/includes/init.php';

$id = (int)($_GET['id'] ?? 0);
$rid = (int)($_GET['rid'] ?? 0);

if ($rid > 0) {
    $stmt = $pdo->prepare('SELECT thread_id FROM replies WHERE id = ?');
    $stmt->execute([$rid]);
    $r = $stmt->fetch();
    if ($r) {
        session_write_close();
        header('Location: thread.php?id=' . $r['thread_id'] . '#reply-' . $rid);
        exit;
    }
}

$stmt = $pdo->prepare('SELECT t.*, u.username AS author_name, u.role AS author_role, u.expertise AS author_expertise,
    cat.slug AS category_slug, cat.name AS category_name, cat.parent_id AS category_parent_id
    FROM threads t
    JOIN users u ON u.id = t.author_id
    JOIN categories cat ON cat.id = t.category_id
    WHERE t.id = ?');
$stmt->execute([$id]);
$thread = $stmt->fetch();
if (!$thread) { header('HTTP/1.1 404 Not Found'); echo 'Thread not found.'; exit; }

$user = current_user($pdo);
$is_proposal = $thread['proposal_type'] !== null;
$is_ident = $thread['category_slug'] === 'identifications';
$error = '';
$success = '';

$entries = [];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_login();

    if ($_POST['action'] === 'reply' && $thread['status'] === 'open') {
        $body = trim($_POST['body'] ?? '');
        if (!$body) {
            $error = 'Reply body is required.';
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('INSERT INTO replies (thread_id, body, author_id) VALUES (?, ?, ?)');
                $stmt->execute([$id, $body, $user['id']]);
                $reply_id = $pdo->lastInsertId();

                if ($is_proposal) {
                    insert_proposal_data($pdo, $id, $reply_id, $user['id'], $thread['proposal_type']);
                }

                $pdo->commit();
                $success = 'Reply posted.';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Error posting reply: ' . $e->getMessage();
            }
        }
    }

    if ($_POST['action'] === 'mark_solution' && $is_ident) {
        $reply_id = (int)($_POST['reply_id'] ?? 0);
        $can_mark = $user && ($user['id'] === $thread['author_id'] || $user['role'] === 'admin');
        if ($can_mark) {
            $pdo->prepare('UPDATE replies SET is_solution = 1 WHERE id = ? AND thread_id = ?')
                ->execute([$reply_id, $id]);
            $pdo->prepare("UPDATE threads SET status = 'closed', closed_by = ?, closed_reason = 'Solution marked' WHERE id = ?")
                ->execute([$user['id'], $id]);

            $stmt = $pdo->prepare('SELECT body FROM replies WHERE id = ?');
            $stmt->execute([$reply_id]);
            $reply = $stmt->fetch();
            if ($reply && preg_match('/@entry:([^\s<>]+)/', $reply['body'], $m)) {
                $entry = find_object($pdo, $m[1], 'id');
                if ($entry) {
                    $pdo->prepare('UPDATE threads SET identified_entry_id = ? WHERE id = ?')
                        ->execute([$entry['id'], $id]);
                }
            }

            $success = 'Solution marked. Thread closed.';
            $stmt = $pdo->prepare('SELECT * FROM threads WHERE id = ?');
            $stmt->execute([$id]);
            $thread = $stmt->fetch();
        } else {
            $error = 'You do not have permission to mark a solution.';
        }
    }

    if ($_POST['action'] === 'approve_proposal' && $is_proposal && $user && can_approve_proposals($user)) {
        if ($thread['proposal_type'] === 'remove_entry' && !can_approve_removals($user)) {
            $error = 'Only verified users and admins can approve removals.';
        } else {
            $selected_post = $_POST['selected_post'] ?? 'op';
            $apply_reply_id = null;
            $apply_is_solution = 0;
            $apply_is_accepted = 0;

            if ($selected_post === 'op') {
                $apply_is_accepted = 1;
            } else {
                $apply_reply_id = (int)$selected_post;
                $apply_is_solution = 1;
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare("
                    UPDATE threads SET proposal_status = 'approved', reviewer_id = ?, reviewed_at = NOW(),
                        status = 'closed', closed_by = ?, closed_reason = 'Proposal approved'
                    WHERE id = ?
                ")->execute([$user['id'], $user['id'], $id]);

                if ($apply_is_accepted) {
                    $pdo->prepare('UPDATE threads SET is_accepted = 1 WHERE id = ?')->execute([$id]);
                }
                if ($apply_reply_id) {
                    $pdo->prepare('UPDATE replies SET is_solution = 1 WHERE id = ?')->execute([$apply_reply_id]);
                }

                if ($thread['proposal_type'] === 'add_entry') {
                    $stmt = $pdo->prepare('
                        SELECT * FROM proposed_entries WHERE thread_id = ? AND
                        ((reply_id IS NULL AND ? = 1) OR (reply_id = ?))
                        LIMIT 1
                    ');
                    $stmt->execute([$id, $apply_is_accepted, $apply_reply_id]);
                    $pe = $stmt->fetch();

                    if ($pe) {
                        $cols = implode(', ', $ENTRY_FIELD_COLUMNS);
                        $phs = implode(', ', array_fill(0, count($ENTRY_FIELD_COLUMNS), '?'));
                        $ins = $pdo->prepare("INSERT INTO objects ($cols) VALUES ($phs)");
                        $vals = array_map(fn($f) => $pe[$f], $ENTRY_FIELD_COLUMNS);
                        $ins->execute($vals);
                        $new_entry_id = $pdo->lastInsertId();

                        foreach ($ENTRY_FIELD_COLUMNS as $f) {
                            if ($pe[$f] !== null && $pe[$f] !== '') {
                                $pdo->prepare("
                                    INSERT INTO entry_edits (entry_id, thread_id, reply_id, target_author_id,
                                        action, field, old_value, new_value, reviewer_id)
                                    VALUES (?, ?, ?, NULL, 'created', ?, NULL, ?, ?)
                                ")->execute([$new_entry_id, $id, $apply_reply_id, $f, $pe[$f], $user['id']]);
                            }
                        }

                        if ($thread['identified_entry_id'] === null && $thread['parent_reply_id']) {
                            $pdo->prepare('UPDATE threads SET identified_entry_id = ? WHERE id = ?')
                                ->execute([$new_entry_id, $id]);
                        }
                    }
                } elseif ($thread['proposal_type'] === 'edit_field') {
                    $stmt = $pdo->prepare('
                        SELECT * FROM proposed_field_edits WHERE thread_id = ? AND
                        ((reply_id IS NULL AND ? = 1) OR (reply_id = ?))
                        LIMIT 1
                    ');
                    $stmt->execute([$id, $apply_is_accepted, $apply_reply_id]);
                    $pfe = $stmt->fetch();

                    if ($pfe && $pfe['entry_id']) {
                        $field = $pfe['field'];
                        $allowed = $ENTRY_FIELD_COLUMNS;

                        if (in_array($field, $allowed)) {
                            $new_val = $pfe['new_value'];
                            $stmt = $pdo->prepare("SELECT $field FROM objects WHERE id = ?");
                            $stmt->execute([$pfe['entry_id']]);
                            $cur = $stmt->fetchColumn();

                            $upd = $pdo->prepare("UPDATE objects SET $field = ? WHERE id = ?");
                            $upd->execute([$new_val, $pfe['entry_id']]);

                            $stmt = $pdo->prepare("
                                SELECT target_author_id FROM entry_edits
                                WHERE entry_id = ? AND field = ? AND action = 'created'
                                ORDER BY created_at ASC LIMIT 1
                            ");
                            $stmt->execute([$pfe['entry_id'], $field]);
                            $target_author = $stmt->fetchColumn() ?: null;

                            $pdo->prepare("
                                INSERT INTO entry_edits (entry_id, thread_id, reply_id, target_author_id,
                                    action, field, old_value, new_value, reviewer_id)
                                VALUES (?, ?, ?, ?, 'edited', ?, ?, ?, ?)
                            ")->execute([$pfe['entry_id'], $id, $apply_reply_id, $target_author, $field, $cur, $new_val, $user['id']]);
                        }
                    }
                } elseif ($thread['proposal_type'] === 'remove_entry') {
                    $stmt = $pdo->prepare('
                        SELECT * FROM proposed_removals WHERE thread_id = ? AND
                        ((reply_id IS NULL AND ? = 1) OR (reply_id = ?))
                        LIMIT 1
                    ');
                    $stmt->execute([$id, $apply_is_accepted, $apply_reply_id]);
                    $pr = $stmt->fetch();

                    if ($pr && $pr['entry_id']) {
                        if ($pr['target_field']) {
                            $field = $pr['target_field'];
                            $allowed = $ENTRY_FIELD_COLUMNS;

                            if (in_array($field, $allowed)) {
                                $stmt = $pdo->prepare("
                                    SELECT old_value FROM entry_edits
                                    WHERE entry_id = ? AND field = ? AND action = 'created'
                                    ORDER BY created_at ASC LIMIT 1
                                ");
                                $stmt->execute([$pr['entry_id'], $field]);
                                $revert_val = $stmt->fetchColumn();

                                $stmt = $pdo->prepare("SELECT $field FROM objects WHERE id = ?");
                                $stmt->execute([$pr['entry_id']]);
                                $bad_val = $stmt->fetchColumn();

                                $upd = $pdo->prepare("UPDATE objects SET $field = ? WHERE id = ?");
                                $upd->execute([$revert_val, $pr['entry_id']]);

                                $stmt = $pdo->prepare("
                                    SELECT target_author_id FROM entry_edits
                                    WHERE entry_id = ? AND field = ? AND action = 'edited'
                                    ORDER BY created_at DESC LIMIT 1
                                ");
                                $stmt->execute([$pr['entry_id'], $field]);
                                $target_author = $stmt->fetchColumn() ?: null;

                                $pdo->prepare("
                                    INSERT INTO entry_edits (entry_id, thread_id, reply_id, target_author_id,
                                        action, field, old_value, new_value, reviewer_id)
                                    VALUES (?, ?, ?, ?, 'removed', ?, ?, ?, ?)
                                ")->execute([
                                    $pr['entry_id'], $id, $apply_reply_id, $target_author,
                                    $field, $bad_val, $revert_val, $user['id'],
                                ]);

                                if ($target_author) {
                                    recalculate_expertise($pdo, $target_author);
                                }
                            }
                        } else {
                            $pdo->prepare("UPDATE objects SET status = 'deleted' WHERE id = ?")
                                ->execute([$pr['entry_id']]);

                            $stmt = $pdo->prepare("
                                SELECT target_author_id FROM entry_edits
                                WHERE entry_id = ? AND action = 'created' ORDER BY created_at ASC LIMIT 1
                            ");
                            $stmt->execute([$pr['entry_id']]);
                            $ta = $stmt->fetchColumn() ?: null;

                            $pdo->prepare("
                                INSERT INTO entry_edits (entry_id, thread_id, reply_id, target_author_id,
                                    action, field, old_value, new_value, reviewer_id)
                                VALUES (?, ?, ?, ?, 'removed', 'status', 'active', 'deleted', ?)
                            ")->execute([$pr['entry_id'], $id, $apply_reply_id, $ta, $user['id']]);

                            if ($ta) {
                                recalculate_expertise($pdo, $ta);
                            }
                        }
                    }
                }

                $pdo->commit();
                $success = 'Proposal approved.';
                $stmt = $pdo->prepare('SELECT * FROM threads WHERE id = ?');
                $stmt->execute([$id]);
                $thread = $stmt->fetch();
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Error approving proposal: ' . $e->getMessage();
            }
        }
    }

    if ($_POST['action'] === 'reject_proposal' && $is_proposal && $user && can_approve_proposals($user)) {
        $pdo->prepare("
            UPDATE threads SET proposal_status = 'rejected', reviewer_id = ?, reviewed_at = NOW(),
                status = 'closed', closed_by = ?, closed_reason = 'Proposal rejected'
            WHERE id = ?
        ")->execute([$user['id'], $user['id'], $id]);

        $success = 'Proposal rejected.';
        $stmt = $pdo->prepare('SELECT * FROM threads WHERE id = ?');
        $stmt->execute([$id]);
        $thread = $stmt->fetch();
    }
}

// Now render output
$page_title = $thread['title'];
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= h($thread['title']) ?></h1>

<p>
  <a href="category.php?slug=<?= h($thread['category_slug']) ?>"><?= h($thread['category_name']) ?></a>
  &mdash; by <a href="profile.php?username=<?= h($thread['author_name']) ?>"><?= h($thread['author_name']) ?></a>
  &mdash; <?= time_ago($thread['created_at']) ?>
  <?php if ($thread['status'] === 'closed'): ?><strong>[Closed]</strong><?php endif; ?>
</p>

<div style="border:1px solid #ccc;padding:12px;margin:12px 0;background:#f9f9f9">
  <?= render_body($pdo, $thread['body']) ?>
</div>

<?php if ($is_proposal): ?>
  <p>
    <strong>Proposal:</strong> <?= h($thread['proposal_type']) ?>
    &mdash; <strong>Status:</strong> <?= h($thread['proposal_status'] ?? 'pending') ?>
  </p>

  <?php if ($thread['proposal_status'] === 'pending' && $user && can_approve_proposals($user)): ?>
    <?php if ($thread['proposal_type'] === 'remove_entry' && !can_approve_removals($user)): ?>
      <p><em>Only verified users and admins can approve removals.</em></p>
    <?php else: ?>
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="approve_proposal">
        <label>Apply data from:
          <select name="selected_post">
            <option value="op">Original post</option>
            <?php
            $rp = $pdo->prepare("SELECT r.id, u.username FROM replies r JOIN users u ON u.id = r.author_id WHERE r.thread_id = ? ORDER BY r.created_at");
            $rp->execute([$id]);
            foreach ($rp as $r): ?>
              <option value="<?= $r['id'] ?>">Reply #<?= $r['id'] ?> by <?= h($r['username']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <input type="submit" value="Approve" style="color:green;font-weight:bold">
      </form>
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="reject_proposal">
        <input type="submit" value="Reject" style="color:red;font-weight:bold">
      </form>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

<?php if ($is_ident && $thread['status'] === 'open' && $user && ($user['id'] === $thread['author_id'] || $user['role'] === 'admin')): ?>
  <p><em>Click "Mark solution" on a reply to close this thread if the object is identified.</em></p>
<?php endif; ?>

<?php if ($thread['identified_entry_id']): ?>
  <?php
  $stmt = $pdo->prepare("SELECT id, name, catalog_id FROM objects WHERE id = ?");
  $stmt->execute([$thread['identified_entry_id']]);
  $ie = $stmt->fetch();
  ?>
  <?php if ($ie): ?>
    <p><strong>Identified as:</strong> <a href="entry.php?q=<?= h($ie['name']) ?>"><?= h($ie['name']) ?></a></p>
  <?php endif; ?>
<?php endif; ?>

<?php if ($thread['closed_reason']): ?>
  <p><strong>Closed:</strong> <?= h($thread['closed_reason']) ?></p>
<?php endif; ?>

<?php render_flash('success'); render_flash('error'); ?>

<h2>Replies</h2>

<?php
$stmt = $pdo->prepare("
    SELECT r.*, u.username AS author_name, u.role AS author_role, u.expertise AS author_expertise
    FROM replies r
    JOIN users u ON u.id = r.author_id
    WHERE r.thread_id = ?
    ORDER BY r.created_at ASC
");
$stmt->execute([$id]);
$replies = $stmt->fetchAll();
?>

<?php if (empty($replies)): ?>
  <p>No replies yet.</p>
<?php else: ?>
  <?php foreach ($replies as $reply): ?>
    <div id="reply-<?= $reply['id'] ?>" style="border:1px solid #ddd;padding:10px;margin:8px 0<?= $reply['is_solution'] ? ';background:#efe' : '' ?>">
      <p>
        <strong><a href="profile.php?username=<?= h($reply['author_name']) ?>"><?= h($reply['author_name']) ?></a></strong>
        &mdash; <?= time_ago($reply['created_at']) ?>
        <?php if ($reply['is_solution']): ?><strong style="color:green">[Solution]</strong><?php endif; ?>
      </p>
      <div><?= render_body($pdo, $reply['body']) ?></div>

      <?php if ($is_ident && $thread['status'] === 'open' && $user && ($user['id'] === $thread['author_id'] || $user['role'] === 'admin') && !$reply['is_solution']): ?>
        <form method="post" style="margin-top:4px">
          <input type="hidden" name="action" value="mark_solution">
          <input type="hidden" name="reply_id" value="<?= $reply['id'] ?>">
          <input type="submit" value="Mark as solution">
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if ($thread['status'] === 'open'): ?>
  <h3>Post a Reply</h3>
  <form method="post">
    <input type="hidden" name="action" value="reply">
    <p><textarea name="body" rows="8" cols="70" required></textarea></p>
    <p><small>Reference syntax: @username, @entry:Sirius, @thread:42, @reply:123</small></p>

    <?php if ($is_proposal): ?>
      <hr>
      <?php if ($thread['proposal_type'] === 'add_entry'): ?>
        <h3>Proposed Entry Data (optional)</h3>
        <p><label>Name: <input type="text" name="pe_name" size="40"></label></p>
        <p><label>Catalog ID: <input type="text" name="pe_catalog_id" size="20"></label></p>
        <p><label>Entry type:
          <select name="pe_entry_type">
            <?php
            foreach ($ENTRY_TYPES as $t): ?>
              <option value="<?= h($t) ?>"><?= h($t) ?></option>
            <?php endforeach; ?>
          </select>
        </label></p>
        <p><label>RA: <input type="text" name="pe_right_ascension" size="16"></label> <label>Dec: <input type="text" name="pe_declination" size="16"></label></p>
        <p><label>Mag: <input type="text" name="pe_apparent_mag" size="8"></label> <label>Spectral type: <input type="text" name="pe_spectral_type" size="10"></label> <label>Constellation: <input type="text" name="pe_constellation" size="8"></label></p>
        <p><label>Distance (ly): <input type="text" name="pe_distance_ly" size="12"></label></p>
        <p><label>Discoverer: <input type="text" name="pe_discovered_by" size="30"></label> <label>Year: <input type="number" name="pe_discovery_year" size="6"></label></p>
        <p><label>Notes: <br><textarea name="pe_notes" rows="4" cols="60"></textarea></label></p>
      <?php elseif ($thread['proposal_type'] === 'edit_field'): ?>
        <h3>Proposed Field Edit (optional)</h3>
        <p><label>Target entry:
          <select name="pfe_entry_id">
            <?php
            $entries = $pdo->query("SELECT id, name, catalog_id FROM objects WHERE status='active' ORDER BY name")->fetchAll();
            foreach ($entries as $e): ?>
              <option value="<?= $e['id'] ?>"><?= h($e['name']) ?> (<?= h($e['catalog_id'] ?? '') ?>)</option>
            <?php endforeach; ?>
          </select>
        </label></p>
        <p><label>Field: <input type="text" name="pfe_field" size="30"></label></p>
        <p><label>Old value: <input type="text" name="pfe_old_value" size="30"></label></p>
        <p><label>New value: <input type="text" name="pfe_new_value" size="30"></label></p>
      <?php elseif ($thread['proposal_type'] === 'remove_entry'): ?>
        <h3>Proposed Removal (optional)</h3>
        <p><label>Target entry:
          <select name="pr_entry_id">
            <?php foreach ($entries as $e): ?>
              <option value="<?= $e['id'] ?>"><?= h($e['name']) ?> (<?= h($e['catalog_id'] ?? '') ?>)</option>
            <?php endforeach; ?>
          </select>
        </label></p>
        <p><label>Specific field to revert: <input type="text" name="pr_target_field" size="30"></label></p>
        <p><label>Reason: <br><textarea name="pr_reason" rows="3" cols="60"></textarea></label></p>
      <?php endif; ?>
    <?php endif; ?>

    <p><input type="submit" value="Post Reply"></p>
  </form>
<?php else: ?>
  <p><em>This thread is closed. No new replies can be posted.</em></p>
<?php endif; ?>

<p><a href="category.php?slug=<?= h($thread['category_slug']) ?>">&larr; Back to <?= h($thread['category_name']) ?></a></p>
<?php
require_once __DIR__ . '/includes/footer.php';
