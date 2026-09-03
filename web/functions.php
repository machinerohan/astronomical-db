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

function require_expert(): array
{
    $user = require_user();
    if ($user['is_restricted'] || ($user['expertise'] === 'normal' && $user['role'] !== 'admin')) {
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

function get_verification(int $userId): ?array
{
    $statement = db()->prepare('SELECT v.note, v.created_at, u.username AS verifier FROM verifications v JOIN users u ON u.id = v.verified_by_id WHERE v.user_id = ? ORDER BY v.created_at DESC LIMIT 1');
    $statement->execute([$userId]);
    return $statement->fetch() ?: null;
}

function get_catalogue_objects(int $limit = 100): array
{
    $statement = catalogue_db()->prepare('SELECT id, name, catalog_id, object_type, constellation, distance_ly FROM objects WHERE status = "active" ORDER BY name LIMIT ?');
    $statement->execute([$limit]);
    return $statement->fetchAll();
}

function get_catalogue_object(int $id): ?array
{
    $statement = catalogue_db()->prepare('SELECT id, name, catalog_id, object_type, constellation, distance_ly FROM objects WHERE id = ? AND status = "active"');
    $statement->execute([$id]);
    return $statement->fetch() ?: null;
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
        'SELECT p.id, p.body, p.is_solution, p.linked_post_id, p.linked_object_id, p.created_at, u.id as author_id, u.username, u.expertise, u.role
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

function create_system_post(int $threadId, int $authorId, string $body, ?int $linkedObjectId = null): int
{
    $statement = db()->prepare('INSERT INTO posts (thread_id, author_id, body, linked_object_id) VALUES (?, ?, ?, ?)');
    $statement->execute([$threadId, $authorId, $body, $linkedObjectId]);
    return (int) db()->lastInsertId();
}

function create_proposal(int $threadId, int $authorId, string $type, ?string $field = null, ?string $newValue = null, ?int $targetObjectId = null): int
{
    $allowedFields = ['name', 'object_type', 'right_ascension', 'declination', 'apparent_mag', 'constellation', 'distance_ly', 'discovered_by', 'discovery_year', 'notes'];
    if ($type !== 'edit_field' || !$field || !in_array($field, $allowedFields, true) || !$targetObjectId) {
        throw new InvalidArgumentException('Invalid catalogue edit proposal.');
    }
    $statement = db()->prepare(
        'INSERT INTO proposals (thread_id, author_id, type, field, new_value, target_object_id) 
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([$threadId, $authorId, $type, $field, $newValue, $targetObjectId]);
    return (int) db()->lastInsertId();
}

function create_add_proposal(int $threadId, int $postId, int $authorId, array $object): int
{
    $conn = db();
    $conn->beginTransaction();
    try {
        $statement = $conn->prepare('INSERT INTO proposals (thread_id, post_id, author_id, type) VALUES (?, ?, ?, "add_entry")');
        $statement->execute([$threadId, $postId, $authorId]);
        $proposalId = (int) $conn->lastInsertId();
        $statement = $conn->prepare(
            'INSERT INTO proposed_objects (proposal_id, name, object_type, right_ascension, declination, apparent_mag, constellation, distance_ly, discovered_by, discovery_year, notes, image_url)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$proposalId, $object['name'], $object['object_type'], $object['right_ascension'] ?: null, $object['declination'] ?: null, $object['apparent_mag'] ?: null, $object['constellation'] ?: null, $object['distance_ly'] ?: null, $object['discovered_by'] ?: null, $object['discovery_year'] ?: null, $object['notes'] ?: null, $object['image_url'] ?: null]);
        $conn->commit();
        return $proposalId;
    } catch (Exception $exception) {
        $conn->rollBack();
        throw $exception;
    }
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
    $ownership = $conn->prepare('SELECT author_id, status FROM proposals WHERE id = ?');
    $ownership->execute([$proposalId]);
    $proposalState = $ownership->fetch();
    if (!$proposalState || $proposalState['status'] !== 'pending') {
        throw new RuntimeException('This proposal is no longer pending.');
    }
    if ((int) $proposalState['author_id'] === $approverId) {
        throw new RuntimeException('You cannot approve your own proposal. Ask another expert or admin to review it.');
    }

    $conn->beginTransaction();
    
    try {
        $statement = $conn->prepare(
            'UPDATE proposals SET status = "approved", approver_id = ?, resolved_at = NOW() WHERE id = ?'
        );
        $statement->execute([$approverId, $proposalId]);
        
        $statement = $conn->prepare('SELECT type, target_object_id, field, new_value, thread_id, author_id FROM proposals WHERE id = ?');
        $statement->execute([$proposalId]);
        $proposal = $statement->fetch();
        
        if ($proposal['type'] === 'add_entry') {
            $objectQuery = $conn->prepare('SELECT name, object_type, right_ascension, declination, apparent_mag, constellation, distance_ly, discovered_by, discovery_year, notes, image_url FROM proposed_objects WHERE proposal_id = ?');
            $objectQuery->execute([$proposalId]);
            $object = $objectQuery->fetch();
            if (!$object) {
                throw new RuntimeException('Proposal object data is missing.');
            }
            $catalogue = catalogue_db();
            $insert = $catalogue->prepare('INSERT INTO objects (name, catalog_id, object_type, right_ascension, declination, apparent_mag, constellation, distance_ly, discovered_by, discovery_year, notes) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([$object['name'], $object['object_type'], $object['right_ascension'], $object['declination'], $object['apparent_mag'], $object['constellation'], $object['distance_ly'], $object['discovered_by'], $object['discovery_year'], $object['notes']]);
            $objectId = (int) $catalogue->lastInsertId();
            if ($object['image_url']) {
                $catalogue->prepare('INSERT INTO object_images (object_id, proposal_id, uploaded_by, image_path, caption) VALUES (?, ?, ?, ?, ?)')->execute([$objectId, $proposalId, $proposal['author_id'], $object['image_url'], null]);
              }
            $conn->prepare('UPDATE threads SET identified_object_id = ? WHERE id = ? AND type = "identification"')->execute([$objectId, $proposal['thread_id']]);
        } elseif ($proposal['type'] === 'edit_field' && $proposal['target_object_id']) {
            $field = $proposal['field'];
            $value = $proposal['new_value'];
            $objectId = $proposal['target_object_id'];
            
            $oldStmt = catalogue_db()->prepare("SELECT {$field} FROM objects WHERE id = ?");
            $oldStmt->execute([$objectId]);
            $oldValue = $oldStmt->fetchColumn();
            $updateStmt = catalogue_db()->prepare("UPDATE objects SET {$field} = ? WHERE id = ?");
            $updateStmt->execute([$value, $objectId]);
            
            $statement = $conn->prepare(
                'INSERT INTO object_edits (object_id, proposal_id, field, old_value, new_value, applied_by) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([$objectId, $proposalId, $field, $oldValue, $value, $approverId]);
        }

        create_system_post((int) $proposal['thread_id'], $approverId, 'Proposal approved and applied to the catalogue.', $proposal['type'] === 'add_entry' ? $objectId : $proposal['target_object_id']);
        $conn->prepare('UPDATE users SET expertise = "expert" WHERE id = ? AND expertise = "normal" AND (SELECT COUNT(*) FROM proposals WHERE author_id = ? AND status = "approved") >= 3')->execute([$proposal['author_id'], $proposal['author_id']]);
        
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

function reject_proposal(int $proposalId, int $approverId, string $reason): void
{
    $conn = db();
    $statement = $conn->prepare(
        'UPDATE proposals SET status = "rejected", approver_id = ?, reason = ?, resolved_at = NOW() WHERE id = ?'
    );
    $statement->execute([$approverId, $reason, $proposalId]);
    $proposal = get_proposal($proposalId);
    if ($proposal) {
        create_system_post((int) $proposal['thread_id'], $approverId, 'Proposal rejected: ' . $reason);
    }
}

function get_proposal(int $proposalId): ?array
{
    $statement = db()->prepare('SELECT p.*, t.title, t.author_id AS thread_author_id FROM proposals p JOIN threads t ON t.id = p.thread_id WHERE p.id = ?');
    $statement->execute([$proposalId]);
    return $statement->fetch() ?: null;
}

function create_dispute(int $proposalId, int $authorId, string $reason): void
{
    $proposal = get_proposal($proposalId);
    if (!$proposal || $proposal['status'] !== 'approved' || (int) $proposal['author_id'] === $authorId || (int) $proposal['approver_id'] === $authorId) {
        throw new RuntimeException('This proposal cannot be disputed by this user.');
    }
    $statement = db()->prepare('INSERT INTO disputes (proposal_id, author_id, reason) VALUES (?, ?, ?)');
    $statement->execute([$proposalId, $authorId, $reason]);
}

function resolve_dispute(int $disputeId, int $resolverId, bool $approve): void
{
    $statement = db()->prepare('SELECT d.*, p.approver_id, p.author_id AS proposal_author_id, p.type, p.target_object_id, p.field, p.new_value FROM disputes d JOIN proposals p ON p.id = d.proposal_id WHERE d.id = ? AND d.status = "pending"');
    $statement->execute([$disputeId]);
    $dispute = $statement->fetch();
    if (!$dispute || (int) $dispute['author_id'] === $resolverId || (int) $dispute['approver_id'] === $resolverId) {
        throw new RuntimeException('This dispute cannot be resolved by this user.');
    }
    $conn = db();
    $conn->beginTransaction();
    try {
        $conn->prepare('UPDATE disputes SET status = ?, resolver_id = ?, resolved_at = NOW() WHERE id = ?')->execute([$approve ? 'approved' : 'rejected', $resolverId, $disputeId]);
        if ($approve) {
            $conn->prepare('UPDATE proposals SET status = "reverted", resolved_at = NOW() WHERE id = ?')->execute([$dispute['proposal_id']]);
            if ($dispute['type'] === 'edit_field' && $dispute['target_object_id']) {
                $edit = db()->prepare('SELECT old_value FROM object_edits WHERE proposal_id = ?');
                $edit->execute([$dispute['proposal_id']]);
                if ($oldValue = $edit->fetchColumn()) {
                    catalogue_db()->prepare('UPDATE objects SET ' . $dispute['field'] . ' = ? WHERE id = ?')->execute([$oldValue, $dispute['target_object_id']]);
                }
            }
            $conn->prepare('UPDATE users SET expertise = "normal" WHERE id = ? AND expertise = "expert" AND (SELECT COUNT(*) FROM disputes WHERE author_id = ? AND status = "approved") >= 3')->execute([$dispute['proposal_author_id'], $dispute['proposal_author_id']]);
        }
        $conn->commit();
    } catch (Exception $exception) {
        $conn->rollBack();
        throw $exception;
    }
}

function get_pending_disputes(int $limit = 50): array
{
    $statement = db()->prepare('SELECT d.*, p.type, p.status AS proposal_status, u.username, t.title FROM disputes d JOIN proposals p ON p.id = d.proposal_id JOIN users u ON u.id = d.author_id JOIN threads t ON t.id = p.thread_id WHERE d.status = "pending" ORDER BY d.created_at DESC LIMIT ?');
    $statement->execute([$limit]);
    return $statement->fetchAll();
}

function get_disputes_for_proposal(int $proposalId): array
{
    $statement = db()->prepare('SELECT d.*, u.username FROM disputes d JOIN users u ON u.id = d.author_id WHERE d.proposal_id = ? ORDER BY d.created_at DESC');
    $statement->execute([$proposalId]);
    return $statement->fetchAll();
}

function get_proposals_for_thread(int $threadId): array
{
    $statement = db()->prepare('SELECT p.*, u.username AS author_name FROM proposals p JOIN users u ON u.id = p.author_id WHERE p.thread_id = ? ORDER BY p.created_at');
    $statement->execute([$threadId]);
    return $statement->fetchAll();
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