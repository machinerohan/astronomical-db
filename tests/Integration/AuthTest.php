<?php

namespace Tests\Integration;

use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_login_with_valid_credentials(): void
    {
        $result = \login($this->pdo, 'admin', 'admin');
        $this->assertTrue($result);
    }

    public function test_login_with_invalid_password(): void
    {
        $result = \login($this->pdo, 'admin', 'wrongpassword');
        $this->assertFalse($result);
    }

    public function test_login_with_nonexistent_user(): void
    {
        $result = \login($this->pdo, 'nonexistent', 'password');
        $this->assertFalse($result);
    }

    public function test_current_user_returns_null_for_unauthenticated(): void
    {
        if (isset($_SESSION['user_id'])) {
            unset($_SESSION['user_id']);
        }
        $this->assertNull(\current_user($this->pdo));
    }

    public function test_current_user_returns_user_after_login(): void
    {
        \login($this->pdo, 'admin', 'admin');
        $user = \current_user($this->pdo);
        $this->assertNotNull($user);
        $this->assertSame('admin', $user['username']);
    }

    public function test_is_logged_in_returns_false_initially(): void
    {
        if (isset($_SESSION['user_id'])) {
            unset($_SESSION['user_id']);
        }
        $this->assertFalse(\is_logged_in());
    }

    public function test_is_logged_in_returns_true_after_login(): void
    {
        \login($this->pdo, 'admin', 'admin');
        $this->assertTrue(\is_logged_in());
    }

    public function test_can_approve_proposals_admin(): void
    {
        $user = ['role' => 'admin', 'expertise' => 'novice'];
        $this->assertTrue(\can_approve_proposals($user));
    }

    public function test_can_approve_proposals_regular_user(): void
    {
        $user = ['role' => 'member', 'expertise' => 'novice'];
        $this->assertFalse(\can_approve_proposals($user));
    }

    public function test_login_with_alice(): void
    {
        $result = \login($this->pdo, 'alice', 'password');
        $this->assertTrue($result);
    }
}
