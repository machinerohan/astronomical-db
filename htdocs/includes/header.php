<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= isset($page_title) ? h($page_title) . ' — ' : '' ?>AstroForum</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<nav>
  <a href="index.php">AstroForum</a> &nbsp;
  <a href="catalogue.php">Catalogue</a> &nbsp;
  <?php if (is_logged_in()): ?>
    <a href="profile.php?username=<?= h($_SESSION['user_username'] ?? $_SESSION['user_id']) ?>"><?= h($_SESSION['user_username'] ?? 'User') ?></a> &nbsp;
    <?php if ($_SESSION['user_role'] === 'admin'): ?>
      <a href="admin/index.php">Admin</a> &nbsp;
    <?php endif; ?>
    <a href="logout.php">Logout</a>
  <?php else: ?>
    <a href="login.php">Login</a>
  <?php endif; ?>
</nav>

<hr>

<?php $flash = flash_message(); if ($flash): ?>
  <div class="flash"><?= h($flash) ?></div>
<?php endif; ?>
