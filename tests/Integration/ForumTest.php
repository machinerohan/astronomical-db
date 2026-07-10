<?php

namespace Tests\Integration;

use Tests\TestCase;

class ForumTest extends TestCase
{
    public function test_insert_proposal_data_add_entry(): void
    {
        \login($this->pdo, 'admin', 'admin');

        $this->pdo->prepare('
            INSERT INTO threads (category_id, title, author_id, body, proposal_type, proposal_status, created_at)
            VALUES (4, :title, 1, :body, \'add_entry\', \'pending\', NOW())
        ')->execute(['title' => 'Test thread', 'body' => 'Test body']);

        $threadId = (int) $this->pdo->lastInsertId();

        \insert_proposal_data($this->pdo, $threadId, null, 1, 'add_entry');

        $stmt = $this->pdo->prepare('SELECT * FROM proposed_entries WHERE thread_id = ?');
        $stmt->execute([$threadId]);
        $proposed = $stmt->fetch();

        $this->assertNotFalse($proposed, 'proposed_entries row should exist');
    }

    public function test_insert_proposal_data_with_reply(): void
    {
        \login($this->pdo, 'admin', 'admin');

        $this->pdo->prepare('
            INSERT INTO threads (category_id, title, author_id, body, proposal_type, proposal_status, created_at)
            VALUES (4, :title, 1, :body, \'add_entry\', \'pending\', NOW())
        ')->execute(['title' => 'Thread with reply', 'body' => 'Body']);

        $threadId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('
            INSERT INTO replies (thread_id, author_id, body, created_at)
            VALUES (?, 1, \'Reply body\', NOW())
        ')->execute([$threadId]);

        $replyId = (int) $this->pdo->lastInsertId();

        \insert_proposal_data($this->pdo, $threadId, $replyId, 1, 'add_entry');

        $stmt = $this->pdo->prepare('SELECT * FROM proposed_entries WHERE thread_id = ? AND reply_id = ?');
        $stmt->execute([$threadId, $replyId]);
        $proposed = $stmt->fetch();

        $this->assertNotFalse($proposed, 'proposed_entries with reply_id should exist');
    }
}
