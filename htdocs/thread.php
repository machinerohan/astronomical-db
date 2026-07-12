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
    cat.slug AS category_slug, cat.name AS category_name
    FROM threads t
    JOIN users u ON u.id = t.author_id
    JOIN categories cat ON cat.id = t.category_id
    WHERE t.id = ?');
$stmt->execute([$id]);
$thread = $stmt->fetch();
if (!$thread) { http_response_code(404); echo 'Thread not found.'; exit; }

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
                        $cols = implode(', ', ENTRY_FIELD_COLUMNS);
                        $phs = implode(', ', array_fill(0, count(ENTRY_FIELD_COLUMNS), '?'));
                        $ins = $pdo->prepare("INSERT INTO objects ($cols) VALUES ($phs)");
                        $vals = array_map(fn($f) => $pe[$f], ENTRY_FIELD_COLUMNS);
                        $ins->execute($vals);
                        $new_entry_id = $pdo->lastInsertId();

                        foreach (ENTRY_FIELD_COLUMNS as $f) {
                            if ($pe[$f] !== null && $pe[$f] !== '') {
                                $pdo->prepare("
                                    INSERT INTO entry_edits (entry_id, thread_id, reply_id, target_author_id,
                                        action, field, old_value, new_value, reviewer_id)
                                    VALUES (?, ?, ?, NULL, 'created', ?, NULL, ?, ?)
                                ")->execute([$new_entry_id, $id, $apply_reply_id, $f, $pe[$f], $user['id']]);
                            }
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
                        $allowed = ENTRY_FIELD_COLUMNS;

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
                            $allowed = ENTRY_FIELD_COLUMNS;

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

                $discussion_id = null;
                if ($thread['proposal_type'] === 'add_entry' && !empty($pe)) {
                    $nstmt = $pdo->prepare("
                        SELECT c.id FROM categories c
                        JOIN category_entry_types cet ON cet.category_id = c.id
                        WHERE cet.entry_type = ? AND c.is_proposal = FALSE
                        ORDER BY c.sort_order LIMIT 1
                    ");
                    $nstmt->execute([$pe['entry_type']]);
                    $cat_id = $nstmt->fetchColumn();
                    if ($cat_id) {
                        $lines = ["A new entry has been added to the catalogue:\n"];
                        foreach (ENTRY_FIELD_COLUMNS as $col) {
                            if (!empty($pe[$col])) {
                                $lines[] = '**' . (ENTRY_FIELD_LABELS[$col] ?? $col) . ':** ' . $pe[$col];
                            }
                        }
                        $lines[] = '';
                        $lines[] = 'View entry: @entry:' . $pe['name'];
                        $pdo->prepare("INSERT INTO threads (category_id, title, body, author_id, entry_id, status) VALUES (?, ?, ?, ?, ?, 'open')")
                            ->execute([$cat_id, 'New entry: ' . $pe['name'], implode("\n", $lines), $user['id'], $new_entry_id ?? null]);
                        $discussion_id = $pdo->lastInsertId();
                    }
                } elseif ($thread['proposal_type'] === 'edit_field' && !empty($pfe) && !empty($pfe['entry_id'])) {
                    $nstmt = $pdo->prepare("SELECT name, entry_type FROM objects WHERE id = ?");
                    $nstmt->execute([$pfe['entry_id']]);
                    $obj = $nstmt->fetch();
                    if ($obj) {
                        $nstmt = $pdo->prepare("
                            SELECT c.id FROM categories c
                            JOIN category_entry_types cet ON cet.category_id = c.id
                            WHERE cet.entry_type = ? AND c.is_proposal = FALSE
                            ORDER BY c.sort_order LIMIT 1
                        ");
                        $nstmt->execute([$obj['entry_type']]);
                        $cat_id = $nstmt->fetchColumn();
                        if ($cat_id) {
                            $title = $obj['name'] . ' — field edited (' . $pfe['field'] . ')';
                            $body = '**' . $obj['name'] . '** (@entry:' . $obj['name'] . ') — field edit'
                                  . "\n\n**Field:** " . $pfe['field']
                                  . "\n**New value:** " . ($pfe['new_value'] ?? '');
                            if (!empty($pfe['old_value'])) {
                                $body .= "\n**Old value:** " . $pfe['old_value'];
                            }
                            $pdo->prepare("INSERT INTO threads (category_id, title, body, author_id, entry_id, status) VALUES (?, ?, ?, ?, ?, 'open')")
                                ->execute([$cat_id, $title, $body, $user['id'], $pfe['entry_id']]);
                            $discussion_id = $pdo->lastInsertId();
                        }
                    }
                } elseif ($thread['proposal_type'] === 'remove_entry' && !empty($pr) && !empty($pr['entry_id'])) {
                    $nstmt = $pdo->prepare("SELECT name, entry_type FROM objects WHERE id = ?");
                    $nstmt->execute([$pr['entry_id']]);
                    $obj = $nstmt->fetch();
                    if ($obj) {
                        $nstmt = $pdo->prepare("
                            SELECT c.id FROM categories c
                            JOIN category_entry_types cet ON cet.category_id = c.id
                            WHERE cet.entry_type = ? AND c.is_proposal = FALSE
                            ORDER BY c.sort_order LIMIT 1
                        ");
                        $nstmt->execute([$obj['entry_type']]);
                        $cat_id = $nstmt->fetchColumn();
                        if ($cat_id) {
                            if ($pr['target_field']) {
                                $title = $obj['name'] . ' — field reverted (' . $pr['target_field'] . ')';
                                $body = '**' . $obj['name'] . '** (@entry:' . $obj['name'] . ') — field reverted'
                                      . "\n\n**Field:** " . $pr['target_field'];
                            } else {
                                $title = $obj['name'] . ' — removed from catalogue';
                                $body = '**' . $obj['name'] . '** (@entry:' . $obj['name'] . ') has been removed from the catalogue.';
                            }
                            if (!empty($pr['reason'])) {
                                $body .= "\n**Reason:** " . $pr['reason'];
                            }
                            $pdo->prepare("INSERT INTO threads (category_id, title, body, author_id, entry_id, status) VALUES (?, ?, ?, ?, ?, 'open')")
                                ->execute([$cat_id, $title, $body, $user['id'], $pr['entry_id']]);
                            $discussion_id = $pdo->lastInsertId();
                        }
                    }
                }

                if ($discussion_id) {
                    $pdo->prepare("INSERT INTO replies (thread_id, body, author_id) VALUES (?, ?, ?)")
                        ->execute([$id, 'This proposal has been approved. See discussion at @thread:' . $discussion_id, $user['id']]);
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
        if ($thread['proposal_type'] === 'remove_entry' && !can_approve_removals($user)) {
            $error = 'Only verified users and admins can reject removals.';
        } else {
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

    if ($success && !$error) {
        $_SESSION['flash'] = $success;
        session_write_close();
        header('Location: thread.php?id=' . $id);
        exit;
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
  <?= render_body($thread['body']) ?>
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

<?php render_flash($success, 'success'); render_flash($error); ?>

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
      <div><?= render_body($reply['body']) ?></div>

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
      <?php
      $stmt = $pdo->prepare("SELECT entry_type FROM category_entry_types WHERE category_id = ?");
      $stmt->execute([$thread['category_id']]);
      $allowed_types = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: ENTRY_TYPES;
      $entries = $pdo->query("SELECT id, name, catalog_id FROM objects WHERE status='active' ORDER BY name")->fetchAll();
      $show_type = $thread['proposal_type'];
      require __DIR__ . '/includes/proposal-fields.php';
      ?>
    <?php endif; ?>

    <p><input type="submit" value="Post Reply"></p>
  </form>
<?php else: ?>
  <p><em>This thread is closed. No new replies can be posted.</em></p>
<?php endif; ?>

<p><a href="category.php?slug=<?= h($thread['category_slug']) ?>">&larr; Back to <?= h($thread['category_name']) ?></a></p>
<?php
require_once __DIR__ . '/includes/footer.php';
