<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

session_start();

// Spec tuning knobs -----------------------------------------------------------
const EXPERT_PROMOTION_THRESHOLD = 3; // approvals needed before a member becomes expert (R5)
const REVERTS_BEFORE_EXPERT_LOSS = 2; // reverted proposals before losing expert (R7)

const ALLOWED_OBJECT_FIELDS = [
    'name', 'catalog_id', 'object_type', 'right_ascension', 'declination',
    'apparent_mag', 'constellation', 'distance_ly', 'discovered_by',
    'discovery_year', 'notes',
];

const IMAGE_MIME_WHITELIST = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
const IMAGE_MAX_BYTES = 4194304; // 4 MiB
const UPLOAD_DIR = __DIR__ . '/uploads';

// View helpers -----------------------------------------------------------------

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $page = 'home', array $params = []): never
{
    $query = http_build_query(array_merge(['page' => $page], $params));
    header('Location: index.php?' . $query);
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
    $sent = (string) ($_POST['csrf_token'] ?? '');
    $known = (string) ($_SESSION['csrf_token'] ?? '');

    // An absent token must not validate against an absent session secret,
    // otherwise token-free forged requests would be accepted outright.
    if ($known === '' || $sent === '' || !hash_equals($known, $sent)) {
        http_response_code(419);
        exit('Invalid form token. Please go back and try again.');
    }
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

// Auth & permission model (R1, R5, R7, R8) --------------------------------------

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $statement = db()->prepare('SELECT id, username, role, expertise, promotion_source, registration_status, is_restricted, created_at FROM users WHERE id = ?');
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

/** Restricted accounts may browse but not act (R8). */
function ensure_not_restricted(array $user): void
{
    if ($user['is_restricted']) {
        flash('error', 'Your account is restricted and cannot contribute.');
        redirect('dashboard');
    }
}

/**
 * Proposal approval requires expert standing. Admin-granted verification grants
 * exactly this same access level (spec R8); restrictions block it (R8) unless
 * the actor is an admin.
 */
function can_approve_proposals(array $user): bool
{
    if ($user['role'] === 'admin') {
        return true;
    }

    return !$user['is_restricted'] && in_array($user['expertise'], ['expert', 'verified'], true);
}

function require_proposal_approver(): array
{
    $user = require_user();
    ensure_not_restricted($user);

    if (!can_approve_proposals($user)) {
        http_response_code(403);
        exit('Only experts, verified users, or admins can resolve proposals.');
    }

    return $user;
}

function can_post(array $user): bool
{
    return !$user['is_restricted'];
}

// Catalogue reads/writes (R11, R12) ----------------------------------------------

function catalogue_objects(?string $type = null, ?string $search = null): array
{
    $sql = 'SELECT o.*, (SELECT COUNT(*) FROM object_images i WHERE i.object_id = o.id) AS image_count
            FROM objects o WHERE o.status = "active"';
    $params = [];

    if ($type !== null && $type !== '') {
        $sql .= ' AND o.object_type = ?';
        $params[] = $type;
    }
    if ($search !== null && $search !== '') {
        $sql .= ' AND (o.name LIKE ? OR o.catalog_id LIKE ?)';
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }

    $sql .= ' ORDER BY o.name';
    $statement = catalog_db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function catalogue_object(int $id): ?array
{
    $statement = catalog_db()->prepare('SELECT * FROM objects WHERE id = ?');
    $statement->execute([$id]);
    $object = $statement->fetch();

    return $object ?: null;
}

function object_provenance(int $objectId): ?array
{
    $statement = catalog_db()->prepare(
        'SELECT oe.*, p.thread_id AS proposal_thread_id
         FROM object_edits oe JOIN astronomical_db.proposals p ON p.id = oe.proposal_id
         WHERE oe.object_id = ?
         ORDER BY oe.id ASC LIMIT 1'
    );
    $statement->execute([$objectId]);

    return $statement->fetch() ?: null;
}

// Threads & posts (R2, R3, R9) ----------------------------------------------------

function category_by_slug(string $slug): ?array
{
    $statement = db()->prepare('SELECT * FROM categories WHERE slug = ?');
    $statement->execute([$slug]);
    $category = $statement->fetch();

    return $category ?: null;
}

function categories(): array
{
    return db()->query('SELECT * FROM categories ORDER BY (object_type IS NULL) DESC, name')->fetchAll();
}

function threads_for_category(int $categoryId): array
{
    $statement = db()->prepare(
        'SELECT t.*, u.username AS author_name,
                (SELECT COUNT(*) FROM posts p WHERE p.thread_id = t.id) AS reply_count,
                co.name AS identified_object_name,
                pr.type AS proposal_kind, pr.status AS proposal_status
         FROM threads t
         JOIN users u ON u.id = t.author_id
         LEFT JOIN catalogue_db.objects co ON co.id = t.identified_object_id
         LEFT JOIN proposals pr ON pr.id = t.linked_proposal_id
         WHERE t.category_id = ?
         ORDER BY t.created_at DESC'
    );
    $statement->execute([$categoryId]);

    return $statement->fetchAll();
}

function thread_by_id(int $id): ?array
{
    $statement = db()->prepare(
        'SELECT t.*, c.name AS category_name, c.slug AS category_slug,
                c.object_type AS category_object_type, u.username AS author_name
         FROM threads t
         JOIN categories c ON c.id = t.category_id
         JOIN users u ON u.id = t.author_id
         WHERE t.id = ?'
    );
    $statement->execute([$id]);
    $thread = $statement->fetch();

    return $thread ?: null;
}

function posts_for_thread(int $threadId): array
{
    $statement = db()->prepare(
        'SELECT p.*, u.username AS author_name, u.expertise AS author_expertise,
                lp.created_at AS linked_post_time,
                lp.body AS linked_post_body,
                lu.username AS linked_post_author,
                co.name AS linked_object_name
         FROM posts p
         JOIN users u ON u.id = p.author_id
         LEFT JOIN posts lp ON lp.id = p.linked_post_id
         LEFT JOIN users lu ON lu.id = lp.author_id
         LEFT JOIN catalogue_db.objects co ON co.id = p.linked_object_id
         WHERE p.thread_id = ?
         ORDER BY p.created_at ASC, p.id ASC'
    );
    $statement->execute([$threadId]);

    return $statement->fetchAll();
}

/**
 * Create a thread with its opening post. Identification requests may only ever
 * live in the opening message of a type="identification" thread (R3): later
 * messages cannot request identification because they never carry that role.
 */
function create_thread(array $user, int $categoryId, string $type, string $title, string $body): int
{
    db()->beginTransaction();
    try {
        $insertThread = db()->prepare('INSERT INTO threads (category_id, author_id, type, title) VALUES (?, ?, ?, ?)');
        $insertThread->execute([$categoryId, $user['id'], $type, $title]);
        $threadId = (int) db()->lastInsertId();

        $insertPost = db()->prepare('INSERT INTO posts (thread_id, author_id, body, is_opening) VALUES (?, ?, ?, TRUE)');
        $insertPost->execute([$threadId, $user['id'], $body]);
        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }

    return $threadId;
}

/**
 * Author confirms an identification (R3): record the object and flag the
 * opening message as the accepted answer.
 */
function confirm_identification(int $threadId, int $objectId, array $actor): void
{
    $thread = thread_by_id($threadId);
    if (!$thread || $thread['type'] !== 'identification') {
        flash('error', 'Only identification threads can confirm an object.');
        return;
    }
    if ((int) $thread['author_id'] !== (int) $actor['id']) {
        flash('error', 'Only the thread author can confirm an identification.');
        return;
    }
    if (!catalogue_object($objectId) || catalogue_object($objectId)['status'] !== 'active') {
        flash('error', 'That catalogue entry does not exist.');
        return;
    }

    db()->beginTransaction();
    try {
        $update = db()->prepare('UPDATE threads SET identified_object_id = ? WHERE id = ?');
        $update->execute([$objectId, $threadId]);

        $solution = db()->prepare('UPDATE posts SET is_solution = FALSE WHERE thread_id = ?');
        $solution->execute([$threadId]);

        $mark = db()->prepare('UPDATE posts SET is_solution = TRUE WHERE thread_id = ? AND is_opening = TRUE');
        $mark->execute([$threadId]);

        $reply = db()->prepare(
            'INSERT INTO posts (thread_id, author_id, body, linked_object_id)
             VALUES (?, ?, ?, ?)'
        );
        $reply->execute([
            $threadId,
            $actor['id'],
            'Identified as “' . catalogue_object($objectId)['name'] . '”. See the catalogue entry for details.',
            $objectId,
        ]);
        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }

    flash('success', 'Identification confirmed.');
}

// Proposals (R4, R5, R9) ------------------------------------------------------------

function create_proposal(
    array $user,
    int $threadId,
    ?int $postId,
    string $type,
    array $payload
): int {
    db()->beginTransaction();
    try {
        $target = isset($payload['target_object_id']) ? (int) $payload['target_object_id'] : null;
        $insert = db()->prepare(
            'INSERT INTO proposals (thread_id, post_id, author_id, type, target_object_id, field, new_value)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $threadId,
            $postId,
            $user['id'],
            $type,
            $type === 'edit_field' ? $target : null,
            $payload['field'] ?? null,
            $payload['new_value'] ?? null,
        ]);
        $proposalId = (int) db()->lastInsertId();

        // Backfill so thread listings can show proposal status (R4/R9).
        db()->prepare('UPDATE threads SET linked_proposal_id = ? WHERE id = ?')
            ->execute([$proposalId, $threadId]);

        if ($type === 'add_entry') {
            $row = $payload['object'];
            $insertObject = db()->prepare(
                'INSERT INTO proposed_objects
                    (proposal_id, name, object_type, right_ascension, declination, apparent_mag,
                     constellation, distance_ly, discovered_by, discovery_year, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insertObject->execute([
                $proposalId, $row['name'], $row['object_type'], $row['right_ascension'] ?? null,
                $row['declination'] ?? null, $row['apparent_mag'] ?? null, $row['constellation'] ?? null,
                $row['distance_ly'] ?? null, $row['discovered_by'] ?? null, $row['discovery_year'] ?? null,
                $row['notes'] ?? null,
            ]);
        }

        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }

    return $proposalId;
}

/**
 * Expert approval (R5). Applies the change to catalogue_db (R12), leaves an
 * audit trail in object_edits for revert-to-last-good (R6), attaches already-
 * uploaded pictures to the created object (R11), posts the linking reply
 * message (R9), and counts the approval toward expert promotion (R5).
 */
function approve_proposal(int $proposalId, array $approver): void
{
    $pdo = db();
    $statement = $pdo->prepare('SELECT * FROM proposals WHERE id = ?');
    $statement->execute([$proposalId]);
    $proposal = $statement->fetch();

    if (!$proposal || $proposal['status'] !== 'pending') {
        flash('error', 'That proposal was already resolved.');
        return;
    }

    $catalogue = catalog_db();

    if ($proposal['type'] === 'add_entry') {
        $pendingStatement = $pdo->prepare('SELECT * FROM proposed_objects WHERE proposal_id = ?');
        $pendingStatement->execute([$proposalId]);
        $pending = $pendingStatement->fetch();
        if (!$pending) {
            flash('error', 'Proposal payload is missing; cannot approve.');
            return;
        }

        $catalogue->beginTransaction();
        $pdo->beginTransaction();
        try {
            $create = $catalogue->prepare(
                'INSERT INTO objects (name, catalog_id, object_type, right_ascension, declination,
                                      apparent_mag, constellation, distance_ly, discovered_by, discovery_year, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $create->execute([
                $pending['name'], $pending['name'] /* provisional catalog id */, $pending['object_type'],
                $pending['right_ascension'], $pending['declination'], $pending['apparent_mag'],
                $pending['constellation'], $pending['distance_ly'], $pending['discovered_by'],
                $pending['discovery_year'], $pending['notes'],
            ]);
            $objectId = (int) $catalogue->lastInsertId();
            $catalogue->commit();
            $pdo->commit();
        } catch (Throwable $exception) {
            $catalogue->rollBack();
            $pdo->rollBack();
            throw $exception;
        }

        finalize_approved_proposal($proposal, $objectId, (string) $objectId, '__created__', null, (string) $objectId, $approver);
        return;
    }

    // edit_field: capture last good value, then mutate the catalogue row.
    $field = $proposal['field'] ?? '';
    if (!in_array($field, ALLOWED_OBJECT_FIELDS, true) || empty($proposal['target_object_id'])) {
        flash('error', 'Invalid edit proposal.');
        return;
    }

    $oldStatement = $catalogue->prepare("SELECT {$field} FROM objects WHERE id = ?");
    $oldStatement->execute([(int) $proposal['target_object_id']]);
    $oldValue = $oldStatement->fetchColumn();
    if ($oldValue === false) {
        flash('error', 'Catalogue entry no longer exists.');
        return;
    }

    $update = $catalogue->prepare("UPDATE objects SET {$field} = ? WHERE id = ?");
    $update->execute([$proposal['new_value'], (int) $proposal['target_object_id']]);

    finalize_approved_proposal(
        $proposal,
        (int) $proposal['target_object_id'],
        (string) ($proposal['target_object_id']),
        $field,
        (string) $oldValue,
        (string) $proposal['new_value'],
        $approver
    );
}

/** Shared tail of approve_proposal: DB bookkeeping, images, reply, promotion. */
function finalize_approved_proposal(
    array $proposal,
    int $objectId,
    string $referenceValue,
    string $auditField,
    ?string $oldValue,
    string $newValue,
    array $approver
): void {
    $pdo = db();
    $proposalId = (int) $proposal['id'];

    $pdo->beginTransaction();
    try {
        $resolve = $pdo->prepare(
            'UPDATE proposals SET status = "approved", approver_id = ?, resolved_at = NOW(), target_object_id = COALESCE(target_object_id, ?) WHERE id = ?'
        );
        $resolve->execute([$approver['id'], $objectId, $proposalId]);

        $audit = $pdo->prepare(
            'INSERT INTO object_edits (object_id, proposal_id, field, old_value, new_value, applied_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $audit->execute([$objectId, $proposalId, $auditField, $oldValue, $newValue, $approver['id']]);

        // Attach images uploaded with the proposal to their permanent object (R11).
        $attach = catalog_db()->prepare('UPDATE object_images SET object_id = ? WHERE proposal_id = ? AND object_id IS NULL');
        $attach->execute([$objectId, $proposalId]);

        $replyBody = $auditField === '__created__'
            ? 'Approved: the object was added to the catalogue. See the entry below.'
            : "Approved: field “{$auditField}” updated to “{$newValue}”.";
        $reply = $pdo->prepare(
            'INSERT INTO posts (thread_id, author_id, body, linked_post_id, linked_object_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $reply->execute([$proposal['thread_id'], $approver['id'], $replyBody, $proposal['post_id'], $objectId]);

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    maybe_promote_to_expert((int) $proposal['author_id']);
    flash('success', 'Proposal approved and applied to the catalogue.');
}

/** Rejection with mandatory reason, delivered as a reply message (R9). */
function reject_proposal(int $proposalId, array $approver, string $reason): void
{
    $reason = trim($reason);
    if ($reason === '') {
        flash('error', 'A rejection reason is required.');
        return;
    }

    $statement = db()->prepare('SELECT * FROM proposals WHERE id = ?');
    $statement->execute([$proposalId]);
    $proposal = $statement->fetch();

    if (!$proposal || $proposal['status'] !== 'pending') {
        flash('error', 'That proposal was already resolved.');
        return;
    }

    db()->beginTransaction();
    try {
        $resolve = db()->prepare(
            'UPDATE proposals SET status = "rejected", approver_id = ?, reason = ?, resolved_at = NOW() WHERE id = ?'
        );
        $resolve->execute([$approver['id'], $reason, $proposalId]);

        $reply = db()->prepare(
            'INSERT INTO posts (thread_id, author_id, body, linked_post_id)
             VALUES (?, ?, ?, ?)'
        );
        $reply->execute([
            $proposal['thread_id'],
            $approver['id'],
            'Proposal rejected: ' . $reason,
            $proposal['post_id'],
        ]);

        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }

    flash('success', 'Proposal rejected with reason posted.');
}

// Disputes & reverts (R6, R7) ---------------------------------------------------------

function file_dispute(array $user, int $proposalId, string $reason): void
{
    $reason = trim($reason);
    if ($reason === '') {
        flash('error', 'A dispute requires a reason.');
        return;
    }

    $statement = db()->prepare('SELECT * FROM proposals WHERE id = ?');
    $statement->execute([$proposalId]);
    $proposal = $statement->fetch();

    if (!$proposal || $proposal['status'] !== 'approved') {
        flash('error', 'Disputes can only target approved proposals.');
        return;
    }

    $insert = db()->prepare('INSERT INTO disputes (proposal_id, author_id, reason) VALUES (?, ?, ?)');
    $insert->execute([$proposalId, $user['id'], $reason]);

    $reply = db()->prepare(
        'INSERT INTO posts (thread_id, author_id, body, linked_post_id)
         VALUES (?, ?, ?, ?)'
    );
    $reply->execute([
        $proposal['thread_id'],
        $user['id'],
        'Filed a dispute against this approved proposal: ' . $reason,
        $proposal['post_id'],
    ]);
}

/**
 * Dispute resolution (R6): anyone except the original approver can resolve.
 * Approval reverts the catalogue to the last good state recorded in
 * object_edits. NOTE: reverts count against the PROPOSAL AUTHOR'S record,
 * mirroring how promotions count approvals (R5) — see REVERTS_BEFORE_EXPERT_LOSS.
 */
function resolve_dispute(int $disputeId, array $resolver, bool $uphold): void
{
    $statement = db()->prepare('SELECT d.*, p.thread_id, p.post_id, p.approver_id AS original_approver_id, p.author_id AS proposer_id FROM disputes d JOIN proposals p ON p.id = d.proposal_id WHERE d.id = ?');
    $statement->execute([$disputeId]);
    $dispute = $statement->fetch();

    if (!$dispute || $dispute['status'] !== 'pending') {
        flash('error', 'That dispute was already resolved.');
        return;
    }
    if ((int) $dispute['original_approver_id'] === (int) $resolver['id']) {
        flash('error', 'The original approver cannot resolve this dispute.'); // R6
        return;
    }

    $catalogue = catalog_db();

    if ($uphold) {
        // Revert every non-reverted applied edit back to its last good value (R6).
        $edits = db()->prepare('SELECT * FROM object_edits WHERE proposal_id = ? ORDER BY id DESC');
        $edits->execute([(int) $dispute['proposal_id']]);
        $auditRows = $edits->fetchAll();

        foreach ($auditRows as $row) {
            if ((bool) $row['reverted']) {
                continue;
            }

            if ($row['field'] === '__created__') {
                // The proposal created the object; last good state is absence.
                $delete = $catalogue->prepare('DELETE FROM objects WHERE id = ?');
                $delete->execute([(int) $row['object_id']]);
            } elseif (in_array($row['field'], ALLOWED_OBJECT_FIELDS, true)) {
                $restore = $catalogue->prepare("UPDATE objects SET {$row['field']} = ? WHERE id = ?");
                $restore->execute([$row['old_value'], (int) $row['object_id']]);
            }
        }
    }

    db()->beginTransaction();
    try {
        $update = db()->prepare(
            'UPDATE disputes SET status = ?, resolver_id = ?, resolved_at = NOW() WHERE id = ?'
        );
        $update->execute([
            $uphold ? 'approved' : 'rejected',
            $resolver['id'],
            $disputeId,
        ]);

        if ($uphold) {
            $markEdits = db()->prepare('UPDATE object_edits SET reverted = TRUE WHERE proposal_id = ?');
            $markEdits->execute([(int) $dispute['proposal_id']]);

            $revertProposal = db()->prepare(
                'UPDATE proposals SET status = "reverted", resolved_at = NOW() WHERE id = ?'
            );
            $revertProposal->execute([(int) $dispute['proposal_id']]);
        }

        $replyBody = $uphold
            ? 'Dispute upheld — the catalogue was reverted to the last good state.'
            : 'Dispute rejected — the approved proposal stands.';
        $reply = db()->prepare(
            'INSERT INTO posts (thread_id, author_id, body, linked_post_id)
             VALUES (?, ?, ?, ?)'
        );
        $reply->execute([(int) $dispute['thread_id'], $resolver['id'], $replyBody, $dispute['post_id']]);

        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }

    if ($uphold) {
        demote_on_excess_reverts((int) $dispute['proposer_id']); // R7
    }

    flash('success', $uphold ? 'Dispute upheld; changes reverted.' : 'Dispute rejected.');
}

/**
 * R5: members promoted automatically once enough of their proposals are
 * approved. Skips admins (already superusers) and admin-verified users
 * (their standing belongs to admins alone, R8).
 */
function maybe_promote_to_expert(int $userId): void
{
    $statement = db()->prepare('SELECT id, username, role, expertise, promotion_source FROM users WHERE id = ?');
    $statement->execute([$userId]);
    $candidate = $statement->fetch();

    if (!$candidate
        || $candidate['role'] === 'admin'
        || $candidate['expertise'] !== 'normal'
        || $candidate['promotion_source'] === 'admin') {
        return;
    }

    $count = db()->prepare('SELECT COUNT(DISTINCT id) FROM proposals WHERE author_id = ? AND status = "approved"');
    $count->execute([$userId]);

    if ((int) $count->fetchColumn() >= EXPERT_PROMOTION_THRESHOLD) {
        $promote = db()->prepare('UPDATE users SET expertise = "expert", promotion_source = "auto" WHERE id = ?');
        $promote->execute([$userId]);
        flash('success', $candidate['username'] . ' is now an expert (' . EXPERT_PROMOTION_THRESHOLD . '+ approved proposals).'); // R5
    }
}

/** R7: enough upheld reverts strip auto-granted expert standing. */
function demote_on_excess_reverts(int $proposerId): void
{
    $statement = db()->prepare('SELECT id, username, expertise, promotion_source, role FROM users WHERE id = ?');
    $statement->execute([$proposerId]);
    $user = $statement->fetch();

    if (!$user
        || $user['role'] === 'admin'
        || $user['expertise'] !== 'expert'
        || $user['promotion_source'] !== 'auto') {
        return;
    }

    $count = db()->prepare('SELECT COUNT(DISTINCT id) FROM proposals WHERE author_id = ? AND status = "reverted"');
    $count->execute([$proposerId]);

    if ((int) $count->fetchColumn() >= REVERTS_BEFORE_EXPERT_LOSS) {
        $demote = db()->prepare('UPDATE users SET expertise = "normal", promotion_source = NULL WHERE id = ?');
        $demote->execute([$proposerId]);
    }
}

// Verification & restriction (R8) -------------------------------------------------------

function verify_user(array $admin, int $userId, string $note): void
{
    $note = trim($note);
    if ($note === '') {
        flash('error', 'Admins must record why a user is being verified.');
        return;
    }

    $statement = db()->prepare('SELECT * FROM users WHERE id = ? AND role <> "admin"');
    $statement->execute([$userId]);
    $target = $statement->fetch();

    if (!$target) {
        flash('error', 'User not found.');
        return;
    }

    db()->beginTransaction();
    try {
        $record = db()->prepare('INSERT INTO verifications (user_id, verified_by_id, kind, note) VALUES (?, ?, "verify", ?)');
        $record->execute([$userId, $admin['id'], $note]);

        $update = db()->prepare('UPDATE users SET expertise = "verified", promotion_source = "admin", is_restricted = FALSE, restricted_by = NULL WHERE id = ?');
        $update->execute([$userId]);

        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }

    flash('success', $target['username'] . ' is now verified (same rights as experts).');
}

function remove_verification(array $admin, int $userId, string $note): void
{
    $statement = db()->prepare('SELECT * FROM users WHERE id = ? AND expertise = "verified"');
    $statement->execute([$userId]);
    $target = $statement->fetch();

    if (!$target) {
        flash('error', 'User is not admin-verified.');
        return;
    }

    db()->beginTransaction();
    try {
        $record = db()->prepare('INSERT INTO verifications (user_id, verified_by_id, kind, note) VALUES (?, ?, "unverify", ?)');
        $record->execute([$userId, $admin['id'], trim($note) ?: null]);

        $update = db()->prepare('UPDATE users SET expertise = "normal", promotion_source = NULL WHERE id = ?');
        $update->execute([$userId]);

        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }

    flash('success', $target['username'] . ' is no longer verified.');
}

function restrict_user(array $admin, int $userId, string $note): void
{
    $note = trim($note);
    if ($note === '') {
        flash('error', 'Admins must record why a user is being restricted.');
        return;
    }

    $statement = db()->prepare('SELECT * FROM users WHERE id = ? AND role <> "admin" AND is_restricted = FALSE');
    $statement->execute([$userId]);
    $target = $statement->fetch();

    if (!$target) {
        flash('error', 'User not found or already restricted.');
        return;
    }

    db()->beginTransaction();
    try {
        $kind = in_array($target['expertise'], ['verified', 'expert'], true) ? 'restrict' : 'restrict';
        $record = db()->prepare('INSERT INTO verifications (user_id, verified_by_id, kind, note) VALUES (?, ?, ?, ?)');
        $record->execute([$userId, $admin['id'], $kind, $note]);

        $update = db()->prepare('UPDATE users SET is_restricted = TRUE, restricted_by = ? WHERE id = ?');
        $update->execute([$admin['id'], $userId]);

        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }

    flash('success', $target['username'] . ' is restricted.');
}

function unrestrict_user(array $admin, int $userId, string $note): void
{
    $statement = db()->prepare('SELECT * FROM users WHERE id = ? AND is_restricted = TRUE');
    $statement->execute([$userId]);
    $target = $statement->fetch();

    if (!$target) {
        flash('error', 'User is not restricted.');
        return;
    }

    db()->beginTransaction();
    try {
        $record = db()->prepare('INSERT INTO verifications (user_id, verified_by_id, kind, note) VALUES (?, ?, "unrestrict", ?)');
        $record->execute([$userId, $admin['id'], trim($note) ?: null]);

        $update = db()->prepare('UPDATE users SET is_restricted = FALSE, restricted_by = NULL WHERE id = ?');
        $update->execute([$userId]);

        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }

    flash('success', $target['username'] . ' is unrestricted.');
}

function verification_log(int $userId): array
{
    $statement = db()->prepare(
        'SELECT v.*, u.username AS verifier_name
         FROM verifications v JOIN users u ON u.id = v.verified_by_id
         WHERE v.user_id = ? ORDER BY v.created_at DESC'
    );
    $statement->execute([$userId]);

    return $statement->fetchAll();
}

// Images (R11) ------------------------------------------------------------------------------

/** Validate and persist an uploaded picture; returns [path, mime] or null. */
function store_image_upload(array $file, ?string $caption): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        flash('error', 'Image upload failed. Please retry.');
        return null;
    }
    if (($file['size'] ?? 0) > IMAGE_MAX_BYTES) {
        flash('error', 'Images must be 4 MiB or smaller.');
        return null;
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    if (!in_array($mime, IMAGE_MIME_WHITELIST, true)) {
        flash('error', 'Only JPEG, PNG, WebP, or GIF images are accepted.');
        return null;
    }

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0775, true) && !is_dir(UPLOAD_DIR)) {
        flash('error', 'Upload directory is missing.');
        return null;
    }

    $extension = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => 'gif',
    };
    $filename = 'img_' . bin2hex(random_bytes(12)) . '.' . $extension;

    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $filename)) {
        flash('error', 'Could not save the uploaded image.');
        return null;
    }

    return ['path' => 'uploads/' . $filename, 'mime' => $mime, 'caption' => $caption !== '' ? $caption : null];
}

function attach_image_to_proposal(int $proposalId, array $stored, int $uploaderId): void
{
    $statement = catalog_db()->prepare(
        'INSERT INTO object_images (proposal_id, path, mime, caption, uploaded_by) VALUES (?, ?, ?, ?, ?)'
    );
    $statement->execute([$proposalId, $stored['path'], $stored['mime'], $stored['caption'], $uploaderId]);
}

function images_for_object(int $objectId): array
{
    $statement = catalog_db()->prepare('SELECT * FROM object_images WHERE object_id = ? ORDER BY id DESC');
    $statement->execute([$objectId]);

    return $statement->fetchAll();
}

function images_for_proposal(int $proposalId): array
{
    $statement = catalog_db()->prepare('SELECT * FROM object_images WHERE proposal_id = ? ORDER BY id DESC');
    $statement->execute([$proposalId]);

    return $statement->fetchAll();
}

// Profile history (R10) ------------------------------------------------------------------------

function profile_history(int $userId): array
{
    $postsStmt = db()->prepare(
        'SELECT p.body, p.created_at, t.title AS thread_title, t.id AS thread_id, t.type AS thread_type
         FROM posts p JOIN threads t ON t.id = p.thread_id
         WHERE p.author_id = ? ORDER BY p.created_at DESC LIMIT 25'
    );
    $postsStmt->execute([$userId]);
    $posts = $postsStmt->fetchAll();

    $proposalsStmt = db()->prepare(
        'SELECT pr.*, t.title AS thread_title,
                (SELECT COUNT(*) FROM disputes d WHERE d.proposal_id = pr.id) AS dispute_count
         FROM proposals pr JOIN threads t ON t.id = pr.thread_id
         WHERE pr.author_id = ? ORDER BY pr.created_at DESC LIMIT 25'
    );
    $proposalsStmt->execute([$userId]);
    $proposals = $proposalsStmt->fetchAll();

    $disputesFiledStmt = db()->prepare(
        'SELECT d.*, t.title AS thread_title
         FROM disputes d JOIN proposals pr ON pr.id = d.proposal_id JOIN threads t ON t.id = pr.thread_id
         WHERE d.author_id = ? ORDER BY d.created_at DESC LIMIT 15'
    );
    $disputesFiledStmt->execute([$userId]);
    $disputesFiled = $disputesFiledStmt->fetchAll();

    $resolvedCountStmt = db()->prepare('SELECT COUNT(*) FROM disputes WHERE resolver_id = ?');
    $resolvedCountStmt->execute([$userId]);

    return [
        'posts' => $posts,
        'proposals' => $proposals,
        'disputes_filed' => $disputesFiled,
        'disputes_resolved' => (int) $resolvedCountStmt->fetchColumn(),
    ];
}

function approval_counts(int $userId): array
{
    $approved = db()->prepare('SELECT COUNT(DISTINCT id) FROM proposals WHERE author_id = ? AND status = "approved"');
    $approved->execute([$userId]);
    $approvedCount = (int) $approved->fetchColumn();
    $reverted = db()->prepare('SELECT COUNT(DISTINCT id) FROM proposals WHERE author_id = ? AND status = "reverted"');
    $reverted->execute([$userId]);
    $reviewed = db()->prepare('SELECT COUNT(*) FROM proposals WHERE approver_id = ?');
    $reviewed->execute([$userId]);

    return [
        'approved' => $approvedCount,
        'reverted' => (int) $reverted->fetchColumn(),
        'reviewed' => (int) $reviewed->fetchColumn(),
        'next_expert_in' => max(0, EXPERT_PROMOTION_THRESHOLD - $approvedCount),
    ];
}
