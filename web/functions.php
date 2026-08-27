<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

session_start();

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $page = 'home'): never
{
    header('Location: index.php?page=' . urlencode($page));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        exit('Invalid form token. Please go back and try again.');
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $statement = db()->prepare('SELECT id, username, role, expertise, registration_status, is_restricted, created_at FROM users WHERE id = ?');
    $statement->execute([$_SESSION['user_id']]);
    $user = $statement->fetch();

    if (!$user || $user['registration_status'] !== 'active') {
        unset($_SESSION['user_id']);
        return null;
    }

    return $user;
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        flash('error', 'Please log in to view that page.');
        redirect('login');
    }

    return $user;
}

function require_admin(): array
{
    $user = require_user();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Admins only.');
    }

    return $user;
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

// Forum helper functions

function require_expert(): array
{
    $user = require_user();
    if ($user['expertise'] === 'normal' && $user['role'] !== 'admin') {
        http_response_code(403);
        exit('Experts only.');
    }

    return $user;
}

function get_user_by_id(int $id): ?array
{
    $statement = db()->prepare('SELECT id, username, role, expertise, is_restricted, created_at FROM users WHERE id = ?');
    $statement->execute([$id]);
    return $statement->fetch();
}

function get_categories(): array
{
    $statement = db()->query('SELECT id, name, slug, object_type, description FROM categories ORDER BY name');
    return $statement->fetchAll();
}

function get_category_by_slug(string $slug): ?array
{
    $statement = db()->prepare('SELECT id, name, slug, object_type, description FROM categories WHERE slug = ?');
    $statement->execute([$slug]);
    return $statement->fetch();
}

function get_threads_by_category(int $categoryId, int $limit = 20): array
{
    $statement = db()->prepare(
        'SELECT t.id, t.title, t.type, t.status, t.created_at, u.username, 
                COUNT(DISTINCT p.id) as post_count, MAX(p.created_at) as last_post
         FROM threads t
         JOIN users u ON t.author_id = u.id
         LEFT JOIN posts p ON t.id = p.thread_id
         WHERE t.category_id = ?
         GROUP BY t.id
         ORDER BY last_post DESC, t.created_at DESC
         LIMIT ?'
    );
    $statement->execute([$categoryId, $limit]);
    return $statement->fetchAll();
}

function get_thread_by_id(int $id): ?array
{
    $statement = db()->prepare(
        'SELECT t.*, u.username, u.expertise, c.name as category_name, c.slug as category_slug
         FROM threads t
         JOIN users u ON t.author_id = u.id
         JOIN categories c ON t.category_id = c.id
         WHERE t.id = ?'
    );
    $statement->execute([$id]);
    return $statement->fetch();
}

function get_posts_for_thread(int $threadId): array
{
    $statement = db()->prepare(
        'SELECT p.id, p.body, p.is_solution, p.created_at, u.id as author_id, u.username, u.expertise, u.role
         FROM posts p
         JOIN users u ON p.author_id = u.id
         WHERE p.thread_id = ?
         ORDER BY p.created_at ASC'
    );
    $statement->execute([$threadId]);
    return $statement->fetchAll();
}

function create_thread(int $categoryId, int $authorId, string $type, string $title, string $body): int
{
    $conn = db();
    $conn->beginTransaction();
    
    try {
        $statement = $conn->prepare(
            'INSERT INTO threads (category_id, author_id, type, title) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$categoryId, $authorId, $type, $title]);
        $threadId = (int) $conn->lastInsertId();
        
        $statement = $conn->prepare(
            'INSERT INTO posts (thread_id, author_id, body, is_opening) VALUES (?, ?, ?, true)'
        );
        $statement->execute([$threadId, $authorId, $body]);
        
        $conn->commit();
        return $threadId;
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

function create_post(int $threadId, int $authorId, string $body): int
{
    $statement = db()->prepare(
        'INSERT INTO posts (thread_id, author_id, body) VALUES (?, ?, ?)'
    );
    $statement->execute([$threadId, $authorId, $body]);
    return (int) db()->lastInsertId();
}

function create_proposal(int $threadId, int $authorId, string $type, ?string $field = null, ?string $newValue = null, ?int $targetObjectId = null): int
{
    $statement = db()->prepare(
        'INSERT INTO proposals (thread_id, author_id, type, field, new_value, target_object_id) 
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([$threadId, $authorId, $type, $field, $newValue, $targetObjectId]);
    return (int) db()->lastInsertId();
}

function get_pending_proposals(int $limit = 20): array
{
    $statement = db()->prepare(
        'SELECT p.id, p.type, p.field, p.new_value, p.created_at, u.username, t.title, c.slug as category_slug
         FROM proposals p
         JOIN users u ON p.author_id = u.id
         JOIN threads t ON p.thread_id = t.id
         JOIN categories c ON t.category_id = c.id
         WHERE p.status = "pending"
         ORDER BY p.created_at DESC
         LIMIT ?'
    );
    $statement->execute([$limit]);
    return $statement->fetchAll();
}

function approve_proposal(int $proposalId, int $approverId, ?string $reason = null): void
{
    $conn = db();
    $conn->beginTransaction();
    
    try {
        $statement = $conn->prepare(
            'UPDATE proposals SET status = "approved", approver_id = ?, resolved_at = NOW() WHERE id = ?'
        );
        $statement->execute([$approverId, $proposalId]);
        
        // Get proposal details
        $statement = $conn->prepare('SELECT type, target_object_id, field, new_value FROM proposals WHERE id = ?');
        $statement->execute([$proposalId]);
        $proposal = $statement->fetch();
        
        if ($proposal['type'] === 'add_entry') {
            // Create new object from proposal
            $statement = $conn->prepare(
                'INSERT INTO objects (name, catalog_id, object_type, right_ascension, declination, 
                                    apparent_mag, constellation, distance_ly, discovered_by, discovery_year)
                 SELECT name, NULL, object_type, right_ascension, declination, apparent_mag, 
                        constellation, distance_ly, discovered_by, discovery_year
                 FROM proposed_objects WHERE proposal_id = ?'
            );
            $statement->execute([$proposalId]);
        } elseif ($proposal['type'] === 'edit_field' && $proposal['target_object_id']) {
            // Apply edit to object
            $field = $proposal['field'];
            $value = $proposal['new_value'];
            $objectId = $proposal['target_object_id'];
            
            $updateStmt = $conn->prepare("UPDATE objects SET {$field} = ? WHERE id = ?");
            $updateStmt->execute([$value, $objectId]);
            
            // Log the edit
            $statement = $conn->prepare(
                'INSERT INTO object_edits (object_id, proposal_id, field, new_value, applied_by) VALUES (?, ?, ?, ?, ?)'
            );
            $statement->execute([$objectId, $proposalId, $field, $value, $approverId]);
        }
        
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

function reject_proposal(int $proposalId, int $approverId, string $reason): void
{
    $statement = db()->prepare(
        'UPDATE proposals SET status = "rejected", approver_id = ?, reason = ?, resolved_at = NOW() WHERE id = ?'
    );
    $statement->execute([$approverId, $reason, $proposalId]);
}

function get_user_stats(int $userId): array
{
    $stats = [
        'posts' => 0,
        'proposals' => 0,
        'approvals' => 0,
        'disputes' => 0,
    ];
    
    $statement = db()->prepare('SELECT COUNT(*) FROM posts WHERE author_id = ?');
    $statement->execute([$userId]);
    $stats['posts'] = (int) $statement->fetchColumn();
    
    $statement = db()->prepare('SELECT COUNT(*) FROM proposals WHERE author_id = ?');
    $statement->execute([$userId]);
    $stats['proposals'] = (int) $statement->fetchColumn();
    
    $statement = db()->prepare('SELECT COUNT(*) FROM proposals WHERE approver_id = ? AND status = "approved"');
    $statement->execute([$userId]);
    $stats['approvals'] = (int) $statement->fetchColumn();
    
    return $stats;
}

function get_user_history(int $userId, string $type = 'all'): array
{
    $history = [];
    
    if ($type === 'all' || $type === 'posts') {
        $statement = db()->prepare(
            'SELECT "post" as type, p.id, p.body, p.created_at, t.title, t.id as thread_id
             FROM posts p
             JOIN threads t ON p.thread_id = t.id
             WHERE p.author_id = ?
             ORDER BY p.created_at DESC
             LIMIT 50'
        );
        $statement->execute([$userId]);
        $history = array_merge($history, $statement->fetchAll());
    }
    
    if ($type === 'all' || $type === 'proposals') {
        $statement = db()->prepare(
            'SELECT "proposal" as type, p.id, p.type as proposal_type, p.status, p.created_at, t.title, t.id as thread_id
             FROM proposals p
             JOIN threads t ON p.thread_id = t.id
             WHERE p.author_id = ?
             ORDER BY p.created_at DESC
             LIMIT 50'
        );
        $statement->execute([$userId]);
        $history = array_merge($history, $statement->fetchAll());
    }
    
    usort($history, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    return array_slice($history, 0, 50);
}