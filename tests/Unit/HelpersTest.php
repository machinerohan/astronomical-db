<?php

namespace Tests\Unit;

use Tests\TestCase;

class HelpersTest extends TestCase
{
    public function test_h_escapes_html(): void
    {
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', \h('<script>alert(1)</script>'));
    }

    public function test_h_handles_null(): void
    {
        $this->assertSame('', \h(null));
    }

    public function test_h_handles_empty_string(): void
    {
        $this->assertSame('', \h(''));
    }

    public function test_h_preserves_safe_text(): void
    {
        $this->assertSame('Hello World', \h('Hello World'));
    }

    public function test_render_body_escapes_html(): void
    {
        $result = \render_body($this->pdo, '<b>bold</b>');
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $result);
    }

    public function test_render_body_converts_username_mention(): void
    {
        $result = \render_body($this->pdo, 'Hello @admin');
        $this->assertStringContainsString('<a href="profile.php?username=admin">@admin</a>', $result);
    }

    public function test_render_body_converts_entry_mention(): void
    {
        $result = \render_body($this->pdo, 'See @entry:Sirius');
        $this->assertStringContainsString('<a href="entry.php?q=Sirius">@entry:Sirius</a>', $result);
    }

    public function test_render_body_converts_thread_mention(): void
    {
        $result = \render_body($this->pdo, 'See @thread:1');
        $this->assertStringContainsString('<a href="thread.php?id=1">@thread:1</a>', $result);
    }

    public function test_render_body_does_not_mention_email(): void
    {
        $result = \render_body($this->pdo, 'user@example.com');
        $this->assertStringNotContainsString('<a href="profile.php?username=user@example.com">', $result);
    }

    public function test_time_ago_just_now(): void
    {
        $result = \time_ago(new \DateTimeImmutable('-30 seconds')->format('Y-m-d H:i:s'));
        $this->assertSame('just now', $result);
    }

    public function test_time_ago_minutes(): void
    {
        $result = \time_ago(new \DateTimeImmutable('-5 minutes')->format('Y-m-d H:i:s'));
        $this->assertSame('5m ago', $result);
    }

    public function test_time_ago_hours(): void
    {
        $result = \time_ago(new \DateTimeImmutable('-3 hours')->format('Y-m-d H:i:s'));
        $this->assertSame('3h ago', $result);
    }

    public function test_time_ago_days(): void
    {
        $result = \time_ago(new \DateTimeImmutable('-2 days')->format('Y-m-d H:i:s'));
        $this->assertSame('2d ago', $result);
    }

    public function test_is_proposal_category_returns_false_for_root_category(): void
    {
        // Category 1 (General) has no parent_id
        $this->assertFalse(\is_proposal_category($this->pdo, 1));
    }

    public function test_is_proposal_category_returns_true_for_proposals_subcategory(): void
    {
        // Category 4 (Stars — Proposals) has a parent_id
        $this->assertTrue(\is_proposal_category($this->pdo, 4));
    }
}
