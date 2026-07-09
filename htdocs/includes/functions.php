<?php

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function render_body(PDO $pdo, string $text): string {
    $text = h($text);

    $text = preg_replace('/(?<=\s|^)@(?!entry:|thread:|reply:)([A-Za-z0-9_]+)/',
        '<a href="profile.php?username=$1">@$1</a>', $text);

    $text = preg_replace_callback('/@entry:([^\s<>]+)/',
        function ($m) {
            return '<a href="entry.php?q=' . urlencode($m[1]) . '">@entry:' . h($m[1]) . '</a>';
        }, $text);

    $text = preg_replace('/@thread:(\d+)/',
        '<a href="thread.php?id=$1">@thread:$1</a>', $text);

    $text = preg_replace('/@reply:(\d+)/',
        '<a href="thread.php?rid=$1#reply-$1">@reply:$1</a>', $text);

    return nl2br($text, false);
}

function time_ago(string $datetime): string {
    $now = new DateTime;
    $then = new DateTime($datetime);
    $diff = $now->getTimestamp() - $then->getTimestamp();

    if ($diff < 0) return 'just now';
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return $then->format('M j, Y');
}

function flash_message(): ?string {
    if (isset($_SESSION['flash'])) {
        $msg = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $msg;
    }
    return null;
}

function flash_redirect(): ?string {
    if (isset($_SESSION['flash_redirect'])) {
        $url = $_SESSION['flash_redirect'];
        unset($_SESSION['flash_redirect']);
        return $url;
    }
    return null;
}

function is_proposal_category(PDO $pdo, int $category_id): bool {
    $stmt = $pdo->prepare('SELECT parent_id FROM categories WHERE id = ?');
    $stmt->execute([$category_id]);
    $cat = $stmt->fetch();
    return $cat && $cat['parent_id'] !== null;
}
