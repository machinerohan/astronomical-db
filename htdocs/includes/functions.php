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

function render_flash(string $var): void {
    if (isset($GLOBALS[$var]) && $GLOBALS[$var]) {
        $cls = $var === 'error' ? 'fmsg-error' : 'fmsg-success';
        echo '<div class="fmsg ' . $cls . '">' . h($GLOBALS[$var]) . '</div>';
    }
}

function find_object(PDO $pdo, string $q, string $select = '*'): ?array {
    $stmt = $pdo->prepare("SELECT $select FROM objects WHERE name = ? OR catalog_id = ? LIMIT 1");
    $stmt->execute([$q, $q]);
    $row = $stmt->fetch();
    if (!$row) {
        $like = "%$q%";
        $stmt = $pdo->prepare("SELECT $select FROM objects WHERE name LIKE ? OR catalog_id LIKE ? LIMIT 1");
        $stmt->execute([$like, $like]);
        $row = $stmt->fetch();
    }
    return $row ?: null;
}

function approved_proposal_sql(): string {
    return "(t.is_accepted = 1 OR t.proposal_status = 'approved')";
}

function count_approved(PDO $pdo, string $table, string $column, int $author_id): int {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM $table
        JOIN threads t ON t.id = $table.thread_id
        WHERE $table.$column = ? AND " . approved_proposal_sql()
    );
    $stmt->execute([$author_id]);
    return (int)$stmt->fetchColumn();
}

function render_pagination(int $current, int $total, string $tmpl): void {
    if ($total <= 1) return;
    echo '<p>';
    for ($p = 1; $p <= $total; $p++) {
        if ($p === $current) {
            echo '<strong>' . $p . '</strong> ';
        } else {
            $url = str_replace('{p}', $p, $tmpl);
            echo '<a href="' . h($url) . '">' . $p . '</a> ';
        }
    }
    echo '</p>';
}

function insert_proposal_data(PDO $pdo, int $thread_id, ?int $reply_id, int $author_id, string $proposal_type): void {
    if ($proposal_type === 'add_entry') {
        $cols = implode(', ', $GLOBALS['ENTRY_FIELD_COLUMNS']);
        $phs = implode(', ', array_fill(0, count($GLOBALS['ENTRY_FIELD_COLUMNS']), '?'));
        $pst = $pdo->prepare("
            INSERT INTO proposed_entries (thread_id, reply_id, author_id, $cols)
            VALUES (?, ?, ?, $phs)
        ");
        $vals = [$thread_id, $reply_id, $author_id];
        foreach ($GLOBALS['ENTRY_FIELD_COLUMNS'] as $f) {
            $pk = 'pe_' . $f;
            if ($f === 'entry_type') {
                $vals[] = $_POST[$pk] ?? 'star';
            } elseif ($f === 'name') {
                $vals[] = $_POST[$pk] ?? '';
            } else {
                $vals[] = !empty($_POST[$pk]) ? $_POST[$pk] : null;
            }
        }
        $pst->execute($vals);
    } elseif ($proposal_type === 'edit_field') {
        $pst = $pdo->prepare('
            INSERT INTO proposed_field_edits (thread_id, reply_id, entry_id, author_id, field, old_value, new_value)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $pst->execute([
            $thread_id, $reply_id, (int)($_POST['pfe_entry_id'] ?? 0), $author_id,
            $_POST['pfe_field'] ?? '', !empty($_POST['pfe_old_value']) ? $_POST['pfe_old_value'] : null,
            !empty($_POST['pfe_new_value']) ? $_POST['pfe_new_value'] : null,
        ]);
    } elseif ($proposal_type === 'remove_entry') {
        $pst = $pdo->prepare('
            INSERT INTO proposed_removals (thread_id, reply_id, entry_id, target_field, author_id, reason)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $pst->execute([
            $thread_id, $reply_id, (int)($_POST['pr_entry_id'] ?? 0),
            !empty($_POST['pr_target_field']) ? $_POST['pr_target_field'] : null, $author_id,
            $_POST['pr_reason'] ?? '',
        ]);
    }
}

$ENTRY_FIELD_COLUMNS = ['name','catalog_id','entry_type','right_ascension','declination',
    'apparent_mag','spectral_type','constellation','distance_ly','discovered_by','discovery_year','notes'];

$ENTRY_FIELD_LABELS = [
    'name' => 'Name',
    'catalog_id' => 'Catalog ID',
    'entry_type' => 'Type',
    'right_ascension' => 'Right Ascension (J2000)',
    'declination' => 'Declination (J2000)',
    'apparent_mag' => 'Apparent Magnitude',
    'spectral_type' => 'Spectral Type',
    'constellation' => 'Constellation',
    'distance_ly' => 'Distance (ly)',
    'discovered_by' => 'Discovered by',
    'discovery_year' => 'Discovery Year',
    'notes' => 'Notes',
];

$ENTRY_TYPES = ['star','galaxy','nebula','emission_nebula','reflection_nebula','planetary_nebula',
    'open_cluster','globular_cluster','quasar','planet','dwarf_planet','moon','asteroid','comet',
    'cluster','supernova_remnant'];
