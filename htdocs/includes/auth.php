<?php

session_start();

function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function require_login(): void {
    if (!is_logged_in()) {
        $_SESSION['flash'] = 'Please log in first.';
        $_SESSION['flash_redirect'] = $_SERVER['REQUEST_URI'];
        header('Location: login.php');
        exit;
    }
}

function require_admin(): void {
    require_login();
    if ($_SESSION['user_role'] !== 'admin') {
        $_SESSION['flash'] = 'Admin access required.';
        header('Location: index.php');
        exit;
    }
}

function current_user(PDO $pdo): ?array {
    if (!is_logged_in()) return null;
    static $user = null;
    if ($user === null) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
    }
    return $user;
}

function login(PDO $pdo, string $username, string $password): bool {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        if (password_needs_rehash($user['password'], PASSWORD_BCRYPT)) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $upd = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $upd->execute([$hash, $user['id']]);
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_username'] = $user['username'];
        return true;
    }
    return false;
}

function logout(): void {
    session_destroy();
}

function can_approve_proposals(array $user): bool {
    return in_array($user['expertise'], ['expert', 'verified']) || $user['role'] === 'admin';
}

function can_approve_removals(array $user): bool {
    return $user['expertise'] === 'verified' || $user['role'] === 'admin';
}

function recalculate_expertise(PDO $pdo, int $user_id): void {
    $stmt = $pdo->prepare('SELECT admin_demoted_at FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    if (!$user) return;
    if ($user['admin_demoted_at'] !== null) return;

    $positive = count_approved($pdo, 'proposed_entries', 'author_id', $user_id);
    $positive += count_approved($pdo, 'proposed_field_edits', 'author_id', $user_id);
    $pos2->execute([$user_id]);
    $positive += (int)$pos2->fetchColumn();

    $neg = $pdo->prepare("
        SELECT COUNT(*) FROM entry_edits
        WHERE target_author_id = ? AND action = 'removed'
    ");
    $neg->execute([$user_id]);
    $negative = (int)$neg->fetchColumn();

    $net = $positive - $negative;

    $new_expertise = 'normal';
    if ($net >= 5) {
        $new_expertise = 'expert';
    }

    $upd = $pdo->prepare('UPDATE users SET expertise = ? WHERE id = ? AND role = ?');
    $upd->execute([$new_expertise, $user_id, 'member']);
}
