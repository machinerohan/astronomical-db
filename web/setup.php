<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$adminExists = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
$error = null;
$created = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$adminExists) {
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_]{3,64}$/', $username) || strlen($password) < 8) {
        $error = 'Use a valid username and a password of at least 8 characters.';
    } else {
        try {
            $statement = db()->prepare("INSERT INTO users (username, password_hash, role, registration_status) VALUES (?, ?, 'admin', 'active')");
            $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            $created = true;
            $adminExists = true;
        } catch (PDOException $exception) {
            $error = $exception->getCode() === '23000' ? 'That username is already taken.' : 'Could not create the administrator.';
        }
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>AstroForum setup</title><link rel="stylesheet" href="style.css"></head><body><main class="setup-shell"><div class="form-panel"><a class="brand" href="index.php"><span class="brand-mark">✦</span><span>Astro<span>Forum</span></span></a><p class="eyebrow">One-time setup</p><h1>Open the registration desk.</h1><?php if ($created): ?><div class="notice success">Administrator created. You can now review registrations.</div><a class="primary-button button-link" href="index.php?page=login">Continue to login <span>→</span></a><?php elseif ($adminExists): ?><div class="notice success">An administrator already exists. Setup is complete.</div><a class="primary-button button-link" href="index.php?page=login">Go to login <span>→</span></a><?php else: ?><?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?><p>Create the first active administrator for Feature 0. This page is only needed once.</p><form method="post" class="stack"><?= csrf_field() ?><label>Admin username<input name="username" required minlength="3" maxlength="64"></label><label>Admin password<input type="password" name="password" required minlength="8"><small>At least 8 characters.</small></label><button class="primary-button" type="submit">Create administrator <span>→</span></button></form><?php endif; ?></div></main></body></html>