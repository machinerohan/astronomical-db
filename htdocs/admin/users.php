<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$page_title = 'Manage Users';
require_once __DIR__ . '/../includes/header.php';

$user = current_user($pdo);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['demote_user'])) {
        $target_id = (int)$_POST['user_id'];
        if ($target_id === $user['id']) {
            $error = 'You cannot demote yourself.';
        } else {
            $pdo->prepare("UPDATE users SET expertise = 'normal', admin_demoted_at = COALESCE(admin_demoted_at, NOW()) WHERE id = ? AND role = 'member'")
                ->execute([$target_id]);
            $pdo->prepare("INSERT INTO admin_actions (admin_id, action, target_type, target_id) VALUES (?, 'demote_user', 'user', ?)")
                ->execute([$user['id'], $target_id]);
            $success = 'User demoted.';
        }
    }

    if (isset($_POST['verify_user'])) {
        $target_id = (int)$_POST['user_id'];
        $note = trim($_POST['verification_note'] ?? '');
        $pdo->prepare("UPDATE users SET expertise = 'verified' WHERE id = ?")
            ->execute([$target_id]);
        $pdo->prepare("INSERT INTO user_verifications (user_id, verified_by_id, note) VALUES (?, ?, ?)")
            ->execute([$target_id, $user['id'], $note ?: null]);
        $pdo->prepare("INSERT INTO admin_actions (admin_id, action, target_type, target_id) VALUES (?, 'verify_user', 'user', ?)")
            ->execute([$user['id'], $target_id]);
        $success = 'User verified.';
    }
}

$users = $pdo->query("SELECT * FROM users ORDER BY username")->fetchAll();
?>
<h1>Manage Users</h1>
<?php render_flash($error); render_flash($success, 'success'); ?>

<table class="wide">
<tr><th>ID</th><th>Username</th><th>Role</th><th>Expertise</th><th>Demoted At</th><th>Actions</th></tr>
<?php foreach ($users as $u): ?>
  <tr>
    <td><?= $u['id'] ?></td>
    <td><a href="../profile.php?username=<?= h($u['username']) ?>"><?= h($u['username']) ?></a></td>
    <td><?= h($u['role']) ?></td>
    <td><?= h($u['expertise']) ?></td>
    <td><?= $u['admin_demoted_at'] ? h($u['admin_demoted_at']) : '-' ?></td>
    <td>
      <?php if ($u['role'] === 'member' && $u['expertise'] === 'expert'): ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
          <input type="submit" name="demote_user" value="Demote" onclick="return confirm('Demote this expert?')">
        </form>
      <?php endif; ?>
      <?php if ($u['expertise'] !== 'verified'): ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
          <input type="text" name="verification_note" placeholder="Verification note" size="30">
          <input type="submit" name="verify_user" value="Verify">
        </form>
      <?php endif; ?>
    </td>
  </tr>
<?php endforeach; ?>
</table>
<?php
require_once __DIR__ . '/../includes/footer.php';
